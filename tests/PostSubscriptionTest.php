<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\CronService;
use App\Services\ProviderSyncService;
use App\Services\SubscriptionService;

/*
 * Post-based subscriptions (username / new & old posts / min-max / delay /
 * expiry): admin config → validation → reserve charge → provider "add" →
 * status sync → settlement (refund exactly once) → expiry/cancel/failure.
 */

$pdb = Database::instance();
$psProvider = Fx::provider();
$psAdmin = (int) $pdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $pdb->insert('admins', ['username' => 'psadmin', 'email' => 'psadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$postsService = static function (array $extra = []) use ($psProvider): int {
    return Fx::service($extra['provider_id'] ?? $psProvider, $extra + [
        'name' => 'Instagram Auto Likes [posts]', 'type' => 'subscription', 'subscription_mode' => 'posts', 'subscription_enabled' => 1,
        'rate' => '2.000000', 'provider_rate' => '1.000000', 'min_quantity' => 100, 'max_quantity' => 1000,
        'subscription_min_cycles' => 1, 'subscription_max_cycles' => 20, 'subscription_old_posts_max' => 5,
        'subscription_delays' => '0,15,60', 'subscription_max_expiry_days' => 30, 'provider_service_id' => '900', 'cancel' => 1,
    ]);
};
$in = static fn (array $o = []) => $o + ['username' => 'nasa', 'posts' => '5', 'old_posts' => '2', 'min' => '100', 'max' => '200', 'delay' => '15', 'expiry' => ''];
$calls = [];
$fake = static function (array $statusBody = null, ?callable $onCancel = null) use (&$calls): void {
    $calls = [];
    Fx::http(['https://provider.test/' => static function ($m, $u, $o) use (&$calls, $statusBody, $onCancel) {
        $calls[] = $o['form'];
        return match ($o['form']['action'] ?? '') {
            'add' => Fx::json(['order' => 77001 + count($calls)]),
            'status' => isset($o['form']['orders'])
                ? Fx::json(array_fill_keys(explode(',', $o['form']['orders']), $statusBody ?? ['status' => 'Active', 'posts' => '0']))
                : Fx::json($statusBody ?? ['status' => 'Active', 'posts' => '0']),
            'cancel' => $onCancel ? $onCancel($o['form']) : Fx::json([['order' => (int) $o['form']['orders'], 'cancel' => 1]]),
            default => Fx::json(['error' => 'unexpected']),
        };
    }]);
};
$sync = static fn () => ProviderSyncService::syncOrderStatuses(100);
$ledger = static fn (int $userId, string $type) => (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = ?', [$userId, $type]);

T::test('Post subscription: admin configures a Subscriptions service (post-based) through the service form', function () use ($psAdmin, $pdb, $psProvider) {
    login_as_admin($psAdmin);
    $cat = (int) $pdb->fetchColumn('SELECT category_id FROM services WHERE id = ?', [Fx::service(null, ['name' => 'cat seed'])]);
    http('POST', '/' . admin_path() . '/services/save', ['_token' => csrf(), 'category_id' => $cat, 'name' => 'IG Auto Views [admin form]', 'type' => 'subscription', 'subscription_mode' => 'posts',
        'rate' => '1.5', 'min_quantity' => '50', 'max_quantity' => '5000', 'provider_id' => $psProvider, 'provider_service_id' => '321', 'status' => 'active', 'link_type' => 'text', 'refill_days' => '30', 'sort_order' => '0',
        'subscription_min_cycles' => '1', 'subscription_max_cycles' => '50', 'subscription_old_posts_max' => '10', 'subscription_max_expiry_days' => '60', 'subscription_delays' => ['0', '30', '60']]);
    $s = $pdb->fetch("SELECT * FROM services WHERE name = 'IG Auto Views [admin form]'");
    T::eq(['subscription', 'posts', 1, 50, 10, 60, '0,30,60', 1], [$s['type'], $s['subscription_mode'], (int) $s['subscription_min_cycles'], (int) $s['subscription_max_cycles'], (int) $s['subscription_old_posts_max'], (int) $s['subscription_max_expiry_days'], $s['subscription_delays'], (int) $s['subscription_enabled']]);
    // A post-based service needs at least one allowed delay; bad limits are refused.
    http('POST', '/' . admin_path() . '/services/save', ['_token' => csrf(), 'id' => $s['id'], 'category_id' => $cat, 'name' => 'x', 'type' => 'subscription', 'subscription_mode' => 'posts', 'rate' => '1', 'min_quantity' => '50', 'max_quantity' => '5000', 'subscription_delays' => []]);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'at least one allowed delay'));
    http('POST', '/' . admin_path() . '/services/save', ['_token' => csrf(), 'id' => $s['id'], 'category_id' => $cat, 'name' => 'x', 'type' => 'subscription', 'subscription_mode' => 'posts', 'rate' => '1', 'min_quantity' => '50', 'max_quantity' => '5000', 'subscription_delays' => ['0'], 'subscription_max_expiry_days' => '0']);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'Maximum expiry'));
    $html = http('GET', '/' . admin_path() . '/services/' . $s['id'] . '/edit')->body();
    T::true(str_contains($html, 'Post-based') && str_contains($html, 'Allowed delays') && str_contains($html, 'Maximum old posts'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Post subscription: every limit is validated server-side (username, posts, old posts, min/max, delay, expiry)', function () use ($postsService, $in, $fake) {
    $fake();
    $svc = $postsService();
    $u = Fx::user('100');
    $bad = [
        [['username' => ''], 'Enter the username'],
        [['username' => 'two words'], 'must not contain spaces'],
        [['username' => '<script>'], 'must not contain spaces'],
        [['posts' => '0'], 'New posts must be between 1 and 20'],
        [['posts' => '21'], 'New posts must be between 1 and 20'],
        [['posts' => '2.5'], 'whole number'],
        [['old_posts' => '6'], 'Old posts must be between 0 and 5'],
        [['min' => '99'], 'between 100 and 1,000'],
        [['max' => '1001'], 'between 100 and 1,000'],
        [['min' => '300', 'max' => '200'], 'minimum cannot exceed the maximum'],
        [['delay' => '30'], 'offered delays'],
        [['delay' => '-1'], 'offered delays'],
        [['expiry' => gmdate('Y-m-d', time() - 86400)], 'must be in the future'],
        [['expiry' => gmdate('Y-m-d', time() + 40 * 86400)], 'at most 30 days'],
        [['expiry' => 'soon'], 'as a date'],
    ];
    foreach ($bad as [$override, $msg]) {
        T::throws(ValidationException::class, fn () => SubscriptionService::createPosts((int) $u['id'], $svc, $in($override)), $msg);
    }
    T::eq('100.000000', Fx::balance((int) $u['id']), 'nothing charged for invalid input');
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE user_id = ?', [$u['id']]));
    // A service whose old-posts limit is 0 refuses old posts; a normal service refuses post subscriptions.
    $noOld = $postsService(['subscription_old_posts_max' => 0, 'name' => 'no old posts']);
    T::throws(ValidationException::class, fn () => SubscriptionService::createPosts((int) $u['id'], $noOld, $in()), 'does not deliver to old posts');
    T::throws(ValidationException::class, fn () => SubscriptionService::createPosts((int) $u['id'], Fx::service(null, ['name' => 'plain']), $in()), 'does not take post-based subscriptions');
    // Subscriptions switched off globally.
    App\Services\SettingsService::set('subscriptions_enabled', '0');
    T::throws(ValidationException::class, fn () => SubscriptionService::createPosts((int) $u['id'], $svc, $in()), 'currently disabled');
    App\Services\SettingsService::set('subscriptions_enabled', '1');
    // Insufficient balance: nothing created.
    $poor = Fx::user('1');
    T::throws(ValidationException::class, fn () => SubscriptionService::createPosts((int) $poor['id'], $svc, $in()), 'Insufficient balance');
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE user_id = ?', [$poor['id']]));
});

