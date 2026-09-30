<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;

/**
 * Subscriptions, two modes on one table and one order engine:
 *
 * 1. "scheduled" — the same order (link + quantity) repeated every N hours for
 *    M cycles. Every cycle is a normal order placed with OrderService::place().
 *
 * 2. "posts" — the standard SMM "Subscriptions" contract: username, number of
 *    new posts, old posts, quantity min/max per post, delay and expiry. The
 *    provider watches the account and delivers to each new post, so the panel
 *    submits ONE order (API v2 add with username/min/max/posts/old_posts/delay/
 *    expiry) and follows it through status sync. Billing is prepaid and safe:
 *      reserve  = max × (new posts + old posts) × rate / 1000, charged up front;
 *      final    = what was actually used (provider charge converted with the
 *                 service's sell/cost ratio, or processed posts × max when the
 *                 provider reports no charge), never more than the reserve;
 *      refund   = reserve − final, credited exactly once (unique ledger ref)
 *                 when the provider reports completion/expiry/cancellation,
 *                 when a cancel is accepted, or at expiry for manual services.
 *    A definitive submission failure refunds the whole reserve (order engine).
 *
 * Safety:
 *   - a cycle is claimed with an atomic UPDATE (locked_until), so concurrent cron
 *     runs never process the same subscription at once;
 *   - each cycle's order uses the idempotency key "sub{id}c{cycle}" (unique per
 *     user), so even an expired claim can never create a second order for a cycle;
 *   - advancing the cycle counter is guarded by the expected previous value.
 *
 * Lifecycle: active → (paused ⇄ active) → completed | cancelled;
 *            active → suspended after repeated failures (resume → active).
 */
