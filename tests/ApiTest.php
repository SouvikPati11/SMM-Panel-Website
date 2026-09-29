<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApiKeyService;
use App\Services\SettingsService;

$apiProvider = Fx::provider('https://provider.test/api/v2');
$apiService = Fx::service($apiProvider, ['name' => 'API test service', 'rate' => '1.000000']);
$apiUser = Fx::user('5');
$apiKey = ApiKeyService::generate((int) $apiUser['id']);
$api = static fn (array $params, string $ip = '203.0.113.50') => http('POST', '/api/v2', $params, ['REMOTE_ADDR' => $ip]);

T::test('API: invalid key → 401 JSON error, logged', function () use ($api) {
    $r = $api(['key' => str_repeat('0', 40), 'action' => 'balance']);
    T::eq(401, $r->status());
    T::eq(['error' => 'Invalid API key'], json_decode($r->body(), true));
    T::eq(401, (int) Database::instance()->fetchColumn('SELECT http_status FROM api_logs ORDER BY id DESC LIMIT 1'));
    T::true(!str_contains((string) Database::instance()->fetchColumn('SELECT request FROM api_logs ORDER BY id DESC LIMIT 1'), str_repeat('0', 40)), 'full key must not be logged');
});

T::test('API: valid key → balance', function () use ($api, $apiKey) {
    $r = $api(['key' => $apiKey, 'action' => 'balance']);
    T::eq(200, $r->status());
    T::eq(['balance' => '5.00000', 'currency' => 'USD'], json_decode($r->body(), true));
});

T::test('API: only a hash of the key is stored', function () use ($apiKey, $apiUser) {
    $row = Database::instance()->fetch("SELECT * FROM api_keys WHERE user_id = ? AND status = 'active'", [$apiUser['id']]);
    T::eq(hash('sha256', $apiKey), $row['key_hash']);
    T::true(!str_contains(json_encode($row), $apiKey));
});

T::test('API: services list uses standard fields and hides hidden services', function () use ($api, $apiKey, $apiService, $apiProvider) {
    $hidden = Fx::service($apiProvider, ['name' => 'Secret service', 'is_hidden' => 1]);
    $list = json_decode($api(['key' => $apiKey, 'action' => 'services'])->body(), true);
    $ids = array_column($list, 'service');
    T::true(in_array($apiService, $ids, true));
    T::true(!in_array($hidden, $ids, true), 'hidden service leaked');
    $svc = $list[array_search($apiService, $ids, true)];
    foreach (['service', 'name', 'type', 'category', 'rate', 'min', 'max', 'refill', 'cancel', 'dripfeed'] as $k) {
        T::true(array_key_exists($k, $svc), "missing {$k}");
    }
    T::eq('1.00', $svc['rate']);
});

T::test('API: add order, then status single + multiple', function () use ($api, $apiKey, $apiService) {
    Fx::http(['https://provider.test/' => fn () => Fx::json(['order' => 31337])]);
    $r = $api(['key' => $apiKey, 'action' => 'add', 'service' => $apiService, 'link' => 'https://instagram.com/x', 'quantity' => '1000']);
    T::eq(200, $r->status());
    $oid = json_decode($r->body(), true)['order'];
    T::true(is_int($oid) && $oid > 0);
    $st = json_decode($api(['key' => $apiKey, 'action' => 'status', 'order' => $oid])->body(), true);
    T::eq('Processing', $st['status']);
    T::eq('1.00', $st['charge']);
    $multi = json_decode($api(['key' => $apiKey, 'action' => 'status', 'orders' => $oid . ',999999'])->body(), true);
    T::eq('Processing', $multi[(string) $oid]['status']);
    T::eq('Incorrect order ID', $multi['999999']['error']);
    T::eq('source api', 'source ' . Database::instance()->fetchColumn('SELECT source FROM orders WHERE id = ?', [$oid]));
});

T::test('API: invalid service / quantity / insufficient funds → 422 with message', function () use ($api, $apiKey, $apiService) {
    $r = $api(['key' => $apiKey, 'action' => 'add', 'service' => 424242, 'link' => 'https://x.com', 'quantity' => '100']);
    T::eq(422, $r->status());
    T::eq('Incorrect service ID', json_decode($r->body(), true)['error']);
    $r = $api(['key' => $apiKey, 'action' => 'add', 'service' => $apiService, 'link' => 'https://x.com', 'quantity' => '5']);
    T::eq(422, $r->status());
    $r = $api(['key' => $apiKey, 'action' => 'add', 'service' => $apiService, 'link' => 'https://x.com', 'quantity' => '9000']);
    T::eq('Not enough funds on balance', json_decode($r->body(), true)['error']);
});

T::test('API: provider failure surfaces as failed order with refund', function () use ($api, $apiKey, $apiService, $apiUser) {
    $before = Fx::balance((int) $apiUser['id']);
    Fx::http(['https://provider.test/' => fn () => Fx::json(['error' => 'Not enough funds on balance'])]);
    $oid = json_decode($api(['key' => $apiKey, 'action' => 'add', 'service' => $apiService, 'link' => 'https://x.com/b', 'quantity' => '100'])->body(), true)['order'];
    $st = json_decode($api(['key' => $apiKey, 'action' => 'status', 'order' => $oid])->body(), true);
    T::eq('Canceled', $st['status']);
    T::eq($before, Fx::balance((int) $apiUser['id']));
});

T::test('API: users cannot read other users\' orders', function () use ($api, $apiKey, $apiService) {
    $other = Fx::user('10');
    $otherKey = ApiKeyService::generate((int) $other['id']);
    Fx::http(['https://provider.test/' => fn () => Fx::json(['order' => 5])]);
    $oid = json_decode($api(['key' => $otherKey, 'action' => 'add', 'service' => $apiService, 'link' => 'https://x.com/c', 'quantity' => '100'])->body(), true)['order'];
    $r = $api(['key' => $apiKey, 'action' => 'status', 'order' => $oid]);
    T::eq('Incorrect order ID', json_decode($r->body(), true)['error']);
});

T::test('API: per-key rate limit returns 429', function () use ($api) {
    SettingsService::set('api_rate_limit', '3');
    $u = Fx::user('1');
    $k = ApiKeyService::generate((int) $u['id']);
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = $api(['key' => $k, 'action' => 'balance'])->status();
    }
    SettingsService::set('api_rate_limit', '60');
    T::eq([200, 200, 200, 429, 429], $codes);
});

T::test('API: repeated bad keys from one IP are throttled', function () use ($api, $apiKey) {
    for ($i = 0; $i < 31; $i++) {
        $api(['key' => bin2hex(random_bytes(20)), 'action' => 'balance'], '192.0.2.99');
    }
    T::eq(429, $api(['key' => $apiKey, 'action' => 'balance'], '192.0.2.99')->status());
});

T::test('API: disabled per user and revoked keys are rejected', function () use ($api) {
    $u = Fx::user('1');
    $k = ApiKeyService::generate((int) $u['id']);
    Database::instance()->update('users', ['api_enabled' => 0], ['id' => $u['id']]);
    T::eq(401, $api(['key' => $k, 'action' => 'balance'])->status());
    Database::instance()->update('users', ['api_enabled' => 1], ['id' => $u['id']]);
    ApiKeyService::revoke((int) $u['id']);
    T::eq(401, $api(['key' => $k, 'action' => 'balance'])->status());
});

T::test('API: unknown action → 400', function () use ($api, $apiKey) {
    T::eq(400, $api(['key' => $apiKey, 'action' => 'drop_tables'])->status());
});