T::test('Post subscription: creation reserves max × (new + old posts) × rate and sends a standard API v2 subscription add', function () use ($postsService, $in, $fake, &$calls, $pdb) {
    $fake();
    $svc = $postsService();
    $u = Fx::user('10');
    $exp = (new DateTimeImmutable('today', display_tz()))->modify('+10 days')->format('Y-m-d');
    $sub = SubscriptionService::createPosts((int) $u['id'], $svc, $in(['expiry' => $exp, '@' => 1]), 'k-posts-1');
    T::eq(['posts', 'active', 5, 2, 200, 100, 15, '2.800000'], [$sub['mode'], $sub['status'], (int) $sub['total_cycles'], (int) $sub['old_posts'], (int) $sub['quantity'], (int) $sub['qty_min'], (int) $sub['delay_minutes'], $sub['prepaid']]);
    T::eq('7.200000', Fx::balance((int) $u['id']), '200 × 7 posts × 2.00 / 1000 = 2.80 reserved');
    $add = $calls[0];
    T::eq(['add', '900', 'nasa', 100, 200, 5, 2, 15], [$add['action'], $add['service'], $add['username'], (int) $add['min'], (int) $add['max'], (int) $add['posts'], (int) $add['old_posts'], (int) $add['delay']]);
    T::eq((new DateTimeImmutable($exp))->format('d/m/Y'), $add['expiry'], 'expiry in the API v2 d/m/Y format');
    T::true(!isset($add['link']) && !isset($add['quantity']), 'no link/quantity for provider subscriptions');
    $order = $pdb->fetch('SELECT * FROM orders WHERE subscription_id = ?', [$sub['id']]);
    T::eq(['processing', 'subscription', '2.800000', 'nasa'], [$order['status'], $order['source'], $order['charge'], $order['link']]);
    // Same form key again: no second subscription or charge.
    $again = SubscriptionService::createPosts((int) $u['id'], $svc, $in(['expiry' => $exp]), 'k-posts-1');
    T::eq((int) $sub['id'], (int) $again['id']);
    T::eq('7.200000', Fx::balance((int) $u['id']));
    T::eq(1, count(array_filter($calls, static fn ($c) => $c['action'] === 'add')));
    // "@nasa" is normalised to "nasa"; no expiry given → default = the longest allowed period.
    $sub2 = SubscriptionService::createPosts((int) $u['id'], $svc, $in(['username' => '@nasa', 'posts' => '1', 'old_posts' => '0']));
    T::eq('nasa', $sub2['link']);
    T::true(str_starts_with($sub2['expires_at'], (new DateTimeImmutable('today', display_tz()))->modify('+30 days')->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')));
});

T::test('Post subscription: status sync tracks processed posts and settles with the provider charge, refund exactly once', function () use ($postsService, $in, $fake, $sync, $pdb, $ledger) {
    $fake();
    $svc = $postsService();
    $u = Fx::user('10');
    $sub = SubscriptionService::createPosts((int) $u['id'], $svc, $in());
    $fake(['status' => 'Active', 'posts' => '2', 'charge' => '0.35']);
    $sync();
    $s = SubscriptionService::find((int) $sub['id']);
    T::eq([2, 'active', 'Active'], [(int) $s['completed_cycles'], $s['status'], $s['provider_status']]);
    T::eq('in_progress', $pdb->fetchColumn('SELECT status FROM orders WHERE id = ?', [$s['last_order_id']]));
    // Final: provider charged 0.90 (cost currency) for its deliveries → user pays 0.90 × 2.00/1.00 = 1.80 of the 2.80 reserve.
    $fake(['status' => 'Completed', 'posts' => '5', 'charge' => '0.90']);
    $sync();
    $s = SubscriptionService::find((int) $sub['id']);
    T::eq(['completed', '1.800000', '1.000000', 5], [$s['status'], $s['final_charge'], $s['refunded'], (int) $s['completed_cycles']]);
    T::eq('8.200000', Fx::balance((int) $u['id']), '10 − 2.80 + 1.00');
    T::eq(['partial', '1.000000'], array_values($pdb->fetch('SELECT status, refunded_amount FROM orders WHERE id = ?', [$s['last_order_id']])));
    // Repeated syncs / settlement calls never refund twice.
    $sync();
    SubscriptionService::reconcile((int) $sub['id'], 'completed', 'test');
    T::eq('8.200000', Fx::balance((int) $u['id']));
    T::eq(1, $ledger((int) $u['id'], 'refund'));
});

T::test('Post subscription: without a provider charge, processed posts × max is charged; "Expired" closes it as expired', function () use ($postsService, $in, $fake, $sync) {
    $fake();
    $u = Fx::user('10');
    $sub = SubscriptionService::createPosts((int) $u['id'], $postsService(['provider_rate' => null]), $in());
    $fake(['status' => 'Expired', 'posts' => '3']);
    $sync();
    $s = SubscriptionService::find((int) $sub['id']);
    T::eq(['expired', '1.200000', '1.600000'], [$s['status'], $s['final_charge'], $s['refunded']], '3 posts × 200 × 2.00/1000 = 1.20');
    T::eq('8.800000', Fx::balance((int) $u['id']));
    // Provider over-reporting can never charge more than the reserve.
    $fake();
    $sub2 = SubscriptionService::createPosts((int) $u['id'], $postsService(['name' => 'svc2']), $in(['posts' => '1', 'old_posts' => '0']));
    $fake(['status' => 'Completed', 'posts' => '1', 'charge' => '99']);
    $sync();
    $s2 = SubscriptionService::find((int) $sub2['id']);
    T::eq([$s2['prepaid'], '0.000000'], [$s2['final_charge'], $s2['refunded']], 'capped at the reserve');
});

T::test('Post subscription: provider rejects the add → full refund, subscription failed', function () use ($postsService, $in, $pdb) {
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['error' => 'Incorrect username'])]);
    $u = Fx::user('10');
    $sub = SubscriptionService::createPosts((int) $u['id'], $postsService(), $in());
    $s = SubscriptionService::find((int) $sub['id']);
    T::eq(['failed', '0.000000'], [$s['status'], $s['final_charge']]);
    T::eq('10.000000', Fx::balance((int) $u['id']));
    T::eq('failed', $pdb->fetchColumn('SELECT status FROM orders WHERE id = ?', [$s['last_order_id']]));
});

