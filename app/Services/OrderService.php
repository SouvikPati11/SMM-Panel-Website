<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;
use App\Providers\ProviderOrderStatus;

/**
 * Order engine.
 *
 *   place()  TX: lock wallet → debit → insert order (queued)   [atomic, idempotent]
 *   submit() claim queued→submitting atomically → call provider → settle:
 *              success      → submitted / processing
 *              rejected     → failed + full refund
 *              unreachable  → back to queued (cron retries; request never arrived)
 *              unknown      → "unknown" + needs_attention (never auto-retried or refunded)
 *   applyStatus() provider/admin status → partial/cancel refunds exactly once.
 */
final class OrderService
{
    public const ACTIVE_STATUSES = ['pending', 'processing', 'in_progress'];
    public const FINAL_STATUSES = ['completed', 'partial', 'cancelled', 'refunded', 'failed'];
    public const MAX_SUBMIT_ATTEMPTS = 8;

    /** Service types and which inputs each needs. `package` types charge `rate` once (not per 1000). */
    public const TYPES = [
        'default' => ['label' => 'Default', 'quantity' => true, 'package' => false],
        'package' => ['label' => 'Package', 'quantity' => false, 'package' => true],
        'custom_comments' => ['label' => 'Custom Comments', 'quantity' => false, 'package' => false, 'list' => 'comments'],
        'custom_comments_package' => ['label' => 'Custom Comments Package', 'quantity' => false, 'package' => true, 'list' => 'comments'],
        'mentions_custom_list' => ['label' => 'Mentions (custom list)', 'quantity' => false, 'package' => false, 'list' => 'usernames'],
        'comment_likes' => ['label' => 'Comment Likes', 'quantity' => true, 'package' => false, 'field' => 'username'],
        'poll' => ['label' => 'Poll', 'quantity' => true, 'package' => false, 'field' => 'answer_number'],
        'keywords' => ['label' => 'Keywords / Search', 'quantity' => true, 'package' => false, 'field' => 'keywords'],
        // Panel-side recurring deliveries: each delivery is a normal link + quantity order
        // placed by the "subscriptions" cron task. Only orderable as a subscription.
        'subscription' => ['label' => 'Subscriptions', 'quantity' => true, 'package' => false, 'subscription' => true],
    ];

    // ------------------------------------------------------------------
    // Pricing
    // ------------------------------------------------------------------

    /** Effective discount % for a user: the larger of their price level and custom discount. */
    public static function userDiscount(array $user): string
    {
        $custom = Money::of((string) ($user['custom_discount'] ?? '0'), 2);
        $level = '0.00';
        if (!empty($user['price_level_id'])) {
            $level = Money::of((string) (Database::instance()->fetchColumn('SELECT discount_percent FROM price_levels WHERE id = ?', [(int) $user['price_level_id']]) ?? '0'), 2);
        }
        $d = Money::max($custom, $level);
        return Money::min($d, '100');
    }

    /** Rate per 1000 (or per package) for this user, after discount. */
    public static function userRate(array $service, array $user): string
    {
        $rate = Money::of((string) $service['rate']);
        $discount = self::userDiscount($user);
        if (Money::isPositive($discount)) {
            $rate = Money::sub($rate, Money::percent($rate, $discount));
        }
        return $rate;
    }

    public static function computeCharge(array $service, string $rate, int $quantity, ?int $runs = null): string
    {
        $isPackage = self::TYPES[$service['type']]['package'] ?? false;
        if ($isPackage) {
            return Money::of($rate);
        }
        $total = $quantity * max(1, (int) $runs);
        return Money::divInt(Money::mul($rate, (string) $total), 1000);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validate order input against the service configuration.
     * @return array{link:string, quantity:int, runs:?int, interval:?int, extra:array<string,string>}
     */
    public static function validateInput(array $service, array $input): array
    {
        $type = $service['type'];
        $def = self::TYPES[$type] ?? self::TYPES['default'];
        $custom = json_decode((string) ($service['custom_fields'] ?? ''), true) ?: [];

        $link = trim((string) ($input['link'] ?? ''));
        if ($link === '') {
            throw new ValidationException(($service['link_label'] ?: 'Link') . ' is required.');
        }
        if (mb_strlen($link) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $link)) {
            throw new ValidationException('The link contains invalid characters.');
        }
        $linkType = $custom['link_type'] ?? 'url';
        if ($linkType === 'url') {
            $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
            if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new ValidationException('Enter the full link, starting with https:// (for example https://instagram.com/yourname).');
            }
        } elseif (preg_match('/\s/', $link)) {
            throw new ValidationException(($service['link_label'] ?: 'Link') . ' must not contain spaces.');
        }
        if (!empty($custom['link_regex']) && @preg_match((string) $custom['link_regex'], '') !== false && !preg_match((string) $custom['link_regex'], $link)) {
            throw new ValidationException(($service['link_label'] ?: 'Link') . ' doesn\'t look right for this service. Check that you copied the full link.');
        }

