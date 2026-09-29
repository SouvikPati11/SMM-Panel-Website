<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\HttpResponse;
use App\Services\OrderService;
use App\Services\ProviderSyncService;

$providerId = Fx::provider();
$serviceId = Fx::service($providerId);
$manualServiceId = Fx::service(null, ['name' => 'Manual service']);

/** Provider fake: $handler(array $form): HttpResponse */
$fakeProvider = static function (callable $handler) {
    Fx::http(['https://provider.test/' => static function ($m, $u, $o) use ($handler) {
        return $handler($o['form'] ?? $o['query'] ?? []);
    }]);
};

T::test('Order: success charges rate×qty/1000 and stores provider order id', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $sent = null;
    $fakeProvider(function ($form) use (&$sent) {
        $sent = $form;
        return Fx::json(['order' => 98765]);
    });
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://instagram.com/test', 'quantity' => '1000']);
    T::eq('2.500000', $o['charge']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
    T::eq('98765', $o['provider_order_id']);
    T::eq('processing', $o['status']);
    T::eq('add', $sent['action']);
    T::eq('provkey123', $sent['key']);
    T::eq('101', $sent['service']);
    T::eq(1000, $sent['quantity']);
});

T::test('Order: price is computed server-side (client price ignored)', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['order' => 1]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '500', 'charge' => '0.0001', 'rate' => '0']);
    T::eq('1.250000', $o['charge']);
});

T::test('Order: user discount (price level / custom) applied', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10', ['custom_discount' => '10.00']);
    $fakeProvider(fn () => Fx::json(['order' => 2]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::eq('2.250000', $o['charge']);
});

T::test('Order: invalid service rejected', function () {
    $u = Fx::user('10');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], 999999, ['link' => 'https://x.com', 'quantity' => '100']), 'not available');
});

T::test('Order: disabled / hidden service rejected', function () use ($providerId) {
    $u = Fx::user('10');
    $sid = Fx::service($providerId, ['status' => 'disabled']);
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $sid, ['link' => 'https://x.com', 'quantity' => '100']));
    $sid2 = Fx::service($providerId, ['is_hidden' => 1]);
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $sid2, ['link' => 'https://x.com', 'quantity' => '100']));
});

T::test('Order: quantity outside min/max rejected', function () use ($serviceId) {
    $u = Fx::user('100');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '99']), 'between');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '10001']), 'between');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1e3']), 'whole number');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $serviceId, ['link' => 'javascript:alert(1)', 'quantity' => '100']), 'valid URL');
    T::eq('100.000000', Fx::balance((int) $u['id']));
});

T::test('Order: insufficient balance rejected, nothing charged', function () use ($serviceId) {
    $u = Fx::user('1');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']), 'Insufficient');
    T::eq('1.000000', Fx::balance((int) $u['id']));
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$u['id']]));
});

T::test('Order: duplicate submission with same idempotency key charges once', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $calls = 0;
    $fakeProvider(function () use (&$calls) {
        $calls++;
        return Fx::json(['order' => 555]);
    });
    $a = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000'], 'web', 'form-abc');
    $b = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000'], 'web', 'form-abc');
    T::eq($a['id'], $b['id']);
    T::eq(true, $b['duplicate'] ?? false);
    T::eq(1, $calls);
    T::eq('7.500000', Fx::balance((int) $u['id']));
});

T::test('Order: provider rejection → failed + full refund', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['error' => 'Incorrect service ID']));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::eq('failed', $o['status']);
    T::eq('10.000000', Fx::balance((int) $u['id']));
    T::eq('2.500000', $o['refunded_amount']);
});

T::test('Order: provider unreachable → stays queued, cron retry succeeds, no refund', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    Fx::http([]); // everything unreachable (never sent)
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::eq('pending', $o['status']);
    T::eq('queued', $o['submit_state']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
    $fakeProvider(fn () => Fx::json(['order' => 777]));
    ProviderSyncService::submitQueued();
    $o = OrderService::find((int) $o['id']);
    T::eq('submitted', $o['submit_state']);
    T::eq('777', $o['provider_order_id']);
});

