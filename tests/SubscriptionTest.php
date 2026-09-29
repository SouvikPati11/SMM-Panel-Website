<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Services\CronService;
use App\Services\SubscriptionService;

/*
 * Auto-subscriptions: every delivery is a normal order through OrderService,
 * processed by the "subscriptions" cron task with a claim + per-cycle idempotency.
 */

$subProvider = Fx::provider();
$subService = Fx::service($subProvider, ['name' => 'Instagram Likes [Auto]', 'subscription_enabled' => 1]); // rate 2.50 / 1000
$noSubService = Fx::service($subProvider, ['name' => 'Instagram Followers [No subs]']);
$providerCalls = 0;
$subFake = static function (?callable $handler = null) use (&$providerCalls): void {
    $providerCalls = 0;
    Fx::http(['https://provider.test/' => static function ($m, $u, $o) use ($handler, &$providerCalls) {
        $providerCalls++;
        return $handler ? $handler($o['form'] ?? []) : Fx::json(['order' => 5000 + $providerCalls]);
    }]);
};
$due = static fn (int $id) => Database::instance()->query('UPDATE subscriptions SET next_run_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $id]);
$orders = static fn (int $id) => Database::instance()->fetchAll('SELECT * FROM orders WHERE subscription_id = ? ORDER BY subscription_cycle', [$id]);

T::test('Subscription: creation places and charges delivery 1 atomically; later deliveries are scheduled', function () use ($subService, $subFake, $orders) {
    $subFake();
    $u = Fx::user('10');
    $sub = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/abc', 'quantity' => '1000'], 24, 3, 'k1');
    T::eq('active', $sub['status']);
    T::eq(1, (int) $sub['completed_cycles']);
    T::eq(3, (int) $sub['total_cycles']);
    $o = $orders((int) $sub['id']);
    T::eq(1, count($o));
    T::eq('subscription', $o[0]['source']);
    T::eq(1, (int) $o[0]['subscription_cycle']);
    T::eq('processing', $o[0]['status'], 'submitted to the provider like any order');
    T::eq('7.500000', Fx::balance((int) $u['id']), 'only the first delivery is charged');
    $next = strtotime($sub['next_run_at'] . ' UTC') - time();
    T::true($next > 23 * 3600 && $next <= 24 * 3600, 'next delivery in 24h');
    // Same form submitted twice → same subscription, no second charge.
    $again = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/abc', 'quantity' => '1000'], 24, 3, 'k1');
    T::eq((int) $sub['id'], (int) $again['id']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
});

T::test('Subscription: validation — service must allow it, interval/cycles bounded, balance for delivery 1 required', function () use ($subService, $noSubService, $subFake) {
    $subFake();
    $u = Fx::user('10');
    $in = ['link' => 'https://instagram.com/p/x', 'quantity' => '1000'];
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $noSubService, $in, 24, 3), 'does not support');
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $subService, $in, 5, 3), 'repeat');
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $subService, $in, 24, 1), 'between 2');
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $subService, $in, 24, 5000), 'between 2');
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $subService, ['link' => 'not a url', 'quantity' => '1000'], 24, 3), 'valid URL');
    $poor = Fx::user('1');
    T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $poor['id'], $subService, $in, 24, 3), 'Insufficient balance');
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE user_id = ?', [$poor['id']]), 'nothing created when delivery 1 cannot be charged');
    T::eq('1.000000', Fx::balance((int) $poor['id']));
    App\Services\SettingsService::set('subscriptions_enabled', '0');
    try {
        T::throws(ValidationException::class, fn () => SubscriptionService::create((int) $u['id'], $subService, $in, 24, 3), 'disabled');
    } finally {
        App\Services\SettingsService::set('subscriptions_enabled', '1');
    }
});

T::test('Subscription: cron processes due deliveries, completes after the last one, never early', function () use ($subService, $subFake, $due, $orders) {
    $subFake();
    $u = Fx::user('10');
    $sub = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/c', 'quantity' => '1000'], 1, 3);
    $id = (int) $sub['id'];
    T::eq(0, SubscriptionService::processDue()['ordered'], 'not due yet');
    $due($id);
    $r = CronService::run('subscriptions');
    T::eq('success', $r['status']);
    T::eq(1, $r['output']['ordered']);
    T::eq(2, (int) SubscriptionService::find($id)['completed_cycles']);
    $due($id);
    T::eq(1, SubscriptionService::processDue()['completed']);
    $s = SubscriptionService::find($id);
    T::eq('completed', $s['status']);
    T::eq(3, (int) $s['completed_cycles']);
    T::true($s['completed_at'] !== null && $s['next_run_at'] === null);
    T::eq([1, 2, 3], array_map(static fn ($o) => (int) $o['subscription_cycle'], $orders($id)));
    T::eq('2.500000', Fx::balance((int) $u['id']), '3 × 2.50 charged, each at its own delivery');
    $due($id);
    T::eq(0, SubscriptionService::processDue()['due'], 'completed subscriptions never run again');
});

