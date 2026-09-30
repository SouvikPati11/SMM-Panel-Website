<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;
use App\Providers\ProviderOrderStatus;

/** Everything cron does with providers: order status sync, retries, refills, catalog and price sync. */
final class ProviderSyncService
{
    /** Pull statuses for active orders (oldest-synced first), grouped per provider. */
    public static function syncOrderStatuses(int $limit = 100): array
    {
        $db = Database::instance();
        $orders = $db->fetchAll(
            "SELECT id, provider_id, provider_order_id FROM orders
             WHERE status IN ('pending','processing','in_progress') AND submit_state = 'submitted' AND provider_order_id IS NOT NULL
             ORDER BY last_synced_at IS NOT NULL, last_synced_at ASC LIMIT " . max(1, min(1000, $limit))
        );
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => 0];
        $byProvider = [];
        foreach ($orders as $o) {
            $byProvider[(int) $o['provider_id']][(string) $o['provider_order_id']][] = (int) $o['id'];
        }
        foreach ($byProvider as $providerId => $map) {
            $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$providerId]);
            if (!$provider || $provider['status'] !== 'active') {
                continue;
            }
            try {
                $results = ProviderFactory::make($provider)->multiStatus(array_keys($map));
            } catch (ProviderException $e) {
                $stats['errors'] += count($map);
                self::markProviderError($providerId, $e->getMessage());
                continue;
            }
            foreach ($results as $pid => $res) {
                foreach ($map[(string) $pid] ?? [] as $orderId) {
                    $stats['checked']++;
                    if ($res instanceof ProviderOrderStatus) {
                        $before = $db->fetchColumn('SELECT status FROM orders WHERE id = ?', [$orderId]);
                        OrderService::applyStatus($orderId, $res);
                        if ($db->fetchColumn('SELECT status FROM orders WHERE id = ?', [$orderId]) !== $before) {
                            $stats['updated']++;
                        }
                    } else {
                        $stats['errors']++;
                        $db->update('orders', ['last_synced_at' => now(), 'last_error' => mb_substr($res->getMessage(), 0, 480)], ['id' => $orderId]);
                    }
                }
            }
        }
        return $stats;
    }

    /** Retry orders whose submission provably never reached the provider. */
    public static function submitQueued(int $limit = 50): array
    {
        $ids = Database::instance()->fetchAll(
            "SELECT id FROM orders WHERE submit_state = 'queued' AND status = 'pending' AND provider_id IS NOT NULL ORDER BY id ASC LIMIT " . max(1, $limit)
        );
        $out = ['submitted' => 0, 'queued' => 0, 'failed' => 0, 'unknown' => 0, 'not_claimed' => 0];
        foreach ($ids as $row) {
            $r = OrderService::submit((int) $row['id']);
            $out[$r] = ($out[$r] ?? 0) + 1;
        }
        return $out;
    }

    /** Orders stuck in "submitting" (process died mid-request) become "unknown" for admin review. */
    public static function recoverStuckSubmissions(int $minutes = 10): int
    {
        $db = Database::instance();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $rows = $db->fetchAll("SELECT id FROM orders WHERE submit_state = 'submitting' AND updated_at < ?", [$cutoff]);
        foreach ($rows as $r) {
            $db->update('orders', ['submit_state' => 'unknown', 'needs_attention' => 1, 'last_error' => 'Submission interrupted; verify on provider panel', 'updated_at' => now()], ['id' => $r['id']]);
            OrderService::log((int) $r['id'], 'submit_unknown', null, null, 'Submission interrupted (stuck in submitting)');
        }
        return count($rows);
    }

    public static function syncRefills(int $limit = 100): array
    {
        $db = Database::instance();
        $rows = $db->fetchAll(
            "SELECT r.*, o.provider_id FROM refills r JOIN orders o ON o.id = r.order_id
             WHERE r.status = 'processing' AND r.provider_refill_id IS NOT NULL ORDER BY r.updated_at ASC LIMIT " . max(1, $limit)
        );
        $out = ['checked' => 0, 'updated' => 0];
        foreach ($rows as $r) {
            $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$r['provider_id']]);
            if (!$provider || $provider['status'] !== 'active') {
                continue;
            }
            $out['checked']++;
            try {
                $status = ProviderFactory::make($provider)->refillStatus((string) $r['provider_refill_id']);
            } catch (ProviderException $e) {
                $db->update('refills', ['updated_at' => now(), 'message' => mb_substr($e->getMessage(), 0, 480)], ['id' => $r['id']]);
                continue;
            }
            if ($status !== $r['status'] && $status !== 'pending') {
                $db->update('refills', ['status' => $status, 'updated_at' => now()], ['id' => $r['id']]);
                $out['updated']++;
                if ($status === 'completed') {
                    NotificationService::notify((int) $r['user_id'], 'order', 'Refill completed for order #' . $r['order_id'], null, '/orders/' . $r['order_id']);
                }
            } else {
                $db->update('refills', ['updated_at' => now()], ['id' => $r['id']]);
            }
        }
        return $out;
    }

    /** Test the connection and refresh the provider balance. */
    public static function checkProvider(int $providerId): array
    {
        $db = Database::instance();
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$providerId]);
        if (!$provider) {
            throw new ValidationException('Provider not found.');
        }
        try {
            $bal = ProviderFactory::make($provider)->balance();
            $db->update('providers', [
                'balance' => $bal['balance'], 'connection_status' => 'ok', 'last_error' => null,
                'last_checked_at' => now(), 'updated_at' => now(),
            ], ['id' => $providerId]);
            return ['ok' => true, 'balance' => $bal['balance'], 'currency' => $bal['currency']];
        } catch (ProviderException $e) {
            self::markProviderError($providerId, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            // Never a blank 500 for the admin: record it, keep details in the log.
            Logger::error("Provider #{$providerId} balance check crashed: " . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()], 'provider');
            self::markProviderError($providerId, 'Internal error while checking the provider (' . get_class($e) . '). Details are in storage/logs/provider-*.log.');
            return ['ok' => false, 'error' => 'Internal error while checking the provider. Details are in storage/logs/provider-*.log.'];
        }
    }

    /** Admin-facing state: disabled | syncing | never | error | ok. */
    public static function displayStatus(array $p): string
    {
        if (($p['status'] ?? 'active') === 'disabled') {
            return 'disabled';
        }
        if (!empty($p['syncing_since']) && strtotime($p['syncing_since'] . ' UTC') > time() - 15 * 60) {
            return 'syncing';
        }
        if (($p['connection_status'] ?? 'unknown') === 'unknown' || (empty($p['last_checked_at']) && empty($p['last_synced_at']))) {
            return 'never';
        }
        return $p['connection_status'] === 'error' ? 'error' : 'ok';
    }

    /** Provider-native "Subscriptions" (username/posts/expiry, billed per post) cannot be charged by this panel. */
    public static function isProviderSubscriptionType(string $providerType): bool
    {
        return in_array(strtolower(preg_replace('/[\s_\-]+/', '', $providerType)), ['subscriptions', 'subscription'], true);
    }

    /** Download the provider catalog into provider_services. */
    public static function fetchCatalog(int $providerId): array
    {
        $db = Database::instance();
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$providerId]);
        if (!$provider) {
            throw new ValidationException('Provider not found.');
        }
        $db->update('providers', ['syncing_since' => now()], ['id' => $providerId]);
        try {
            $services = ProviderFactory::make($provider)->services();
        } catch (ProviderException $e) {
            $db->update('providers', ['syncing_since' => null], ['id' => $providerId]);
            self::markProviderError($providerId, $e->getMessage());
            throw new ValidationException('Could not fetch services: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $db->update('providers', ['syncing_since' => null], ['id' => $providerId]);
            throw $e;
        }
        $seen = [];
        $db->transaction(static function (Database $db) use ($services, $providerId, &$seen): void {
            foreach ($services as $s) {
                $seen[] = $s['service'];
                $db->query(
                    'INSERT INTO provider_services (provider_id, provider_service_id, name, category, type, rate, min_quantity, max_quantity, refill, cancel, dripfeed, description, is_available, synced_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)
                     ON DUPLICATE KEY UPDATE name=VALUES(name), category=VALUES(category), type=VALUES(type), rate=VALUES(rate), min_quantity=VALUES(min_quantity),
                       max_quantity=VALUES(max_quantity), refill=VALUES(refill), cancel=VALUES(cancel), dripfeed=VALUES(dripfeed), description=VALUES(description), is_available=1, synced_at=VALUES(synced_at)',
                    [$providerId, $s['service'], $s['name'], $s['category'], $s['type'], $s['rate'], min($s['min'], 4294967295), min($s['max'], 4294967295), (int) $s['refill'], (int) $s['cancel'], (int) $s['dripfeed'], $s['description'], now()]
                );
            }
            // Mark services that disappeared from the provider as unavailable.
            if ($seen) {
                $placeholders = implode(',', array_fill(0, count($seen), '?'));
                $db->query("UPDATE provider_services SET is_available = 0 WHERE provider_id = ? AND provider_service_id NOT IN ({$placeholders})", array_merge([$providerId], $seen));
            }
        });
        $db->update('providers', ['last_synced_at' => now(), 'syncing_since' => null, 'connection_status' => 'ok', 'last_error' => null, 'updated_at' => now()], ['id' => $providerId]);
        return ['count' => count($services), 'provider_subscriptions' => count(array_filter($services, static fn ($s) => self::isProviderSubscriptionType($s['type'])))];
    }

    /**
     * Update linked services with auto_sync enabled: provider rate × exchange rate × (1 + markup%),
     * min/max, and disable services the provider no longer offers.
     */
    public static function syncServicePrices(?int $providerId = null): array
    {
        $db = Database::instance();
        $sql = "SELECT s.*, ps.rate AS p_rate, ps.min_quantity AS p_min, ps.max_quantity AS p_max, ps.is_available AS p_avail, p.exchange_rate
                FROM services s
                JOIN providers p ON p.id = s.provider_id
                LEFT JOIN provider_services ps ON ps.provider_id = s.provider_id AND ps.provider_service_id = s.provider_service_id
                WHERE s.auto_sync = 1" . ($providerId ? ' AND s.provider_id = ' . (int) $providerId : '');
        $updated = 0;
        $disabled = 0;
        foreach ($db->fetchAll($sql) as $s) {
            if ($s['p_rate'] === null || (int) $s['p_avail'] === 0) {
                if ($s['status'] === 'active') {
                    $db->update('services', ['status' => 'disabled', 'updated_at' => now()], ['id' => $s['id']]);
                    $disabled++;
                }
                continue;
            }
            $cost = Money::mul((string) $s['p_rate'], (string) $s['exchange_rate']);
            $markup = (string) ($s['markup_percent'] ?? '0');
            $rate = Money::add($cost, Money::percent($cost, $markup));
            $changes = [];
            if (Money::cmp($rate, (string) $s['rate']) !== 0) {
                $changes['rate'] = $rate;
            }
            if (Money::cmp($cost, (string) ($s['provider_rate'] ?? '0')) !== 0) {
                $changes['provider_rate'] = $cost;
            }
            if ((int) $s['p_min'] !== (int) $s['min_quantity']) {
                $changes['min_quantity'] = (int) $s['p_min'];
            }
            if ((int) $s['p_max'] !== (int) $s['max_quantity']) {
                $changes['max_quantity'] = (int) $s['p_max'];
            }
            if ($changes) {
                $changes['updated_at'] = now();
                $db->update('services', $changes, ['id' => $s['id']]);
                $updated++;
            }
        }
        return ['updated' => $updated, 'disabled' => $disabled];
    }

    /**
     * Import selected provider services into the local catalog.
     * @param list<string> $providerServiceIds
     */
    public static function importServices(int $providerId, array $providerServiceIds, ?int $categoryId, string $markupPercent, bool $autoSync, bool $createCategories, ?int &$skipped = null): int
    {
        $skipped = 0;
        $db = Database::instance();
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$providerId]);
        if (!$provider) {
            throw new ValidationException('Provider not found.');
        }
        if (!Money::isNumeric($markupPercent)) {
            throw new ValidationException('Markup must be a number.');
        }
        $count = 0;
        foreach (array_slice(array_unique($providerServiceIds), 0, 2000) as $psid) {
            $ps = $db->fetch('SELECT * FROM provider_services WHERE provider_id = ? AND provider_service_id = ?', [$providerId, (string) $psid]);
            if (!$ps) {
                continue;
            }
            if (self::isProviderSubscriptionType((string) $ps['type'])) {
                $skipped++;
                continue;
            }
            $cat = $categoryId;
            if ($createCategories || !$cat) {
                $name = mb_substr($ps['category'] ?: 'Imported', 0, 150);
                $cat = (int) $db->fetchColumn('SELECT id FROM categories WHERE name = ?', [$name]);
                if (!$cat) {
                    $slug = slugify($name);
                    if ($db->fetchColumn('SELECT id FROM categories WHERE slug = ?', [$slug])) {
                        $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                    }
                    $cat = $db->insert('categories', ['name' => $name, 'slug' => $slug, 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $cost = Money::mul((string) $ps['rate'], (string) $provider['exchange_rate']);
            $rate = Money::add($cost, Money::percent($cost, $markupPercent));
            $type = self::mapType((string) $ps['type']);
            $exists = $db->fetchColumn('SELECT id FROM services WHERE provider_id = ? AND provider_service_id = ?', [$providerId, (string) $psid]);
            $data = [
                'category_id' => $cat,
                'name' => $ps['name'],
                'description' => $ps['description'] ?: null,
                'type' => $type,
                'provider_id' => $providerId,
                'provider_service_id' => (string) $psid,
                'provider_rate' => $cost,
                'rate' => $rate,
                'markup_percent' => $markupPercent,
                'auto_sync' => $autoSync ? 1 : 0,
                'min_quantity' => (int) $ps['min_quantity'],
                'max_quantity' => (int) $ps['max_quantity'],
                'dripfeed' => (int) $ps['dripfeed'],
                'refill' => (int) $ps['refill'],
                'cancel' => (int) $ps['cancel'],
                'updated_at' => now(),
            ];
            if ($exists) {
                $db->update('services', $data, ['id' => (int) $exists]);
            } else {
                $data += ['status' => 'active', 'created_at' => now(), 'sort_order' => 0, 'link_label' => 'Link'];
                $db->insert('services', $data);
            }
            $count++;
        }
        AuditService::log('services.import', 'provider', $providerId, ['count' => $count, 'markup' => $markupPercent]);
        return $count;
    }

    public static function mapType(string $providerType): string
    {
        $t = strtolower(preg_replace('/[\s_\-]+/', '', $providerType));
        return match ($t) {
            'package' => 'package',
            'customcomments' => 'custom_comments',
            'customcommentspackage' => 'custom_comments_package',
            'mentionscustomlist', 'mentionsuserfollowers', 'mentions' => 'mentions_custom_list',
            'commentlikes' => 'comment_likes',
            'poll' => 'poll',
            'seo', 'keywords' => 'keywords',
            default => 'default',
        };
    }

    private static function markProviderError(int $providerId, string $error): void
    {
        Database::instance()->update('providers', ['connection_status' => 'error', 'last_error' => mb_substr($error, 0, 480), 'last_checked_at' => now()], ['id' => $providerId]);
        Logger::warning("Provider #{$providerId}: {$error}", [], 'provider');
    }

    /** Encrypt and store a provider API key (admin panel). */
    public static function encryptKey(string $key): string
    {
        return Crypto::encrypt($key);
    }
}