        $extra = [];
        $quantity = 0;
        if (isset($def['list'])) {
            $field = $def['list'];
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($input[$field] ?? ''))), static fn ($l) => $l !== ''));
            if (!$lines) {
                throw new ValidationException('Please enter at least one ' . ($field === 'comments' ? 'comment' : 'username') . ' (one per line).');
            }
            foreach ($lines as $l) {
                if (mb_strlen($l) > 500) {
                    throw new ValidationException('Each line must be 500 characters or fewer.');
                }
            }
            $extra[$field] = implode("\n", $lines);
            $quantity = count($lines);
        }
        if ($def['quantity']) {
            $q = (string) ($input['quantity'] ?? '');
            if (!preg_match('/^\d{1,10}$/', $q)) {
                throw new ValidationException('Enter the quantity as a whole number, for example 1000.');
            }
            $quantity = (int) $q;
        }
        if ($def['package'] && !isset($def['list'])) {
            $quantity = 1;
        }
        if (!$def['package']) {
            if ($quantity < (int) $service['min_quantity'] || $quantity > (int) $service['max_quantity']) {
                throw new ValidationException(sprintf('Enter a quantity between %s and %s.', number_format((int) $service['min_quantity']), number_format((int) $service['max_quantity'])));
            }
        }
        if (isset($def['field'])) {
            $val = trim((string) ($input[$def['field']] ?? ''));
            if ($val === '' || mb_strlen($val) > 500) {
                throw new ValidationException(ucfirst(str_replace('_', ' ', $def['field'])) . ' is required.');
            }
            if ($def['field'] === 'answer_number' && !preg_match('/^\d{1,3}$/', $val)) {
                throw new ValidationException('Answer number must be a number.');
            }
            $extra[$def['field']] = $val;
        }

        $runs = null;
        $interval = null;
        if ((int) $service['dripfeed'] === 1 && !empty($input['dripfeed']) && in_array((string) $input['dripfeed'], ['1', 'on', 'true'], true)) {
            $runs = (int) ($input['runs'] ?? 0);
            $interval = (int) ($input['interval'] ?? 0);
            if ($runs < 2 || $runs > 1000) {
                throw new ValidationException('For gradual delivery, choose between 2 and 1,000 rounds.');
            }
            if ($interval < 1 || $interval > 1440) {
                throw new ValidationException('For gradual delivery, choose 1 to 1,440 minutes between rounds.');
            }
        }

        return ['link' => $link, 'quantity' => $quantity, 'runs' => $runs, 'interval' => $interval, 'extra' => $extra];
    }

    /**
     * A service customers may order now. $checkPlatform = false only for
     * deliveries of subscriptions that already exist: turning a platform OFF
     * stops new orders but does not break subscriptions users already paid for.
     */
    public static function orderableService(int $serviceId, bool $checkPlatform = true): array
    {
        $service = Database::instance()->fetch(
            "SELECT s.*, c.status AS category_status, c.name AS category_name, c.platform AS category_platform FROM services s JOIN categories c ON c.id = s.category_id WHERE s.id = ?",
            [$serviceId]
        );
        if (!$service || $service['status'] !== 'active' || (int) $service['is_hidden'] === 1 || $service['category_status'] !== 'active'
            || ($checkPlatform && !\App\Helpers\Platforms::categoryVisible(['name' => $service['category_name'], 'platform' => $service['category_platform']]))) {
            throw new ValidationException('This service is not available right now. Please choose another one.');
        }
        return $service;
    }

    // ------------------------------------------------------------------
    // Placing orders
    // ------------------------------------------------------------------

    /**
     * Charge the user and create the order, then try to submit it to the provider.
     * Safe against double-click / replays through $idempotencyKey.
     * @param array{subscription_id?:int, subscription_cycle?:int} $attach links the order to an auto-subscription cycle
     */
    public static function place(int $userId, int $serviceId, array $input, string $source = 'web', ?string $idempotencyKey = null, bool $submitNow = true, array $attach = []): array
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if (!$user || $user['status'] !== 'active') {
            throw new ValidationException('Your account is not active.');
        }
        $service = self::orderableService($serviceId, empty($attach['subscription_id']));
        if (!empty(self::TYPES[$service['type']]['subscription']) && empty($attach['subscription_id'])) {
            throw new ValidationException('"' . $service['name'] . '" can only be ordered as a subscription (choose it on the New order page).');
        }
        if (isset($attach['posts'])) {
            // Post-based subscription: validated by SubscriptionService::validatePosts(); one provider
            // order carries username/min/max/posts/old_posts/delay/expiry and the full prepaid reserve.
            $params = ['link' => (string) $attach['posts']['link'], 'quantity' => (int) $attach['posts']['quantity'], 'runs' => null, 'interval' => null, 'extra' => $attach['posts']['extra']];
            $rate = (string) $attach['posts']['rate'];
            $charge = (string) $attach['posts']['charge'];
        } else {
            $params = self::validateInput($service, $input);
            $rate = self::userRate($service, $user);
            $charge = self::computeCharge($service, $rate, $params['quantity'], $params['runs']);
        }
        PriceProtection::assertSellable($service, $rate);

        if (!Money::isPositive($charge)) {
            throw new ValidationException('This order is too small. Please increase the quantity.');
        }
        $minOrder = (string) setting('min_order_amount', '0');
        if (Money::isNumeric($minOrder) && Money::cmp($charge, $minOrder) < 0) {
            throw new ValidationException('The minimum order is ' . money($minOrder) . '. Please increase the quantity.');
        }

        if ($idempotencyKey !== null) {
            $idempotencyKey = substr(preg_replace('/[^A-Za-z0-9_\-:]/', '', $idempotencyKey), 0, 64) ?: null;
            if ($idempotencyKey && ($existing = $db->fetch('SELECT * FROM orders WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]))) {
                return $existing + ['duplicate' => true];
            }
        }

        $manual = empty($service['provider_id']) || empty($service['provider_service_id']);

        try {
            $orderId = $db->transaction(static function (Database $db) use ($userId, $service, $params, $rate, $charge, $source, $idempotencyKey, $manual, $attach): int {
                // Lock the wallet first so concurrent orders from the same user serialise.
                $bal = (string) $db->fetchColumn('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
                if (Money::cmp($bal, $charge) < 0) {
                    throw new ValidationException('Insufficient balance: this order costs ' . money($charge) . ' and you have ' . money($bal) . '. Add funds and try again.');
                }
                $orderId = $db->insert('orders', [
                    'user_id' => $userId,
                    'service_id' => (int) $service['id'],
                    'provider_id' => $manual ? null : (int) $service['provider_id'],
                    'link' => $params['link'],
                    'quantity' => $params['quantity'],
                    'extra' => $params['extra'] ? json_encode($params['extra'], JSON_UNESCAPED_UNICODE) : null,
                    'runs' => $params['runs'],
                    'interval' => $params['interval'],
                    'rate' => $rate,
                    'charge' => $charge,
                    'status' => 'pending',
                    'submit_state' => $manual ? 'manual' : 'queued',
                    'source' => $source,
                    'idempotency_key' => $idempotencyKey,
                    'subscription_id' => $attach['subscription_id'] ?? null,
                    'subscription_cycle' => $attach['subscription_cycle'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                WalletService::apply($userId, Money::negate($charge), 'order_charge', "order:{$orderId}:charge", "Order #{$orderId} — " . mb_substr($service['name'], 0, 150), ['order_id' => $orderId]);
                self::log($orderId, 'created', null, 'pending', "Charged {$charge}" . (isset($attach['subscription_id']) ? " (subscription #{$attach['subscription_id']}, cycle {$attach['subscription_cycle']})" : ''), match ($source) { 'api' => 'api', 'subscription' => 'subscription', default => 'user' });
                return $orderId;
            });
        } catch (\PDOException $e) {
            // Unique (user_id, idempotency_key) — a concurrent duplicate submission won the race.
            if ($idempotencyKey && str_contains($e->getMessage(), 'uq_order_idem')) {
                $existing = $db->fetch('SELECT * FROM orders WHERE user_id = ? AND idempotency_key = ?', [$userId, $idempotencyKey]);
                if ($existing) {
                    return $existing + ['duplicate' => true];
                }
            }
            throw $e;
        }

        if ($submitNow && !$manual) {
            self::submit($orderId);
        }
        return self::find($orderId);
    }

    public static function find(int $orderId): array
    {
        return Database::instance()->fetch('SELECT * FROM orders WHERE id = ?', [$orderId]) ?? [];
    }

    /**
     * Send a queued order to its provider. Claims the order atomically so the
     * web request and cron can never submit the same order twice.
     */
    public static function submit(int $orderId): string
    {
        $db = Database::instance();
        $claimed = $db->query(
            "UPDATE orders SET submit_state = 'submitting', submit_attempts = submit_attempts + 1, updated_at = ? WHERE id = ? AND submit_state = 'queued' AND status = 'pending'",
            [now(), $orderId]
        )->rowCount();
        if ($claimed !== 1) {
            return 'not_claimed';
        }
        $order = self::find($orderId);
        $service = $db->fetch('SELECT * FROM services WHERE id = ?', [$order['service_id']]);
        $provider = $order['provider_id'] ? $db->fetch('SELECT * FROM providers WHERE id = ?', [$order['provider_id']]) : null;

        if (!$provider || $provider['status'] !== 'active' || empty($service['provider_service_id'])) {
            // Provider disabled: leave queued for later instead of failing outright.
            $db->update('orders', ['submit_state' => 'queued', 'last_error' => 'Provider unavailable or disabled', 'updated_at' => now()], ['id' => $orderId]);
            return 'queued';
        }

        $params = ['service' => $service['provider_service_id'], 'link' => $order['link']];
        $def = self::TYPES[$service['type']] ?? self::TYPES['default'];
        $postsSub = $order['subscription_id'] && $db->fetchColumn("SELECT mode FROM subscriptions WHERE id = ?", [(int) $order['subscription_id']]) === 'posts';
        if ($postsSub) {
            // API v2 "Subscriptions": service, username, min, max, posts, old_posts, delay, expiry — no link/quantity.
            $params = ['service' => $service['provider_service_id']];
        } elseif ($def['quantity']) {
            $params['quantity'] = (int) $order['quantity'];
        }
        foreach (json_decode((string) $order['extra'], true) ?: [] as $k => $v) {
            $params[$k] = $v;
        }
        if ($order['runs']) {
            $params['runs'] = (int) $order['runs'];
            $params['interval'] = (int) $order['interval'];
        }

        try {
            $providerOrderId = ProviderFactory::make($provider)->addOrder($params);
        } catch (ProviderException $e) {
            return self::handleSubmitFailure($order, $e);
        } catch (\Throwable $e) {
            Logger::error('Unexpected submit error: ' . $e->getMessage(), ['order' => $orderId], 'provider');
            return self::handleSubmitFailure($order, new ProviderException($e->getMessage(), ProviderException::KIND_UNKNOWN));
        }

        $db->update('orders', [
            'provider_order_id' => $providerOrderId,
            'submit_state' => 'submitted',
            'status' => 'processing',
            'last_error' => null,
            'updated_at' => now(),
        ], ['id' => $orderId]);
        self::log($orderId, 'submitted', 'pending', 'processing', 'Provider order ID ' . $providerOrderId);
        return 'submitted';
    }

    private static function handleSubmitFailure(array $order, ProviderException $e): string
    {
        $db = Database::instance();
        $id = (int) $order['id'];
        $msg = mb_substr($e->getMessage(), 0, 480);

        if ($e->isDefinitiveRejection()) {
            $db->update('orders', ['submit_state' => 'failed', 'last_error' => $msg, 'updated_at' => now()], ['id' => $id]);
            self::refund($id, null, 'fail', 'failed', 'Provider rejected order: ' . $msg);
            self::closeSubscriptionOnFailure($order, 'Provider rejected the subscription: ' . $msg);
            NotificationService::notify((int) $order['user_id'], 'order', "Order #{$id} could not be placed", 'The provider rejected this order. The full amount has been refunded to your balance.', '/orders/' . $id);
            return 'failed';
        }
        if ($e->isSafeToRetry() && (int) $order['submit_attempts'] < self::MAX_SUBMIT_ATTEMPTS) {
            $db->update('orders', ['submit_state' => 'queued', 'last_error' => $msg, 'updated_at' => now()], ['id' => $id]);
            self::log($id, 'submit_retry', null, null, 'Provider unreachable, will retry: ' . $msg);
            return 'queued';
        }
        if ($e->isSafeToRetry()) {
            // Never reached the provider after many attempts — safe to refund.
            $db->update('orders', ['submit_state' => 'failed', 'last_error' => $msg, 'updated_at' => now()], ['id' => $id]);
            self::refund($id, null, 'fail', 'failed', 'Provider unreachable after retries: ' . $msg);
            self::closeSubscriptionOnFailure($order, 'Provider unreachable: ' . $msg);
            NotificationService::notify((int) $order['user_id'], 'order', "Order #{$id} failed", 'We could not reach the service provider. The full amount has been refunded.', '/orders/' . $id);
            return 'failed';
        }
        // Outcome unknown: the provider may have accepted it. Do not retry, do not refund — admin decides.
        $db->update('orders', ['submit_state' => 'unknown', 'needs_attention' => 1, 'last_error' => $msg, 'updated_at' => now()], ['id' => $id]);
        self::log($id, 'submit_unknown', null, null, 'Submission outcome unknown (needs admin review): ' . $msg);
        NotificationService::notifyAdmin("Order #{$id} needs review", '<p>The provider response for order #' . $id . ' was ambiguous (' . e($msg) . '). Check the provider panel, then mark it submitted (with provider order ID) or fail it.</p>');
        return 'unknown';
    }

    /** A post subscription whose only order failed is closed as failed (its reserve was refunded with the order). */
    private static function closeSubscriptionOnFailure(array $order, string $reason): void
    {
        if (!empty($order['subscription_id']) && Database::instance()->fetchColumn('SELECT mode FROM subscriptions WHERE id = ?', [(int) $order['subscription_id']]) === 'posts') {
            SubscriptionService::reconcile((int) $order['subscription_id'], 'failed', 'system', $reason);
        }
    }

    // ------------------------------------------------------------------
    // Refunds & status changes
    // ------------------------------------------------------------------

    /**
     * Refund (part of) an order exactly once per $kind.
     * $amount null = everything not yet refunded.
     */
    public static function refund(int $orderId, ?string $amount, string $kind, ?string $newStatus, string $reason, string $actor = 'system'): string
    {
        return Database::instance()->transaction(static function (Database $db) use ($orderId, $amount, $kind, $newStatus, $reason, $actor): string {
            $order = $db->fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                throw new \RuntimeException('Order not found');
            }
            $refundable = Money::sub((string) $order['charge'], (string) $order['refunded_amount']);
            $amount = $amount === null ? $refundable : Money::min(Money::of($amount), $refundable);
            $oldStatus = $order['status'];
            $update = ['updated_at' => now()];
            $done = '0.000000';

            if (Money::isPositive($amount)) {
                $res = WalletService::apply((int) $order['user_id'], $amount, 'refund', "order:{$orderId}:refund:{$kind}", "Refund for order #{$orderId}" . ($kind !== 'fail' ? " ({$kind})" : ''), ['order_id' => $orderId]);
                if (!$res['duplicate']) {
                    $update['refunded_amount'] = Money::add((string) $order['refunded_amount'], $amount);
                    $done = $amount;
                }
            }
            if ($newStatus !== null && $newStatus !== $oldStatus) {
                $update['status'] = $newStatus;
                if (in_array($newStatus, self::FINAL_STATUSES, true)) {
                    $update['completed_at'] = $order['completed_at'] ?? now();
                }
            }
            $db->update('orders', $update, ['id' => $orderId]);
            self::log($orderId, 'refund_' . $kind, $oldStatus, $newStatus ?? $oldStatus, $reason . ' — refunded ' . $done, $actor);
            return $done;
        });
    }

    /**
     * Apply a provider (or admin) status to an order. Handles partial/cancel refunds
     * and is idempotent: final orders are never re-processed.
     */
    public static function applyStatus(int $orderId, ProviderOrderStatus $ps, string $actor = 'provider'): void
    {
        $db = Database::instance();
        $notify = null;
        $db->transaction(static function (Database $db) use ($orderId, $ps, $actor, &$notify): void {
            $order = $db->fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order || in_array($order['status'], self::FINAL_STATUSES, true)) {
                if ($order) {
                    $db->update('orders', ['last_synced_at' => now()], ['id' => $orderId]);
                }
                return;
            }
            if ($order['subscription_id'] && $db->fetchColumn('SELECT mode FROM subscriptions WHERE id = ?', [(int) $order['subscription_id']]) === 'posts') {
                SubscriptionService::applyPostsStatus($order, $ps, $actor); // settles the reserve on final statuses
                return;
            }
            $update = ['last_synced_at' => now(), 'updated_at' => now()];
            if ($ps->startCount !== null) {
                $update['start_count'] = $ps->startCount;
            }
            if ($ps->remains !== null) {
                $update['remains'] = min($ps->remains, (int) $order['quantity'] * max(1, (int) $order['runs']));
            }
            if ($ps->charge !== null && $order['cost'] === null) {
                $update['cost'] = $ps->charge;
            }

            $new = $ps->status;
            if ($new === 'partial') {
                $total = (int) $order['quantity'] * max(1, (int) $order['runs']);
                $remains = (int) ($update['remains'] ?? $order['remains'] ?? 0);
                $db->update('orders', $update, ['id' => $orderId]);
                if ($remains > 0 && $total > 0) {
                    $refund = Money::divInt(Money::mul((string) $order['charge'], (string) $remains), $total);
                    self::refund($orderId, $refund, 'partial', 'partial', "Partial: {$remains} of {$total} not delivered", $actor);
                } else {
                    self::refund($orderId, '0', 'partial', 'partial', 'Partial with no remains reported', $actor);
                }
                $notify = ['Order #' . $orderId . ' partially completed', 'Undelivered quantity has been refunded to your balance.'];
                return;
            }
            if ($new === 'cancelled') {
                $db->update('orders', $update, ['id' => $orderId]);
                self::refund($orderId, null, 'cancel', 'cancelled', 'Cancelled by provider', $actor);
                $notify = ['Order #' . $orderId . ' was cancelled', 'The full amount has been refunded to your balance.'];
                return;
            }
            if ($new !== $order['status']) {
                $update['status'] = $new;
                if ($new === 'completed') {
                    $update['completed_at'] = now();
                    $update['remains'] = $update['remains'] ?? 0;
                    $notify = ['Order #' . $orderId . ' completed', 'Your order has been delivered.'];
                }
                self::log($orderId, 'status', $order['status'], $new, 'Status update', $actor);
            }
            $db->update('orders', $update, ['id' => $orderId]);
        });
        if ($notify) {
            $userId = (int) $db->fetchColumn('SELECT user_id FROM orders WHERE id = ?', [$orderId]);
            NotificationService::notify($userId, 'order', $notify[0], $notify[1], '/orders/' . $orderId);
        }
    }

    /**
     * Status changes an admin may make, following the normal order lifecycle.
     * Final statuses (completed, partial, cancelled, refunded, failed) are never
     * changed here; a completed order can only be refunded explicitly (adminRefund).
     * Money: partial → refunds the undelivered part, cancelled → refunds whatever is
     * not yet refunded; all other transitions move no money. Refunds use unique
     * ledger references per order and kind, so they can never run twice.
     */
    public const ADMIN_TRANSITIONS = [
        'pending' => ['processing', 'in_progress', 'completed', 'partial', 'cancelled'],
        'processing' => ['in_progress', 'completed', 'partial', 'cancelled'],
        'in_progress' => ['completed', 'partial', 'cancelled'],
    ];

    /** @return list<string> statuses this order may be moved to by an admin */
    public static function adminAllowedStatuses(array $order): array
    {
        return self::ADMIN_TRANSITIONS[$order['status']] ?? [];
    }

    /** Admin manual status change (manual services or corrections). Returns the amount refunded by this change. */
    public static function adminSetStatus(int $orderId, string $status, ?int $startCount, ?int $remains, int $adminId, string $reason = ''): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new ValidationException('Enter a reason for the status change (kept in the order log and audit log).');
        }
        $order = self::find($orderId);
        if (!$order) {
            throw new ValidationException('Order not found.');
        }
        if (in_array($order['status'], self::FINAL_STATUSES, true)) {
            throw new ValidationException('This order is already final (' . $order['status'] . ') and cannot be changed.' . ($order['status'] === 'completed' ? ' Use Refund to return the charge.' : ''));
        }
        if (!in_array($status, self::adminAllowedStatuses($order), true)) {
            throw new ValidationException("Changing an order from {$order['status']} to {$status} is not allowed.");
        }
        $total = (int) $order['quantity'] * max(1, (int) $order['runs']);
        if ($status === 'partial' && ($remains === null || $remains <= 0 || $remains >= $total)) {
            throw new ValidationException('For a partial order enter the undelivered quantity (between 1 and ' . max(1, $total - 1) . '). Use Cancelled when nothing was delivered.');
        }
        if ($remains !== null && ($remains < 0 || $remains > $total)) {
            throw new ValidationException('Remains must be between 0 and ' . $total . '.');
        }
        $refundedBefore = (string) $order['refunded_amount'];
        self::applyStatus($orderId, new ProviderOrderStatus($status, $startCount, $remains), 'admin:' . $adminId);
        $after = self::find($orderId);
        $refunded = Money::sub((string) $after['refunded_amount'], $refundedBefore);
        self::log($orderId, 'admin_status', $order['status'], $after['status'], 'Admin: ' . mb_substr($reason, 0, 400) . (Money::isPositive($refunded) ? ' — refunded ' . $refunded : ''), 'admin:' . $adminId);
        AuditService::log('order.status', 'order', $orderId, ['from' => $order['status'], 'to' => $after['status'], 'reason' => $reason, 'remains' => $remains, 'start_count' => $startCount, 'refunded' => $refunded]);
        return $refunded;
    }

    /** Admin full refund of whatever has not been refunded yet; marks order refunded. */
    public static function adminRefund(int $orderId, int $adminId, string $reason): string
    {
        $order = self::find($orderId);
        if (!$order) {
            throw new ValidationException('Order not found.');
        }
        if (in_array($order['status'], ['refunded', 'failed'], true)) {
            throw new ValidationException('Order is already refunded or failed.');
        }
        $amount = self::refund($orderId, null, 'admin', 'refunded', 'Admin refund: ' . $reason, 'admin:' . $adminId);
        Database::instance()->update('orders', ['needs_attention' => 0], ['id' => $orderId]);
        AuditService::log('order.refund', 'order', $orderId, ['amount' => $amount, 'reason' => $reason]);
        NotificationService::notify((int) $order['user_id'], 'order', "Order #{$orderId} refunded", 'The order amount has been refunded to your balance.', '/orders/' . $orderId);
        return $amount;
    }

    /** Resolve an order stuck in "unknown" after the admin checked the provider panel. */
    public static function adminResolveUnknown(int $orderId, string $action, ?string $providerOrderId, int $adminId): void
    {
        $db = Database::instance();
        $order = self::find($orderId);
        if (!$order || !in_array($order['submit_state'], ['unknown', 'submitting'], true)) {
            throw new ValidationException('Order is not awaiting resolution.');
        }
        if ($action === 'submitted') {
            $pid = trim((string) $providerOrderId);
            if ($pid === '' || strlen($pid) > 64) {
                throw new ValidationException('Enter the provider order ID.');
            }
            $db->update('orders', ['provider_order_id' => $pid, 'submit_state' => 'submitted', 'status' => 'processing', 'needs_attention' => 0, 'updated_at' => now()], ['id' => $orderId]);
            self::log($orderId, 'resolved', 'pending', 'processing', 'Admin confirmed provider order ' . $pid, 'admin:' . $adminId);
        } elseif ($action === 'retry') {
            $db->update('orders', ['submit_state' => 'queued', 'needs_attention' => 0, 'updated_at' => now()], ['id' => $orderId]);
            self::log($orderId, 'resolved', null, null, 'Admin confirmed not received by provider; re-queued', 'admin:' . $adminId);
            self::submit($orderId);
        } elseif ($action === 'fail') {
            $db->update('orders', ['submit_state' => 'failed', 'needs_attention' => 0, 'updated_at' => now()], ['id' => $orderId]);
            self::refund($orderId, null, 'fail', 'failed', 'Admin marked submission failed', 'admin:' . $adminId);
        } else {
            throw new ValidationException('Unknown action.');
        }
        AuditService::log('order.resolve', 'order', $orderId, ['action' => $action, 'provider_order_id' => $providerOrderId]);
    }

    // ------------------------------------------------------------------
    // Cancel & refill (user initiated)
    // ------------------------------------------------------------------

    public static function requestCancel(int $userId, int $orderId): string
    {
        if (setting('order_cancel_enabled', '1') !== '1') {
            throw new ValidationException('Order cancellation is disabled.');
        }
        $db = Database::instance();
        $order = $db->fetch('SELECT o.*, s.cancel AS svc_cancel FROM orders o JOIN services s ON s.id = o.service_id WHERE o.id = ? AND o.user_id = ?', [$orderId, $userId]);
        if (!$order) {
            throw new ValidationException('Order not found.');
        }
        if (!in_array($order['status'], self::ACTIVE_STATUSES, true)) {
            throw new ValidationException('Only active orders can be cancelled.');
        }
        // Not yet sent to a provider: cancel locally (atomic claim prevents a race with cron submit).
        if (in_array($order['submit_state'], ['queued', 'manual'], true)) {
            $claimed = $db->query("UPDATE orders SET submit_state = 'failed', updated_at = ? WHERE id = ? AND submit_state IN ('queued','manual') AND status = 'pending'", [now(), $orderId])->rowCount();
            if ($claimed === 1) {
                self::refund($orderId, null, 'cancel', 'cancelled', 'Cancelled by user before submission', 'user');
                return 'cancelled';
            }
            throw new ValidationException('This order is being processed and can no longer be cancelled locally. Please try again in a minute.');
        }
        if ((int) $order['svc_cancel'] !== 1) {
            throw new ValidationException('This service does not support cancellation.');
        }
        if ((int) $order['cancel_requested'] === 1) {
            throw new ValidationException('A cancellation has already been requested for this order.');
        }
        if ($order['submit_state'] !== 'submitted' || !$order['provider_order_id']) {
            throw new ValidationException('This order cannot be cancelled right now.');
        }
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$order['provider_id']]);
        try {
            $result = ProviderFactory::make($provider)->cancel([$order['provider_order_id']]);
        } catch (ProviderException $e) {
            throw new ValidationException('The provider could not process the cancellation right now. Please try again later.');
        }
        $r = $result[(string) $order['provider_order_id']] ?? 'No response';
        if ($r !== true) {
            self::log($orderId, 'cancel_rejected', null, null, 'Provider: ' . $r, 'user');
            throw new ValidationException('Cancellation was not accepted: ' . mb_substr((string) $r, 0, 150));
        }
        $db->update('orders', ['cancel_requested' => 1, 'updated_at' => now()], ['id' => $orderId]);
        self::log($orderId, 'cancel_requested', null, null, 'Cancellation accepted by provider; refund follows on status sync', 'user');
        return 'requested';
    }

    public static function requestRefill(int $userId, int $orderId): array
    {
        if (setting('refill_enabled', '1') !== '1') {
            throw new ValidationException('Refills are currently disabled.');
        }
        $db = Database::instance();
        $order = $db->fetch('SELECT o.*, s.refill AS svc_refill, s.refill_days FROM orders o JOIN services s ON s.id = o.service_id WHERE o.id = ? AND o.user_id = ?', [$orderId, $userId]);
        if (!$order) {
            throw new ValidationException('Order not found.');
        }
        if ((int) $order['svc_refill'] !== 1) {
            throw new ValidationException('This service does not offer refills.');
        }
        if (!in_array($order['status'], ['completed', 'partial'], true)) {
            throw new ValidationException('Refills are available for completed orders only.');
        }
        $completedAt = strtotime(($order['completed_at'] ?? $order['updated_at']) . ' UTC');
        if ($completedAt && time() - $completedAt > (int) $order['refill_days'] * 86400) {
            throw new ValidationException('The refill period for this order has ended.');
        }
        $recent = $db->fetch("SELECT id, status, created_at FROM refills WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$orderId]);
        if ($recent && (in_array($recent['status'], ['pending', 'processing'], true) || strtotime($recent['created_at'] . ' UTC') > time() - 86400)) {
            throw new ValidationException('A refill was already requested for this order in the last 24 hours.');
        }

        $refillId = $db->insert('refills', ['order_id' => $orderId, 'user_id' => $userId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($order['provider_id'] && $order['provider_order_id']) {
            $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$order['provider_id']]);
            try {
                $pid = ProviderFactory::make($provider)->refill((string) $order['provider_order_id']);
                $db->update('refills', ['provider_refill_id' => $pid, 'status' => 'processing', 'updated_at' => now()], ['id' => $refillId]);
            } catch (ProviderException $e) {
                if ($e->isDefinitiveRejection()) {
                    $db->update('refills', ['status' => 'rejected', 'message' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => now()], ['id' => $refillId]);
                    throw new ValidationException('Refill was rejected by the provider: ' . mb_substr(preg_replace('/^Provider error: /', '', $e->getMessage()), 0, 150));
                }
                $db->update('refills', ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => now()], ['id' => $refillId]);
                throw new ValidationException('The provider could not be reached. Please try again later.');
            }
        }
        self::log($orderId, 'refill_requested', null, null, 'Refill #' . $refillId, 'user');
        return $db->fetch('SELECT * FROM refills WHERE id = ?', [$refillId]);
    }

    // ------------------------------------------------------------------
    // Mass order
    // ------------------------------------------------------------------

    /**
     * Each line: service_id | link | quantity   (separators: | or ,)
     * @return list<array{line:int, ok:bool, message:string, order_id?:int}>
     */
    public static function placeMass(int $userId, string $text, string $batchKey): array
    {
        if (setting('mass_order_enabled', '1') !== '1') {
            throw new ValidationException('Mass order is disabled.');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), static fn ($l) => $l !== ''));
        $max = (int) setting('mass_order_max_lines', '100');
        if (!$lines) {
            throw new ValidationException('Enter at least one order line.');
        }
        if (count($lines) > $max) {
            throw new ValidationException("You can submit up to {$max} lines at once.");
        }
        $results = [];
        foreach ($lines as $i => $line) {
            $parts = array_map('trim', preg_split('/\s*[|]\s*/', $line));
            if (count($parts) < 3) {
                $parts = array_map('trim', explode(',', $line));
            }
            if (count($parts) < 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[2])) {
                $results[] = ['line' => $i + 1, 'ok' => false, 'message' => 'Invalid format. Use: service_id | link | quantity'];
                continue;
            }
            try {
                $order = self::place($userId, (int) $parts[0], ['link' => $parts[1], 'quantity' => $parts[2]], 'mass', substr($batchKey, 0, 50) . ':' . ($i + 1), false);
                $results[] = ['line' => $i + 1, 'ok' => true, 'message' => 'Order #' . $order['id'] . ' created (' . money($order['charge']) . ')', 'order_id' => (int) $order['id']];
            } catch (ValidationException $e) {
                $results[] = ['line' => $i + 1, 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        // Submit after charging so one slow provider call does not hold up validation of later lines.
        foreach ($results as $r) {
            if (!empty($r['order_id'])) {
                self::submit($r['order_id']);
            }
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function log(int $orderId, string $event, ?string $old, ?string $new, ?string $message = null, string $actor = 'system'): void
    {
        Database::instance()->insert('order_logs', [
            'order_id' => $orderId,
            'event' => $event,
            'old_status' => $old,
            'new_status' => $new,
            'message' => $message !== null ? mb_substr($message, 0, 1000) : null,
            'actor' => mb_substr($actor, 0, 40),
            'created_at' => now(),
        ]);
    }

    public static function userStats(int $userId): array
    {
        $rows = Database::instance()->fetchPairs('SELECT status, COUNT(*) FROM orders WHERE user_id = ? GROUP BY status', [$userId]);
        $total = array_sum($rows);
        return [
            'total' => $total,
            'completed' => (int) ($rows['completed'] ?? 0),
            'pending' => (int) ($rows['pending'] ?? 0),
            'processing' => (int) ($rows['processing'] ?? 0) + (int) ($rows['in_progress'] ?? 0),
            'partial' => (int) ($rows['partial'] ?? 0),
            'cancelled' => (int) ($rows['cancelled'] ?? 0) + (int) ($rows['refunded'] ?? 0) + (int) ($rows['failed'] ?? 0),
        ];
    }
}