T::test('Post subscription: cancel before sending, cancel accepted by provider, provider without cancel, admin force-cancel', function () use ($postsService, $in, $fake, $sync, &$calls, $psAdmin, $pdb) {
    $u = Fx::user('50');
    // 1. Still queued (provider unreachable): cancelled at once, full refund, never sent later.
    Fx::http(['https://provider.test/' => static fn () => new App\Core\HttpResponse(0, '', 1, 'Could not resolve host', true)]);
    $q = SubscriptionService::createPosts((int) $u['id'], $postsService(), $in());
    T::eq('queued', $pdb->fetchColumn('SELECT submit_state FROM orders WHERE id = ?', [$q['last_order_id']]));
    SubscriptionService::changeStatus((int) $q['id'], 'cancel', 'user', (int) $u['id']);
    T::eq(['cancelled', '0.000000'], [SubscriptionService::find((int) $q['id'])['status'], SubscriptionService::find((int) $q['id'])['final_charge']]);
    $fake();
    App\Services\OrderService::submit((int) $q['last_order_id']);
    T::eq([], $calls, 'the cancelled order is never sent');
    T::eq('50.000000', Fx::balance((int) $u['id']));
    // 2. At the provider, cancel accepted: settled when the provider reports the final state.
    $a = SubscriptionService::createPosts((int) $u['id'], $postsService(), $in());
    SubscriptionService::changeStatus((int) $a['id'], 'cancel', 'user', (int) $u['id']);
    T::true(in_array('cancel', array_column($calls, 'action'), true));
    $s = SubscriptionService::find((int) $a['id']);
    T::eq(['cancelled', null], [$s['status'], $s['final_charge']]);
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus((int) $a['id'], 'cancel', 'user', (int) $u['id']), 'cannot be cancelled');
    $fake(['status' => 'Canceled', 'posts' => '1']);
    $sync();
    $s = SubscriptionService::find((int) $a['id']);
    T::eq(['cancelled', '0.400000'], [$s['status'], $s['final_charge']], '1 post × 200 × 2.00/1000');
    // 3. Provider without cancel support: the user is told; an admin can force-cancel.
    $fake();
    $n = SubscriptionService::createPosts((int) $u['id'], $postsService(['cancel' => 0, 'name' => 'no-cancel']), $in());
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus((int) $n['id'], 'cancel', 'user', (int) $u['id']), 'did not accept a cancellation');
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus((int) $n['id'], 'pause', 'user', (int) $u['id']), 'cannot be paused');
    // Another user can never touch it.
    $other = Fx::user('0');
    T::throws(ValidationException::class, fn () => SubscriptionService::changeStatus((int) $n['id'], 'cancel', 'user', (int) $other['id']), 'not found');
    login_as_admin($psAdmin);
    http('POST', '/' . admin_path() . '/subscriptions/' . $n['id'] . '/action', ['_token' => csrf(), 'action' => 'force_cancel', 'reason' => 'Provider stopped delivering']);
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    T::eq(['cancelled', '0.000000'], [SubscriptionService::find((int) $n['id'])['status'], SubscriptionService::find((int) $n['id'])['final_charge']]);
});