final class SubscriptionService
{
    public const STATUSES = ['active', 'paused', 'completed', 'cancelled', 'suspended', 'expired', 'failed'];
    public const FINAL = ['completed', 'cancelled', 'expired', 'failed'];
    /** Delay before each new post is delivered (minutes), the usual SMM panel choices. */
    public const DELAYS = [0 => 'No delay', 5 => '5 minutes', 10 => '10 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 90 => '1.5 hours', 120 => '2 hours', 180 => '3 hours', 240 => '4 hours', 300 => '5 hours', 360 => '6 hours', 480 => '8 hours', 600 => '10 hours'];
    /** How long after expiry/cancel the panel waits for the provider's final status before reconciling itself. */
    public const PROVIDER_GRACE_HOURS = 48;
    public const INTERVALS = [1 => 'Every hour', 3 => 'Every 3 hours', 6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Every day', 48 => 'Every 2 days', 72 => 'Every 3 days', 168 => 'Every week'];
    /** Retry delays (minutes) after a failed cycle; after the last one the subscription is suspended. */
    public const RETRY_MINUTES = [15, 60, 180, 360, 720];
    private const CLAIM_MINUTES = 10;

    public static function enabled(): bool
    {
        return setting('subscriptions_enabled', '1') === '1';
    }

    public static function maxCycles(): int
    {
        return max(2, min(1000, (int) setting('subscription_max_cycles', '100')));
    }

    /**
     * Subscription rules for one service: whether it can be ordered as a
     * subscription at all, whether it can ONLY be ordered that way (service type
     * "Subscriptions"), the allowed intervals and the delivery-count range.
     * @return array{allowed:bool, only:bool, intervals:array<int,string>, min:int, max:int}
     */
    public static function options(array $service): array
    {
        $only = ($service['type'] ?? '') === 'subscription';
        $allowed = self::enabled() && ($only || (int) ($service['subscription_enabled'] ?? 0) === 1);
        $intervals = self::INTERVALS;
        $csv = trim((string) ($service['subscription_intervals'] ?? ''));
        if ($csv !== '') {
            $pick = array_flip(array_map('intval', explode(',', $csv)));
            $intervals = array_intersect_key(self::INTERVALS, $pick) ?: self::INTERVALS;
        }
        $max = self::maxCycles();
        if (!empty($service['subscription_max_cycles'])) {
            $max = max(2, min($max, (int) $service['subscription_max_cycles']));
        }
        $mode = $only && ($service['subscription_mode'] ?? 'scheduled') === 'posts' ? 'posts' : 'scheduled';
        $floor = $mode === 'posts' ? 1 : 2;
        $min = !empty($service['subscription_min_cycles']) ? max($floor, min($max, (int) $service['subscription_min_cycles'])) : $floor;
        $delays = self::DELAYS;
        $dcsv = trim((string) ($service['subscription_delays'] ?? ''));
        if ($dcsv !== '') {
            $delays = array_intersect_key(self::DELAYS, array_flip(array_map('intval', explode(',', $dcsv)))) ?: self::DELAYS;
        }
        return [
            'allowed' => $allowed, 'only' => $only, 'mode' => $mode, 'intervals' => $intervals, 'min' => $min, 'max' => $max,
            // posts mode: min/max above are the number of new posts
            'delays' => $delays,
            'old_posts_max' => max(0, (int) ($service['subscription_old_posts_max'] ?? 0)),
            'expiry_days' => !empty($service['subscription_max_expiry_days']) ? (int) $service['subscription_max_expiry_days'] : null,
            'qty_min' => (int) ($service['min_quantity'] ?? 1),
            'qty_max' => (int) ($service['max_quantity'] ?? 1),
        ];
    }

    // ------------------------------------------------------------------ post-based subscriptions

    /**
     * Validate post-subscription input against the service rules. Every limit is
     * enforced here, so a modified form or POST cannot bypass them.
     * @return array{username:string, posts:int, old_posts:int, min:int, max:int, delay:int, expires_at:?string, expiry:?string}
     */
    public static function validatePosts(array $service, array $in): array
    {
        $o = self::options($service);
        if (!self::enabled()) {
            throw new ValidationException('Subscriptions are currently disabled.');
        }
        if (!$o['allowed'] || $o['mode'] !== 'posts') {
            throw new ValidationException('This service does not take post-based subscriptions.');
        }
        $username = trim((string) ($in['username'] ?? ''));
        $username = preg_replace('/^@/', '', $username) ?? '';
        if ($username === '') {
            throw new ValidationException('Enter the username (or profile link) to watch for new posts.');
        }
        if (mb_strlen($username) > 200 || preg_match('/[\s\x00-\x1F\x7F<>"\']/u', $username)) {
            throw new ValidationException('The username must not contain spaces or special characters.');
        }
        $int = static function (string $key, string $label) use ($in): int {
            $v = trim((string) ($in[$key] ?? ''));
            if (!preg_match('/^\d{1,9}$/', $v)) {
                throw new ValidationException("{$label} must be a whole number.");
            }
            return (int) $v;
        };
        $posts = $int('posts', 'New posts');
        if ($posts < $o['min'] || $posts > $o['max']) {
            throw new ValidationException("New posts must be between {$o['min']} and {$o['max']}.");
        }
        $old = trim((string) ($in['old_posts'] ?? '')) === '' ? 0 : $int('old_posts', 'Old posts');
        if ($old > $o['old_posts_max']) {
            throw new ValidationException($o['old_posts_max'] === 0 ? 'This service does not deliver to old posts.' : "Old posts must be between 0 and {$o['old_posts_max']}.");
        }
        $min = $int('min', 'Minimum quantity');
        $max = $int('max', 'Maximum quantity');
        if ($min < $o['qty_min'] || $max > $o['qty_max'] || $min > $max) {
            throw new ValidationException(sprintf('Quantity per post: minimum and maximum must be between %s and %s, and the minimum cannot exceed the maximum.', number_format($o['qty_min']), number_format($o['qty_max'])));
        }
        $delayRaw = trim((string) ($in['delay'] ?? '0'));
        if (!preg_match('/^\d{1,4}$/', $delayRaw) || !isset($o['delays'][(int) $delayRaw])) {
            throw new ValidationException('Choose one of the offered delays.');
        }
        $expiresAt = null;
        $expiry = trim((string) ($in['expiry'] ?? ''));
        $today = new \DateTimeImmutable('today', display_tz());
        if ($expiry !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, display_tz()) ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $expiry, display_tz());
            if (!$d) {
                throw new ValidationException('Enter the expiry as a date.');
            }
            if ($d <= $today) {
                throw new ValidationException('The expiry date must be in the future.');
            }
            if ($o['expiry_days'] !== null && $d > $today->modify('+' . $o['expiry_days'] . ' days')) {
                throw new ValidationException("The expiry can be at most {$o['expiry_days']} days from today.");
            }
        } elseif ($o['expiry_days'] !== null) {
            $d = $today->modify('+' . $o['expiry_days'] . ' days'); // default: the longest allowed period
        } else {
            $d = null;
        }
        if ($d) {
            $end = $d->setTime(23, 59, 59);
            $expiresAt = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $expiry = $d->format('d/m/Y'); // format sent to providers (API v2 convention)
        }
        return ['username' => $username, 'posts' => $posts, 'old_posts' => $old, 'min' => $min, 'max' => $max, 'delay' => (int) $delayRaw, 'expires_at' => $expiresAt, 'expiry' => $d ? $expiry : null];
    }

    /** Worst-case cost reserved up front: max per post × (new + old posts). */
    public static function postsReserve(string $rate, array $p): string
    {
        return Money::divInt(Money::mul($rate, (string) ($p['max'] * ($p['posts'] + $p['old_posts']))), 1000);
    }

