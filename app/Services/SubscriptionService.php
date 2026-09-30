<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;

/**
 * Auto-subscriptions: the same order repeated every N hours for M cycles.
 *
 * Built on the existing order engine instead of a parallel one: every cycle is
 * a normal order placed with OrderService::place() (price, discount, balance
 * check, ledger, provider submission, status sync and refunds all unchanged).
 * Provider-native "Subscriptions" services (username/min/max/posts/delay/expiry,
 * billed per new post) are NOT used: the standard API v2 contract this panel
 * implements documents no per-post billing or subscription status format.
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
    public const STATUSES = ['active', 'paused', 'completed', 'cancelled', 'suspended'];
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
        $min = !empty($service['subscription_min_cycles']) ? max(2, min($max, (int) $service['subscription_min_cycles'])) : 2;
        return ['allowed' => $allowed, 'only' => $only, 'intervals' => $intervals, 'min' => $min, 'max' => $max];
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
            return $out + ['disabled' => true];
        }
        $ids = Database::instance()->fetchAll(
            "SELECT id FROM subscriptions WHERE status = 'active' AND next_run_at <= ? AND (locked_until IS NULL OR locked_until < ?) ORDER BY next_run_at ASC LIMIT " . max(1, $limit),
            [now(), now()]
        );
        foreach ($ids as $row) {
            $out['due']++;
            $r = self::runCycle((int) $row['id'], 'cron');
            $out[$r] = ($out[$r] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Place the next cycle's order for one subscription.
     * @return string ordered|completed|retry|suspended|skipped
     */
    public static function runCycle(int $subId, string $actor = 'cron'): string
    {
        $db = Database::instance();
        $claimed = $db->query(
            "UPDATE subscriptions SET locked_until = ?, updated_at = ? WHERE id = ? AND status = 'active' AND next_run_at <= ? AND completed_cycles < total_cycles AND (locked_until IS NULL OR locked_until < ?)",
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
    public static function changeStatus(int $subId, string $action, string $actor, ?int $ownerId = null, string $reason = ''): array
    {
        $db = Database::instance();
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