T::test('Post subscription: manual service settles at expiry via cron; concurrent cron processes refund once', function () use ($postsService, $in, $pdb, $psAdmin, $ledger) {
    $u = Fx::user('10');
    $svc = $postsService(['provider_id' => null, 'provider_service_id' => null, 'name' => 'manual posts']);
    $sub = SubscriptionService::createPosts((int) $u['id'], $svc, $in());
    T::eq('manual', $pdb->fetchColumn('SELECT submit_state FROM orders WHERE id = ?', [$sub['last_order_id']]));
    login_as_admin($psAdmin);
    http('POST', '/' . admin_path() . '/subscriptions/' . $sub['id'] . '/action', ['_token' => csrf(), 'action' => 'record', 'posts_done' => '4']);
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    T::eq(4, (int) SubscriptionService::find((int) $sub['id'])['completed_cycles']);
    CronService::run('subscriptions', false);
    T::eq('active', SubscriptionService::find((int) $sub['id'])['status'], 'not settled before expiry');
    $pdb->query('UPDATE subscriptions SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $sub['id']]);
    if (function_exists('pcntl_fork')) {
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
    } else {
        SubscriptionService::processDue();
        SubscriptionService::processDue();
    }
    $s = SubscriptionService::find((int) $sub['id']);
    T::eq(['expired', '1.600000', '1.200000'], [$s['status'], $s['final_charge'], $s['refunded']], '4 posts × 200 × 2.00/1000 charged');
    T::eq('8.400000', Fx::balance((int) $u['id']));
    T::eq(1, $ledger((int) $u['id'], 'refund'), 'exactly one refund despite concurrent cron runs');
});

T::test('Post subscription (HTTP): order page data, server quote, JSON confirm, tampered POST rejected', function () use ($postsService, $fake, &$calls) {
    $fake();
    $svc = $postsService(['name' => 'HTTP posts svc']);
    $u = Fx::user('20');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    preg_match('#<script type="application/json" id="services-data">(.*?)</script>#s', $html, $m);
    $row = array_values(array_filter(json_decode($m[1] ?? '{}', true)['services'] ?? [], static fn ($x) => $x['id'] === $svc))[0] ?? [];
    T::eq(['posts', ['0', '15', '60'], 5, 30], [$row['sm'] ?? null, $row['dl'] ?? null, $row['om'] ?? null, $row['ed'] ?? null], 'catalog carries post-subscription rules');
    T::true(str_contains($html, 'id="field-subscription-posts"') && str_contains($html, 'name="sub_min"') && str_contains($html, 'name="sub_expiry"'));
    $form = ['_token' => csrf(), 'service' => $svc, 'order_type' => 'subscription', 'sub_username' => 'natgeo', 'sub_posts' => '3', 'sub_old_posts' => '1', 'sub_min' => '150', 'sub_max' => '300', 'sub_delay' => '60', 'sub_expiry' => '', 'form_key' => 'fk-posts-1'];
    $q = json_decode(http('POST', '/order/quote', $form, ['HTTP_ACCEPT' => 'application/json'])->body(), true);
    T::eq(true, $q['ok'] ?? null, json_encode($q));
    T::eq(['natgeo', 3, 1, '1 hour', '$2.40'], [$q['posts']['username'], $q['posts']['posts'], $q['posts']['old_posts'], $q['posts']['delay'], $q['posts']['reserve']]);
    // Tampering with limits in the POST body is refused server-side (quote and store).
    foreach ([['sub_posts' => '500'], ['sub_max' => '999999'], ['sub_delay' => '7'], ['sub_old_posts' => '50']] as $t) {
        $r = http('POST', '/order/quote', $t + $form, ['HTTP_ACCEPT' => 'application/json']);
        T::eq(422, $r->status(), json_encode($t));
        $r = http('POST', '/order', $t + $form, ['HTTP_ACCEPT' => 'application/json']);
        T::eq(422, $r->status(), json_encode($t));
    }
    T::eq('20.000000', Fx::balance((int) $u['id']));
    $r = json_decode(http('POST', '/order', $form, ['HTTP_ACCEPT' => 'application/json'])->body(), true);
    T::true($r['ok'] && str_contains($r['message'], 'reserved'), json_encode($r));
    T::eq('17.600000', Fx::balance((int) $u['id']));
    // Double submit with the same form key: one subscription.
    http('POST', '/order', $form, ['HTTP_ACCEPT' => 'application/json']);
    T::eq('17.600000', Fx::balance((int) $u['id']));
    T::true(str_contains(http('GET', '/subscriptions/' . $r['subscription_id'])->body(), 'natgeo'));
    T::true(str_contains(http('GET', '/subscriptions')->body(), '150–300 / post'));
    App\Services\Auth::logoutUser();
});