T::test('Subscription: duplicate processing is impossible (concurrent claim, expired claim, repeated cron)', function () use ($subService, $subFake, $due, $orders) {
    $subFake();
    $u = Fx::user('20');
    $sub = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/d', 'quantity' => '1000'], 1, 5);
    $id = (int) $sub['id'];
    $due($id);
    // Another worker holds the claim → skipped.
    Database::instance()->query('UPDATE subscriptions SET locked_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 300), $id]);
    T::eq('skipped', SubscriptionService::runCycle($id));
    T::eq(1, count($orders($id)));
    // The other worker placed the order for cycle 2 but died before advancing; its claim expired.
    $o = App\Services\OrderService::place((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/d', 'quantity' => '1000'], 'subscription', "sub{$id}c2", false, ['subscription_id' => $id, 'subscription_cycle' => 2]);
    Database::instance()->query('UPDATE subscriptions SET locked_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $id]);
    $balance = Fx::balance((int) $u['id']);
    T::eq('ordered', SubscriptionService::runCycle($id));
    T::eq(2, count($orders($id)), 'cycle 2 not ordered twice (idempotency key sub{id}c2)');
    T::eq((int) $o['id'], (int) SubscriptionService::find($id)['last_order_id']);
    T::eq($balance, Fx::balance((int) $u['id']), 'not charged twice');
    // Running the cron again immediately does nothing.
    T::eq('skipped', SubscriptionService::runCycle($id));
    T::eq(2, count($orders($id)));
});

T::test('Subscription: concurrent cron processes race on one due subscription → exactly one order', function () use ($subService, $subFake, $due, $orders) {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped: pcntl not available)\n";
        return;
    }
    $subFake();
    $u = Fx::user('20');
    $sub = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/race', 'quantity' => '1000'], 1, 5);
    $id = (int) $sub['id'];
    $due($id);
    $pids = [];
    for ($i = 0; $i < 4; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            Database::setInstance(null);
            try {
                SubscriptionService::processDue();
            } catch (\Throwable) {
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $st);
    }
    Database::setInstance(null);
    T::eq(2, count($orders($id)), 'one new order for cycle 2');
    T::eq(2, (int) SubscriptionService::find($id)['completed_cycles']);
    T::eq('15.000000', Fx::balance((int) $u['id']));
});

T::test('Subscription: failures retry with back-off, are logged, then suspend; resume continues', function () use ($subService, $subFake, $due, $orders) {
    $subFake();
    $u = Fx::user('3');
    $sub = SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/f', 'quantity' => '1000'], 1, 3);
    $id = (int) $sub['id'];
    // Balance 0.50 < 2.50 per delivery.
    foreach (SubscriptionService::RETRY_MINUTES as $i => $minutes) {
        $due($id);
        T::eq('retry', SubscriptionService::runCycle($id), 'attempt ' . ($i + 1));
        $s = SubscriptionService::find($id);
        T::eq($i + 1, (int) $s['attempts']);
        T::true(str_contains((string) $s['last_error'], 'Insufficient balance'));
        $wait = strtotime($s['next_run_at'] . ' UTC') - time();
        T::true($wait > ($minutes - 1) * 60 && $wait <= $minutes * 60, "back-off {$minutes} min");
    }
    $due($id);
    T::eq('suspended', SubscriptionService::runCycle($id));
    T::eq('suspended', SubscriptionService::find($id)['status']);
    T::eq(1, count($orders($id)), 'no order and no charge while failing');
    T::eq('0.500000', Fx::balance((int) $u['id']));
    $events = array_column(Database::instance()->fetchAll('SELECT event FROM subscription_logs WHERE subscription_id = ?', [$id]), 'event');
    T::eq(count(SubscriptionService::RETRY_MINUTES), count(array_keys($events, 'cycle_failed')));
    T::true(in_array('suspended', $events, true));
    $due($id);
    T::eq(0, SubscriptionService::processDue()['due'], 'suspended subscriptions are not processed');
    // User tops up and resumes.
    App\Services\WalletService::apply((int) $u['id'], '10', 'deposit', 'fixture:topup:' . $id, 'top up');
    SubscriptionService::changeStatus($id, 'resume', 'user', (int) $u['id']);
    $s = SubscriptionService::find($id);
    T::eq(['active', 0, null], [$s['status'], (int) $s['attempts'], $s['last_error']]);
    T::eq('ordered', SubscriptionService::runCycle($id));
    T::eq(2, count($orders($id)));
});

T::test('Subscription: pause/resume/cancel lifecycle, ownership and invalid transitions', function () use ($subService, $subFake, $due) {
    $subFake();
    $u = Fx::user('20');
    $other = Fx::user('20');
    $id = (int) SubscriptionService::create((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/l', 'quantity' => '1000'], 1, 5)['id'];
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus($id, 'pause', 'user', (int) $other['id']), 'not found');
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus($id, 'resume', 'user', (int) $u['id']), 'cannot be resumed');
    SubscriptionService::changeStatus($id, 'pause', 'user', (int) $u['id']);
    $due($id);
    T::eq('skipped', SubscriptionService::runCycle($id), 'paused subscriptions do not run');
    SubscriptionService::changeStatus($id, 'resume', 'user', (int) $u['id']);
    SubscriptionService::changeStatus($id, 'cancel', 'user', (int) $u['id']);
    T::eq('cancelled', SubscriptionService::find($id)['status']);
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus($id, 'resume', 'user', (int) $u['id']), 'cannot be resumed');
    $due($id);
    T::eq('skipped', SubscriptionService::runCycle($id));
});

T::test('Subscription: normal one-time orders are unaffected', function () use ($subService, $subFake) {
    $subFake();
    $u = Fx::user('10');
    $o = App\Services\OrderService::place((int) $u['id'], $subService, ['link' => 'https://instagram.com/p/one', 'quantity' => '1000'], 'web', 'single-1');
    T::eq(null, $o['subscription_id']);
    T::eq('web', $o['source']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
});

T::test('Subscription: HTTP — quote, create via confirm (JSON), user pages scoped, admin manage audited', function () use ($subService, $subFake) {
    $subFake();
    $u = Fx::user('10');
    $other = Fx::user('10');
    login_as_user($u);
    $form = ['_token' => csrf(), 'service' => (string) $subService, 'link' => 'https://instagram.com/p/h', 'quantity' => '1000', 'order_type' => 'subscription', 'sub_interval' => '24', 'sub_cycles' => '4', 'form_key' => 'fk-sub-1'];
    $json = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    $q = json_decode(http('POST', '/order/quote', $form, $json)->body(), true);
    T::eq(true, $q['ok']);
    T::eq(4, $q['subscription']['cycles']);
    T::true(str_contains($q['subscription']['estimated_total'], '10.00'), 'estimated total 4 × 2.50');
    T::eq('10.000000', Fx::balance((int) $u['id']), 'quote charges nothing');
    $r = json_decode(http('POST', '/order', $form, $json)->body(), true);
    T::eq('subscription', $r['type']);
    $id = (int) $r['subscription_id'];
    T::eq('7.500000', Fx::balance((int) $u['id']));
    $again = json_decode(http('POST', '/order', $form, $json)->body(), true);
    T::eq($id, (int) $again['subscription_id'], 'double submit → same subscription');
    T::eq('7.500000', Fx::balance((int) $u['id']));
    T::true(str_contains(http('GET', '/subscriptions/' . $id)->body(), 'Subscription #' . $id));
    T::true(str_contains(http('GET', '/orders', ['type' => 'subscription'])->body(), 'Subscription #' . $id));
    login_as_user($other);
    T::throws(HttpException::class, fn () => http('GET', '/subscriptions/' . $id));
    // Admin
    $db = Database::instance();
    $adminId = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'subadmin', 'email' => 'subadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($adminId);
    T::true(str_contains(http('GET', '/' . admin_path() . '/subscriptions')->body(), '#' . $id));
    T::true(str_contains(http('GET', '/' . admin_path() . '/subscriptions/' . $id)->body(), 'Deliveries'));
    http('POST', '/' . admin_path() . '/subscriptions/' . $id . '/action', ['_token' => csrf(), 'action' => 'pause', 'reason' => '']);
    T::eq('active', SubscriptionService::find($id)['status'], 'reason required');
    http('POST', '/' . admin_path() . '/subscriptions/' . $id . '/action', ['_token' => csrf(), 'action' => 'pause', 'reason' => 'Customer asked by ticket']);
    T::eq('paused', SubscriptionService::find($id)['status']);
    T::eq(1, (int) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'subscription.pause' AND target_id = ?", [$id]));
});