T::test('Order: provider timeout (after send) → unknown, flagged, NOT retried or refunded', function () use ($serviceId) {
    $u = Fx::user('10');
    $calls = 0;
    Fx::http(['https://provider.test/' => function () use (&$calls) {
        $calls++;
        return new HttpResponse(0, '', 30000, 'Operation timed out after 30000 ms', false);
    }]);
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::eq('unknown', $o['submit_state']);
    T::eq(1, (int) $o['needs_attention']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
    ProviderSyncService::submitQueued();
    T::eq(1, $calls, 'must not resubmit an ambiguous order');
    // Admin checks provider panel: order was never received → fail it with refund
    OrderService::adminResolveUnknown((int) $o['id'], 'fail', null, 1);
    T::eq('10.000000', Fx::balance((int) $u['id']));
});

T::test('Order: invalid (non-JSON) provider response → unknown', function () use ($serviceId) {
    $u = Fx::user('10');
    Fx::http(['https://provider.test/' => fn () => new HttpResponse(502, '<html>Bad gateway</html>', 10)]);
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::eq('unknown', $o['submit_state']);
});

T::test('Order: status sync completed / partial refund is exact and happens once', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['order' => 4001]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    $fakeProvider(fn ($f) => $f['action'] === 'status' ? Fx::json(['4001' => ['charge' => '1.1', 'start_count' => '500', 'status' => 'Partial', 'remains' => '400', 'currency' => 'USD']]) : Fx::json(['error' => 'x']));
    ProviderSyncService::syncOrderStatuses();
    ProviderSyncService::syncOrderStatuses(); // second run must not refund again
    OrderService::applyStatus((int) $o['id'], new App\Providers\ProviderOrderStatus('partial', 500, 400)); // replay
    $o = OrderService::find((int) $o['id']);
    T::eq('partial', $o['status']);
    T::eq('1.000000', $o['refunded_amount']); // 2.5 * 400/1000
    T::eq(400, (int) $o['remains']);
    T::eq(500, (int) $o['start_count']);
    T::eq('8.500000', Fx::balance((int) $u['id']));
});

T::test('Order: provider cancel → full refund once', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['order' => 5001]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    $fakeProvider(fn () => Fx::json(['5001' => ['status' => 'Canceled', 'remains' => '1000']]));
    ProviderSyncService::syncOrderStatuses();
    ProviderSyncService::syncOrderStatuses();
    T::eq('cancelled', OrderService::find((int) $o['id'])['status']);
    T::eq('10.000000', Fx::balance((int) $u['id']));
});

T::test('Order: completed via sync, then refill request forwarded to provider', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['order' => 6001]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    $fakeProvider(fn ($f) => match ($f['action']) {
        'status' => Fx::json(['6001' => ['status' => 'Completed', 'remains' => '0', 'start_count' => '10']]),
        'refill' => Fx::json(['refill' => '42']),
        'refill_status' => Fx::json(['status' => 'Completed']),
        default => Fx::json(['error' => 'bad']),
    });
    ProviderSyncService::syncOrderStatuses();
    T::eq('completed', OrderService::find((int) $o['id'])['status']);
    $r = OrderService::requestRefill((int) $u['id'], (int) $o['id']);
    T::eq('42', $r['provider_refill_id']);
    T::throws(ValidationException::class, fn () => OrderService::requestRefill((int) $u['id'], (int) $o['id']), '24 hours');
    ProviderSyncService::syncRefills();
    T::eq('completed', Database::instance()->fetchColumn('SELECT status FROM refills WHERE id = ?', [$r['id']]));
    T::eq('7.500000', Fx::balance((int) $u['id']));
});

T::test('Order: user cancel of queued order refunds locally; other users cannot cancel', function () use ($serviceId) {
    $u = Fx::user('10');
    $other = Fx::user('0');
    Fx::http([]);
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    T::throws(ValidationException::class, fn () => OrderService::requestCancel((int) $other['id'], (int) $o['id']), 'not found');
    T::eq('cancelled', OrderService::requestCancel((int) $u['id'], (int) $o['id']));
    T::eq('10.000000', Fx::balance((int) $u['id']));
    T::throws(ValidationException::class, fn () => OrderService::requestCancel((int) $u['id'], (int) $o['id']));
});

T::test('Order: manual service stays pending; admin partial applies refund', function () use ($manualServiceId) {
    $u = Fx::user('10');
    $o = OrderService::place((int) $u['id'], $manualServiceId, ['link' => 'https://x.com/a', 'quantity' => '2000']);
    T::eq('manual', $o['submit_state']);
    T::eq('5.000000', Fx::balance((int) $u['id']));
    OrderService::adminSetStatus((int) $o['id'], 'partial', 0, 1000, 1);
    T::eq('7.500000', Fx::balance((int) $u['id']));
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus((int) $o['id'], 'cancelled', null, null, 1), 'final');
});