    /**
     * Create a post-based subscription: the subscription row and its single
     * provider order (charged with the full reserve) in one transaction.
     */
    public static function createPosts(int $userId, int $serviceId, array $input, ?string $idempotencyKey = null): array
    {
        $db = Database::instance();
        $service = OrderService::orderableService($serviceId);
        $p = self::validatePosts($service, $input);
        $user = $db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if (!$user || $user['status'] !== 'active') {
            throw new ValidationException('Your account is not active.');
        }
        $rate = OrderService::userRate($service, $user);
        $reserve = self::postsReserve($rate, $p);

        if ($idempotencyKey !== null) {
            $idempotencyKey = substr(preg_replace('/[^A-Za-z0-9_\-:]/', '', $idempotencyKey), 0, 64) ?: null;
            if ($idempotencyKey && ($existing = $db->fetch('SELECT * FROM subscriptions WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]))) {
                return $existing + ['duplicate' => true];
            }
        }
        $extra = ['username' => $p['username'], 'min' => $p['min'], 'max' => $p['max'], 'posts' => $p['posts'], 'old_posts' => $p['old_posts'], 'delay' => $p['delay']];
        if ($p['expiry'] !== null) {
            $extra['expiry'] = $p['expiry'];
        }
        try {
            [$subId, $orderId] = $db->transaction(static function (Database $db) use ($userId, $service, $p, $rate, $reserve, $extra, $idempotencyKey): array {
                $subId = $db->insert('subscriptions', [
                    'user_id' => $userId,
                    'service_id' => (int) $service['id'],
                    'mode' => 'posts',
                    'link' => $p['username'],
                    'quantity' => $p['max'],
                    'qty_min' => $p['min'],
                    'old_posts' => $p['old_posts'],
                    'delay_minutes' => $p['delay'],
                    'expires_at' => $p['expires_at'],
                    'rate' => $rate,
                    'cost_rate' => $service['provider_rate'] !== null && Money::isPositive((string) $service['provider_rate']) ? $service['provider_rate'] : null,
                    'prepaid' => $reserve,
                    'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
                    'interval_hours' => 0,
                    'total_cycles' => $p['posts'],
                    'completed_cycles' => 0,
                    'status' => 'active',
                    'next_run_at' => $p['expires_at'],
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $order = OrderService::place($userId, (int) $service['id'], [], 'subscription', "sub{$subId}posts", false, [
                    'subscription_id' => $subId, 'subscription_cycle' => 1,
                    'posts' => ['link' => $p['username'], 'quantity' => $p['max'] * ($p['posts'] + $p['old_posts']), 'extra' => $extra, 'charge' => $reserve, 'rate' => $rate],
                ]);
                $db->update('subscriptions', ['last_order_id' => (int) $order['id'], 'last_run_at' => now()], ['id' => $subId]);
                self::log($subId, 'created', null, (int) $order['id'], sprintf('%d new + %d old posts, %s–%s per post, delay %s%s — reserved %s', $p['posts'], $p['old_posts'], number_format($p['min']), number_format($p['max']), strtolower(self::DELAYS[$p['delay']]), $p['expiry'] ? ', expires ' . $p['expiry'] : '', money_base($reserve)), 'user');
                return [$subId, (int) $order['id']];
            });
        } catch (\PDOException $e) {
            if ($idempotencyKey && str_contains($e->getMessage(), 'uq_sub_idem')) {
                return $db->fetch('SELECT * FROM subscriptions WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]) + ['duplicate' => true];
            }
            throw $e;
        }
        if (!empty($service['provider_id']) && !empty($service['provider_service_id'])) {
            OrderService::submit($orderId);
        }
        AuditService::log('subscription.create', 'subscription', $subId, ['service' => (int) $service['id'], 'mode' => 'posts', 'posts' => $p['posts'], 'old_posts' => $p['old_posts'], 'reserve' => $reserve], 'user', $userId);
        return self::find($subId);
    }

    /**
     * Provider status for the order behind a post subscription (called by
     * OrderService::applyStatus inside its transaction, order row locked).
     */
    public static function applyPostsStatus(array $order, \App\Providers\ProviderOrderStatus $ps, string $actor): void
    {
        $db = Database::instance();
        $sub = $db->fetch('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE', [(int) $order['subscription_id']]);
        if (!$sub) {
            return;
        }
        $raw = json_decode($ps->raw, true) ?: [];
        $lower = array_change_key_case(is_array($raw) ? $raw : [], CASE_LOWER);
        $update = ['updated_at' => now()];
        $orderUpdate = ['last_synced_at' => now(), 'updated_at' => now()];
        if (isset($lower['posts']) && is_numeric($lower['posts'])) {
            $done = max(0, min((int) $sub['total_cycles'] + (int) $sub['old_posts'], (int) $lower['posts']));
            if ($done !== (int) $sub['completed_cycles']) {
                $update['completed_cycles'] = $done;
                self::log((int) $sub['id'], 'posts_processed', $done, (int) $order['id'], "Provider processed {$done} post(s)", $actor);
            }
        }
        if (isset($lower['status']) && is_scalar($lower['status'])) {
            $update['provider_status'] = mb_substr((string) $lower['status'], 0, 40);
        }
        if ($ps->charge !== null) {
            $orderUpdate['cost'] = $ps->charge; // latest cumulative provider charge
        }
        $db->update('subscriptions', $update, ['id' => (int) $sub['id']]);
        if (in_array($ps->status, ['completed', 'partial', 'cancelled'], true)) {
            $db->update('orders', $orderUpdate, ['id' => (int) $order['id']]);
            $rawStatus = strtolower((string) ($lower['status'] ?? ''));
            $final = str_contains($rawStatus, 'expire') ? 'expired' : ($ps->status === 'cancelled' ? 'cancelled' : 'completed');
            self::reconcile((int) $sub['id'], $final, $actor, 'Provider reported ' . ($lower['status'] ?? $ps->status));
            return;
        }
        if (in_array($ps->status, ['processing', 'in_progress'], true) && $order['status'] !== $ps->status) {
            $orderUpdate['status'] = $ps->status;
            OrderService::log((int) $order['id'], 'status', $order['status'], $ps->status, 'Subscription active at provider', $actor);
        }
        $db->update('orders', $orderUpdate, ['id' => (int) $order['id']]);
    }

    /**
     * Close a post subscription and refund what was not used — exactly once.
     * $final: completed | expired | cancelled | failed.
     */
    public static function reconcile(int $subId, string $final, string $actor, string $reason = ''): ?string
    {
        $db = Database::instance();
        $notify = null;
        $refunded = $db->transaction(static function (Database $db) use ($subId, $final, $actor, $reason, &$notify): ?string {
            $sub = $db->fetch('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE', [$subId]);
            if (!$sub || $sub['mode'] !== 'posts' || $sub['final_charge'] !== null) {
                return null; // not a post subscription, or already settled
            }
            $order = $sub['last_order_id'] ? $db->fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [(int) $sub['last_order_id']]) : null;
            $reserve = (string) ($sub['prepaid'] ?? '0');
            $used = self::usedAmount($sub, $order);
            if ($final === 'failed') {
                $used = '0.000000';
            }
            $used = Money::min(Money::max($used, '0'), $reserve);
            $refund = Money::sub($reserve, $used);
            $done = '0.000000';
            if ($order) {
                $orderStatus = !Money::isPositive($used) ? 'cancelled' : (Money::isPositive($refund) ? 'partial' : 'completed');
                if (in_array($order['status'], OrderService::FINAL_STATUSES, true)) {
                    $orderStatus = null; // already final (e.g. failed submission refunded everything)
                }
                $done = OrderService::refund((int) $order['id'], $refund, 'subscription', $orderStatus, "Subscription #{$subId} {$final}: used {$used} of {$reserve}", $actor);
                // The ledger is the truth (an earlier failure/admin refund may already have returned more).
                $after = $db->fetch('SELECT charge, refunded_amount FROM orders WHERE id = ?', [(int) $order['id']]);
                $used = Money::sub((string) $after['charge'], (string) $after['refunded_amount']);
            }
            $db->update('subscriptions', [
                'status' => $final,
                'final_charge' => $used,
                'refunded' => Money::sub($reserve, $used),
                'next_run_at' => null,
                'locked_until' => null,
                'completed_at' => in_array($final, ['completed', 'expired'], true) ? now() : $sub['completed_at'],
                'cancelled_at' => $final === 'cancelled' ? ($sub['cancelled_at'] ?? now()) : $sub['cancelled_at'],
                'updated_at' => now(),
            ], ['id' => $subId]);
            self::log($subId, $final, (int) $sub['completed_cycles'], $order ? (int) $order['id'] : null, trim(($reason !== '' ? $reason . ' — ' : '') . "charged " . money_base($used) . ' of the ' . money_base($reserve) . ' reserve, refunded ' . money_base(Money::sub($reserve, $used))), $actor);
            $notify = [(int) $sub['user_id'], "Subscription #{$subId} {$final}", Money::isPositive($refund) ? 'The unused part of your reserve (' . money($refund) . ') has been returned to your balance.' : 'Your subscription has finished.'];
            return $done;
        });
        if ($notify) {
            NotificationService::notify($notify[0], 'order', $notify[1], $notify[2], '/subscriptions/' . $subId);
        }
        return $refunded;
    }

    /**
     * What the user owes for a post subscription so far. With a provider charge
     * (provider currency) and a known cost rate: charge × exchange rate × sell/cost.
     * Otherwise processed posts × max × rate. Never more than the reserve (caller clamps).
     */
    public static function usedAmount(array $sub, ?array $order): string
    {
        $rate = (string) ($sub['rate'] ?? '0');
        if ($order && $order['cost'] !== null && $sub['cost_rate'] !== null && Money::isPositive((string) $sub['cost_rate'])) {
            $xr = (string) (Database::instance()->fetchColumn('SELECT exchange_rate FROM providers WHERE id = ?', [(int) $order['provider_id']]) ?: '1');
            $costSite = Money::mul((string) $order['cost'], $xr);
            return Money::div(Money::mul($costSite, $rate), (string) $sub['cost_rate']);
        }
        return Money::divInt(Money::mul($rate, (string) ((int) $sub['completed_cycles'] * (int) $sub['quantity'])), 1000);
    }

    /** Admin: record processed posts for a manual (no-provider) post subscription. */
    public static function recordPosts(int $subId, int $posts, int $adminId): void
    {
        $db = Database::instance();
        $sub = self::find($subId);
        if (!$sub || $sub['mode'] !== 'posts' || in_array($sub['status'], self::FINAL, true)) {
            throw new ValidationException('Only an open post subscription can be updated.');
        }
        $max = (int) $sub['total_cycles'] + (int) $sub['old_posts'];
        if ($posts < 0 || $posts > $max) {
            throw new ValidationException("Processed posts must be between 0 and {$max}.");
        }
        $db->update('subscriptions', ['completed_cycles' => $posts, 'updated_at' => now()], ['id' => $subId]);
        self::log($subId, 'posts_processed', $posts, null, "Admin recorded {$posts} processed post(s)", 'admin:' . $adminId);
    }

    /**
     * Cron for post subscriptions: settle manual ones at expiry, and provider
     * ones whose final status never arrived within the grace period after
     * expiry or an accepted cancel. Each row is claimed atomically first.
     */
    public static function processPostsDue(int $limit = 50): array
    {
        $db = Database::instance();
        $out = ['expired' => 0, 'settled' => 0];
        $grace = gmdate('Y-m-d H:i:s', time() - self::PROVIDER_GRACE_HOURS * 3600);
        $rows = $db->fetchAll(
            "SELECT s.id, s.status, s.expires_at, s.cancelled_at, o.provider_id, o.submit_state FROM subscriptions s LEFT JOIN orders o ON o.id = s.last_order_id
             WHERE s.mode = 'posts' AND s.final_charge IS NULL AND (s.locked_until IS NULL OR s.locked_until < ?)
               AND ((s.status = 'active' AND s.expires_at IS NOT NULL AND s.expires_at <= ?) OR (s.status = 'cancelled' AND s.cancelled_at <= ?))
             ORDER BY s.id LIMIT " . max(1, $limit),
            [now(), now(), $grace]
        );
        foreach ($rows as $r) {
            $claimed = $db->query('UPDATE subscriptions SET locked_until = ? WHERE id = ? AND final_charge IS NULL AND (locked_until IS NULL OR locked_until < ?)', [gmdate('Y-m-d H:i:s', time() + self::CLAIM_MINUTES * 60), $r['id'], now()])->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            $manual = empty($r['provider_id']) || $r['submit_state'] === 'manual';
            if ($r['status'] === 'cancelled') {
                self::reconcile((int) $r['id'], 'cancelled', 'cron', 'No final status from the provider after the cancel');
                $out['settled']++;
            } elseif ($manual || strtotime($r['expires_at'] . ' UTC') <= strtotime($grace . ' UTC')) {
                self::reconcile((int) $r['id'], 'expired', 'cron', $manual ? 'Expiry date reached' : 'Expired; no final status from the provider');
                $out['expired']++;
            } else {
                $db->update('subscriptions', ['locked_until' => null], ['id' => $r['id']]); // wait for the provider's final status
            }
        }
        return $out;
    }

    /**
     * Cancel a post subscription. Not yet sent to the provider → cancelled and
     * refunded in full at once. Manual → settled with the processed posts.
     * At a provider → the provider must accept the cancel; the final amount is
     * settled when its final status arrives (or after the grace period).
     */
    public static function cancelPosts(array $sub, string $actor, bool $force = false): void
    {
        $db = Database::instance();
        $order = $sub['last_order_id'] ? OrderService::find((int) $sub['last_order_id']) : null;
        // Not submitted yet: take the order out of the queue atomically so cron cannot send it.
        if ($order && $db->query("UPDATE orders SET submit_state = 'failed', updated_at = ? WHERE id = ? AND submit_state = 'queued'", [now(), $order['id']])->rowCount() === 1) {
            $db->update('subscriptions', ['completed_cycles' => 0], ['id' => (int) $sub['id']]);
            self::reconcile((int) $sub['id'], 'cancelled', $actor, 'Cancelled before it was sent to the provider');
            return;
        }
        if (!$order || $order['submit_state'] === 'manual' || empty($order['provider_order_id'])) {
            if ($order && !in_array($order['submit_state'], ['manual', 'failed'], true) && !$force) {
                throw new ValidationException('This subscription is being sent to the provider right now. Try again in a minute.');
            }
            self::reconcile((int) $sub['id'], 'cancelled', $actor, 'Cancelled');
            return;
        }
        $service = $db->fetch('SELECT * FROM services WHERE id = ?', [(int) $sub['service_id']]);
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [(int) $order['provider_id']]);
        $accepted = false;
        if ((int) ($service['cancel'] ?? 0) === 1 && $provider) {
            try {
                $res = \App\Providers\ProviderFactory::make($provider)->cancel([(string) $order['provider_order_id']]);
                $accepted = ($res[(string) $order['provider_order_id']] ?? false) === true;
            } catch (\Throwable $e) {
                Logger::warning("Subscription #{$sub['id']} cancel at provider failed: " . $e->getMessage(), [], 'subscription');
            }
        }
        if (!$accepted && !$force) {
            throw new ValidationException('The provider did not accept a cancellation for this subscription. It ends at its expiry date, and unused posts are refunded then.');
        }
        $db->update('subscriptions', ['status' => 'cancelled', 'cancelled_at' => now(), 'next_run_at' => null, 'updated_at' => now()], ['id' => (int) $sub['id']]);
        self::log((int) $sub['id'], 'cancel_requested', null, (int) $order['id'], $accepted ? 'Cancel accepted by the provider; settling when its final status arrives' : 'Force-cancelled by an admin (provider did not confirm)', $actor);
        if ($force && !$accepted) {
            self::reconcile((int) $sub['id'], 'cancelled', $actor, 'Force-cancelled; settled with the posts processed so far');
        }
    }

    /** Validate interval + number of deliveries against the service's rules. */
    public static function assertSchedule(array $service, int $intervalHours, int $cycles): void
    {
        $o = self::options($service);
        if (!self::enabled()) {
            throw new ValidationException('Auto-subscriptions are currently disabled.');
        }
        if (!$o['allowed']) {
            throw new ValidationException('This service does not support auto-subscriptions.');
        }
        if (!isset($o['intervals'][$intervalHours])) {
            throw new ValidationException('Choose how often the order should repeat (' . strtolower(implode(', ', $o['intervals'])) . ').');
        }
        if ($cycles < $o['min'] || $cycles > $o['max']) {
            throw new ValidationException("Number of deliveries must be between {$o['min']} and {$o['max']}.");
        }
    }

    /**
     * Create a subscription and place its first order in one transaction: if the
     * first cycle cannot be charged (e.g. insufficient balance) nothing is created.
     */
    public static function create(int $userId, int $serviceId, array $input, int $intervalHours, int $cycles, ?string $idempotencyKey = null): array
    {
        $db = Database::instance();
        $service = OrderService::orderableService($serviceId);
        self::assertSchedule($service, $intervalHours, $cycles);
        unset($input['dripfeed'], $input['runs'], $input['interval']); // drip-feed and subscriptions do not mix
        $params = OrderService::validateInput($service, $input);

        if ($idempotencyKey !== null) {
            $idempotencyKey = substr(preg_replace('/[^A-Za-z0-9_\-:]/', '', $idempotencyKey), 0, 64) ?: null;
            if ($idempotencyKey && ($existing = $db->fetch('SELECT * FROM subscriptions WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]))) {
                return $existing + ['duplicate' => true];
            }
        }

        try {
            [$subId, $orderId] = $db->transaction(static function (Database $db) use ($userId, $service, $params, $intervalHours, $cycles, $idempotencyKey): array {
                $subId = $db->insert('subscriptions', [
                    'user_id' => $userId,
                    'service_id' => (int) $service['id'],
                    'link' => $params['link'],
                    'quantity' => $params['quantity'],
                    'extra' => $params['extra'] ? json_encode($params['extra'], JSON_UNESCAPED_UNICODE) : null,
                    'interval_hours' => $intervalHours,
                    'total_cycles' => $cycles,
                    'completed_cycles' => 0,
                    'status' => 'active',
                    'next_run_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                // Cycle 1 is charged inside the same transaction; submitted after commit.
                $order = OrderService::place($userId, (int) $service['id'], self::orderInput($params), 'subscription', "sub{$subId}c1", false, ['subscription_id' => $subId, 'subscription_cycle' => 1]);
                self::advance($db, $subId, 1, (int) $order['id'], $cycles, $intervalHours);
                self::log($subId, 'created', null, null, "{$cycles} deliveries, " . strtolower(self::INTERVALS[$intervalHours]), 'user');
                self::log($subId, 'cycle_ordered', 1, (int) $order['id'], 'Order #' . $order['id'] . ' placed', 'user');
                return [$subId, (int) $order['id']];
            });
        } catch (\PDOException $e) {
            if ($idempotencyKey && str_contains($e->getMessage(), 'uq_sub_idem')) {
                return $db->fetch('SELECT * FROM subscriptions WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]) + ['duplicate' => true];
            }
            throw $e;
        }
        if (!empty($service['provider_id']) && !empty($service['provider_service_id'])) {
            OrderService::submit($orderId);
        }
        AuditService::log('subscription.create', 'subscription', $subId, ['service' => (int) $service['id'], 'cycles' => $cycles, 'interval_hours' => $intervalHours], 'user', $userId);
        return self::find($subId);
    }

    /** Cron: run every subscription whose next cycle is due. */
    public static function processDue(int $limit = 50): array
    {
        $out = ['due' => 0, 'ordered' => 0, 'retry' => 0, 'suspended' => 0, 'completed' => 0, 'skipped' => 0];
        if (!self::enabled()) {
            // No new deliveries, but open post subscriptions are still settled (refunds are owed).
            return $out + ['disabled' => true] + self::processPostsDue($limit);
        }
        $ids = Database::instance()->fetchAll(
            "SELECT id FROM subscriptions WHERE mode = 'scheduled' AND status = 'active' AND next_run_at <= ? AND (locked_until IS NULL OR locked_until < ?) ORDER BY next_run_at ASC LIMIT " . max(1, $limit),
            [now(), now()]
        );
        foreach ($ids as $row) {
            $out['due']++;
            $r = self::runCycle((int) $row['id'], 'cron');
            $out[$r] = ($out[$r] ?? 0) + 1;
        }
        return $out + self::processPostsDue($limit);
    }

    /**
     * Place the next cycle's order for one subscription.
     * @return string ordered|completed|retry|suspended|skipped
     */
    public static function runCycle(int $subId, string $actor = 'cron'): string
    {
        $db = Database::instance();
        $claimed = $db->query(
            "UPDATE subscriptions SET locked_until = ?, updated_at = ? WHERE id = ? AND mode = 'scheduled' AND status = 'active' AND next_run_at <= ? AND completed_cycles < total_cycles AND (locked_until IS NULL OR locked_until < ?)",
            [gmdate('Y-m-d H:i:s', time() + self::CLAIM_MINUTES * 60), now(), $subId, now(), now()]
        )->rowCount();
        if ($claimed !== 1) {
            return 'skipped';
        }
        $sub = self::find($subId);
        $cycle = (int) $sub['completed_cycles'] + 1;
        try {
            $order = OrderService::place(
                (int) $sub['user_id'],
                (int) $sub['service_id'],
                self::orderInput(['link' => $sub['link'], 'quantity' => (int) $sub['quantity'], 'extra' => json_decode((string) $sub['extra'], true) ?: []]),
                'subscription',
                "sub{$subId}c{$cycle}",
                true,
                ['subscription_id' => $subId, 'subscription_cycle' => $cycle]
            );
        } catch (\Throwable $e) {
            return self::fail($sub, $cycle, $e, $actor);
        }
        $done = self::advance($db, $subId, $cycle, (int) $order['id'], (int) $sub['total_cycles'], (int) $sub['interval_hours']);
        self::log($subId, 'cycle_ordered', $cycle, (int) $order['id'], 'Order #' . $order['id'] . (!empty($order['duplicate']) ? ' (already existed for this cycle)' : ' placed'), $actor);
        if ($done) {
            self::log($subId, 'completed', $cycle, null, 'All deliveries ordered', 'system');
            NotificationService::notify((int) $sub['user_id'], 'order', "Subscription #{$subId} completed", "All {$sub['total_cycles']} deliveries have been ordered.", '/subscriptions/' . $subId);
            return 'completed';
        }
        return 'ordered';
    }

    /** Record a successful cycle; returns true when this was the last one. */
    private static function advance(Database $db, int $subId, int $cycle, int $orderId, int $total, int $intervalHours): bool
    {
        $last = $cycle >= $total;
        // A pause/cancel that happened while this cycle was being placed is kept.
        $db->query(
            "UPDATE subscriptions SET completed_cycles = ?, last_order_id = ?, last_run_at = ?, attempts = 0, last_error = NULL, locked_until = NULL,
                next_run_at = ?, status = IF(? = 1 AND status = 'active', 'completed', status), completed_at = IF(? = 1 AND status = 'completed', ?, completed_at), updated_at = ?
             WHERE id = ? AND completed_cycles = ?",
            [$cycle, $orderId, now(), $last ? null : gmdate('Y-m-d H:i:s', time() + $intervalHours * 3600), $last ? 1 : 0, $last ? 1 : 0, now(), now(), $subId, $cycle - 1]
        );
        return $last;
    }

    private static function fail(array $sub, int $cycle, \Throwable $e, string $actor): string
    {
        $db = Database::instance();
        $subId = (int) $sub['id'];
        $attempts = (int) $sub['attempts'] + 1;
        $msg = mb_substr($e->getMessage(), 0, 480);
        $expected = $e instanceof ValidationException; // balance, service unavailable, input no longer valid
        if (!$expected) {
            Logger::error("Subscription #{$subId} cycle {$cycle} failed: {$msg}", ['file' => $e->getFile(), 'line' => $e->getLine()], 'subscription');
        }
        if ($attempts > count(self::RETRY_MINUTES)) {
            $db->update('subscriptions', ['status' => 'suspended', 'attempts' => $attempts, 'last_error' => $msg, 'locked_until' => null, 'updated_at' => now()], ['id' => $subId]);
            self::log($subId, 'suspended', $cycle, null, "Suspended after {$attempts} failed attempts: {$msg}", $actor);
            NotificationService::notify((int) $sub['user_id'], 'order', "Subscription #{$subId} paused", "We could not place delivery {$cycle}: {$msg} Resume it from your subscriptions page once resolved.", '/subscriptions/' . $subId);
            NotificationService::notifyAdmin("Subscription #{$subId} suspended", '<p>' . e($msg) . '</p>');
            return 'suspended';
        }
        $delay = self::RETRY_MINUTES[$attempts - 1];
        $db->update('subscriptions', ['attempts' => $attempts, 'last_error' => $msg, 'locked_until' => null, 'next_run_at' => gmdate('Y-m-d H:i:s', time() + $delay * 60), 'updated_at' => now()], ['id' => $subId]);
        self::log($subId, 'cycle_failed', $cycle, null, "Attempt {$attempts} failed, retrying in {$delay} min: {$msg}", $actor);
        if ($attempts === 1) {
            NotificationService::notify((int) $sub['user_id'], 'order', "Subscription #{$subId}: delivery {$cycle} delayed", $msg . ' We will retry automatically.', '/subscriptions/' . $subId);
        }
        return 'retry';
    }

    // ------------------------------------------------------------------ lifecycle

    /** @param 'pause'|'resume'|'cancel' $action */
    public static function changeStatus(int $subId, string $action, string $actor, ?int $ownerId = null, string $reason = '', bool $force = false): array
    {
        $db = Database::instance();
        $current = self::find($subId);
        if ($current && ($current['mode'] ?? 'scheduled') === 'posts') {
            // The provider runs post subscriptions: they cannot be paused, only cancelled.
            if (!$current || ($ownerId !== null && (int) $current['user_id'] !== $ownerId)) {
                throw new ValidationException('Subscription not found.');
            }
            if ($action !== 'cancel') {
                throw new ValidationException('Post subscriptions cannot be paused or resumed; you can cancel them.');
            }
            if ($current['status'] !== 'active') {
                throw new ValidationException("A {$current['status']} subscription cannot be cancelled.");
            }
            self::cancelPosts($current, $actor, $force);
            if ($reason !== '') {
                self::log($subId, 'note', null, null, $reason, $actor);
            }
            return self::find($subId);
        }
        return $db->transaction(static function (Database $db) use ($subId, $action, $actor, $ownerId, $reason): array {
            $sub = $db->fetch('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE', [$subId]);
            if (!$sub || ($ownerId !== null && (int) $sub['user_id'] !== $ownerId)) {
                throw new ValidationException('Subscription not found.');
            }
            $from = $sub['status'];
            $allowed = [
                'pause' => ['active'],
                'resume' => ['paused', 'suspended'],
                'cancel' => ['active', 'paused', 'suspended'],
            ];
            if (!isset($allowed[$action])) {
                throw new ValidationException('Unknown action.');
            }
            if (!in_array($from, $allowed[$action], true)) {
                throw new ValidationException("A {$from} subscription cannot be {$action}d.");
            }
            $update = ['updated_at' => now(), 'locked_until' => null];
            if ($action === 'pause') {
                $update['status'] = 'paused';
            } elseif ($action === 'resume') {
                $update['status'] = 'active';
                $update['attempts'] = 0;
                $update['last_error'] = null;
                $next = $sub['next_run_at'] ? strtotime($sub['next_run_at'] . ' UTC') : 0;
                $update['next_run_at'] = gmdate('Y-m-d H:i:s', max($next, time()));
            } else {
                $update['status'] = 'cancelled';
                $update['cancelled_at'] = now();
                $update['next_run_at'] = null;
            }
            $db->update('subscriptions', $update, ['id' => $subId]);
            self::log($subId, $action === 'cancel' ? 'cancelled' : ($action === 'pause' ? 'paused' : 'resumed'), null, null, $reason !== '' ? $reason : "{$from} → {$update['status']}", $actor);
            return $db->fetch('SELECT * FROM subscriptions WHERE id = ?', [$subId]);
        });
    }

    // ------------------------------------------------------------------ helpers

    public static function find(int $subId): array
    {
        return Database::instance()->fetch('SELECT * FROM subscriptions WHERE id = ?', [$subId]) ?? [];
    }

    /** Charge per delivery at the user's current price (orders are charged when placed). */
    public static function cycleCharge(array $sub, array $user): string
    {
        $service = Database::instance()->fetch('SELECT * FROM services WHERE id = ?', [(int) $sub['service_id']]);
        if (!$service) {
            return '0';
        }
        return OrderService::computeCharge($service, OrderService::userRate($service, $user), (int) $sub['quantity']);
    }

    /** Display summary for either mode (lists and detail pages). */
    public static function summary(array $sub): array
    {
        if (($sub['mode'] ?? 'scheduled') === 'posts') {
            $total = (int) $sub['total_cycles'] + (int) $sub['old_posts'];
            return [
                'posts' => true,
                'kind' => 'Post-based',
                'target_label' => 'Username',
                'schedule' => (int) $sub['total_cycles'] . ' new' . ((int) $sub['old_posts'] ? ' + ' . (int) $sub['old_posts'] . ' old' : '') . ' posts · ' . (self::DELAYS[(int) $sub['delay_minutes']] ?? ((int) $sub['delay_minutes'] . ' min')) . ' delay',
                'qty' => number_format((int) ($sub['qty_min'] ?? $sub['quantity'])) . '–' . number_format((int) $sub['quantity']) . ' / post',
                'done' => (int) $sub['completed_cycles'], 'total' => $total, 'unit' => 'posts processed',
                'expiry' => $sub['expires_at'] ? fmt_date($sub['expires_at'], 'M j, Y') : 'No expiry',
            ];
        }
        return [
            'posts' => false,
            'kind' => 'Scheduled',
            'target_label' => 'Link',
            'schedule' => self::INTERVALS[(int) $sub['interval_hours']] ?? ((int) $sub['interval_hours'] . ' h'),
            'qty' => number_format((int) $sub['quantity']) . ' / delivery',
            'done' => (int) $sub['completed_cycles'], 'total' => (int) $sub['total_cycles'], 'unit' => 'deliveries',
            'expiry' => null,
        ];
    }

    public static function spent(int $subId): string
    {
        return Money::of((string) Database::instance()->fetchColumn('SELECT COALESCE(SUM(charge - refunded_amount), 0) FROM orders WHERE subscription_id = ?', [$subId]));
    }

    public static function log(int $subId, string $event, ?int $cycle, ?int $orderId, ?string $message, string $actor = 'system'): void
    {
        Database::instance()->insert('subscription_logs', [
            'subscription_id' => $subId, 'event' => $event, 'cycle' => $cycle, 'order_id' => $orderId,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null, 'actor' => mb_substr($actor, 0, 40), 'created_at' => now(),
        ]);
    }

    /** Rebuild order-form input from validated parameters. */
    private static function orderInput(array $params): array
    {
        $in = ['link' => $params['link'], 'quantity' => (string) $params['quantity']];
        foreach ($params['extra'] ?? [] as $k => $v) {
            $in[$k] = $v;
        }
        return $in;
    }
}