T::test('Order: admin refund refunds only the remainder', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('10');
    $fakeProvider(fn () => Fx::json(['order' => 7001]));
    $o = OrderService::place((int) $u['id'], $serviceId, ['link' => 'https://x.com/a', 'quantity' => '1000']);
    OrderService::adminRefund((int) $o['id'], 1, 'customer request');
    T::eq('10.000000', Fx::balance((int) $u['id']));
    T::throws(ValidationException::class, fn () => OrderService::adminRefund((int) $o['id'], 1, 'again'));
    T::eq('10.000000', Fx::balance((int) $u['id']));
});

T::test('Order: custom comments type counts lines as quantity', function () use ($providerId, $fakeProvider) {
    $sid = Fx::service($providerId, ['type' => 'custom_comments', 'min_quantity' => 1, 'max_quantity' => 100, 'rate' => '10']);
    $u = Fx::user('10');
    $sent = null;
    $fakeProvider(function ($f) use (&$sent) {
        $sent = $f;
        return Fx::json(['order' => 8001]);
    });
    $o = OrderService::place((int) $u['id'], $sid, ['link' => 'https://x.com/p/1', 'comments' => "Nice!\n\nGreat post\nWow"]);
    T::eq(3, (int) $o['quantity']);
    T::eq('0.030000', $o['charge']);
    T::eq("Nice!\nGreat post\nWow", $sent['comments']);
    T::true(!isset($sent['quantity']), 'custom comments must not send quantity');
});

T::test('Order: drip-feed multiplies charge by runs', function () use ($providerId, $fakeProvider) {
    $sid = Fx::service($providerId, ['dripfeed' => 1]);
    $u = Fx::user('10');
    $sent = null;
    $fakeProvider(function ($f) use (&$sent) {
        $sent = $f;
        return Fx::json(['order' => 8101]);
    });
    $o = OrderService::place((int) $u['id'], $sid, ['link' => 'https://x.com/a', 'quantity' => '100', 'dripfeed' => '1', 'runs' => '5', 'interval' => '30']);
    T::eq('1.250000', $o['charge']);
    T::eq(5, $sent['runs']);
    T::eq(30, $sent['interval']);
});

T::test('Order: mass order validates each line independently', function () use ($serviceId, $fakeProvider) {
    $u = Fx::user('3');
    $fakeProvider(fn () => Fx::json(['order' => random_int(10000, 99999)]));
    $res = OrderService::placeMass((int) $u['id'], "{$serviceId} | https://x.com/a | 1000\nbad line\n{$serviceId}|https://x.com/b|50\n{$serviceId} | https://x.com/c | 1000", 'mass-1');
    T::eq(true, $res[0]['ok']);
    T::eq(false, $res[1]['ok']);
    T::eq(false, $res[2]['ok']); // below min
    T::eq(false, $res[3]['ok']); // insufficient balance (0.5 left)
    T::eq('0.500000', Fx::balance((int) $u['id']));
});

T::test('Provider: catalog import applies markup and price sync follows provider', function () use ($providerId, $fakeProvider) {
    $fakeProvider(fn ($f) => Fx::json([
        ['service' => 501, 'name' => 'TikTok Views', 'type' => 'Default', 'category' => 'TikTok', 'rate' => '0.10', 'min' => '100', 'max' => '1000000', 'refill' => false, 'cancel' => true],
        ['service' => 502, 'name' => 'YT Comments', 'type' => 'Custom Comments', 'category' => 'YouTube', 'rate' => '5', 'min' => '5', 'max' => '500', 'refill' => false, 'cancel' => false],
    ]));
    T::eq(2, ProviderSyncService::fetchCatalog($providerId)['count']);
    T::eq(2, ProviderSyncService::importServices($providerId, ['501', '502'], null, '50', true, true));
    $s = Database::instance()->fetch("SELECT * FROM services WHERE provider_id = ? AND provider_service_id = '501'", [$providerId]);
    T::eq('0.150000', $s['rate']);
    T::eq('custom_comments', Database::instance()->fetchColumn("SELECT type FROM services WHERE provider_service_id = '502'"));
    // provider raises price and drops service 502
    $fakeProvider(fn () => Fx::json([['service' => 501, 'name' => 'TikTok Views', 'category' => 'TikTok', 'rate' => '0.20', 'min' => '50', 'max' => '1000']]));
    ProviderSyncService::fetchCatalog($providerId);
    ProviderSyncService::syncServicePrices($providerId);
    $s = Database::instance()->fetch('SELECT * FROM services WHERE id = ?', [$s['id']]);
    T::eq('0.300000', $s['rate']);
    T::eq(50, (int) $s['min_quantity']);
    T::eq('disabled', Database::instance()->fetchColumn("SELECT status FROM services WHERE provider_service_id = '502'"));
});
