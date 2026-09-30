<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpResponse;
use App\Providers\ProviderFactory;
use App\Providers\ResponseParser;
use App\Services\ProviderSyncService;

/*
 * Provider API v2 compatibility: tolerant balance/services parsing, useful
 * admin diagnostics without secrets, provider states, add/edit/test/import.
 */

$pdb = Database::instance();
$provAdmin = (int) $pdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $pdb->insert('admins', ['username' => 'provadmin', 'email' => 'provadmin@example.com', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
/** Serve one raw body for every call to the fake provider. */
$serve = static function (string $body, int $status = 200, array $headers = []): void {
    Fx::http(['https://provider.test/' => static fn () => new HttpResponse($status, $body, 3, null, false, $headers)]);
};
$balanceOf = static function (string $body) use ($serve): array {
    $serve($body);
    $id = Fx::provider();
    return ProviderSyncService::checkProvider($id);
};

T::test('Provider parser: money amounts in every unambiguous API v2 format', function () {
    $cases = [
        ['100.84', '100.84'], [100.84, '100.84'], [5, '5'], [0.00001, '0.00001'], ['0', '0'],
        ['1,234.56', '1234.56'], ['1.234,56', '1234.56'], ['12,5', '12.5'], ['1,234', '1234'], ['1.234.567', '1234567'],
        ['$12.50', '12.50'], ['12.50 USD', '12.50'], ['₹ 1,234.56', '1234.56'], ["1\u{00A0}234,50 €", '1234.50'], ['-5.20', '-5.20'],
        ['12.1234567890123456', '12.123456789012'],
    ];
    foreach ($cases as [$in, $out]) {
        T::eq($out, ResponseParser::amount($in), var_export($in, true));
    }
    foreach (['', 'abc', null, true, [], '1,2,3', '1.2.3'] as $bad) {
        T::eq(null, ResponseParser::amount($bad), var_export($bad, true));
    }
});

T::test('Provider balance: standard and non-standard valid responses are accepted', function () use ($balanceOf) {
    $cases = [
        ['{"balance":"100.84","currency":"USD"}', '100.840000', 'USD'],
        ['{"balance":100.84,"currency":"USD"}', '100.840000', 'USD'],
        ['{"balance":"1,234.56","currency":"INR"}', '1234.560000', 'INR'],
        ['{"balance":"₹1,234.56"}', '1234.560000', 'USD'],
        ['{"balance":"12.50 EUR"}', '12.500000', 'EUR'],
        ['{"status":"success","data":{"balance":"7.25","currency":"usd"}}', '7.250000', 'USD'],
        ['{"Balance":"3.5"}', '3.500000', 'USD'],
        ['{"balance":{"amount":"9.99","currency":"GBP"}}', '9.990000', 'GBP'],
        ['{"balance":0.00001}', '0.000010', 'USD'],
        ["\xEF\xBB\xBF{\"balance\":\"4.00\"}", '4.000000', 'USD'],
        ["<br />\n<b>Notice</b>: Undefined index in api.php on line 3<br />\n{\"balance\":\"2.10\",\"currency\":\"USD\"}", '2.100000', 'USD'],
        ['"42.10"', '42.100000', 'USD'],
    ];
    foreach ($cases as [$body, $bal, $cur]) {
        $r = $balanceOf($body);
        T::true($r['ok'], $body . ' → ' . ($r['error'] ?? ''));
        T::eq($bal, $r['balance'], $body);
        T::eq($cur, $r['currency'], $body);
    }
});

T::test('Provider balance: a response without a balance explains what was received, never the key', function () use ($balanceOf, $pdb) {
    $r = $balanceOf('{"status":"success","user":"reseller","key":"provkey123"}');
    T::true(!$r['ok']);
    T::true(str_contains($r['error'], 'Unexpected balance response') && str_contains($r['error'], 'keys: status, user, key'), $r['error']);
    T::true(!str_contains($r['error'], 'provkey123'), 'API key must not appear in the error');
    $log = $pdb->fetch('SELECT * FROM provider_logs ORDER BY id DESC LIMIT 1');
    T::eq('balance', $log['action']);
    T::true(!str_contains((string) $log['response'], 'provkey123') && str_contains((string) $log['response'], '[redacted]'), 'logged response is redacted');
    T::true(!str_contains((string) $log['request'], 'provkey123'), 'logged request has no key');
    T::eq('error', $pdb->fetchColumn('SELECT connection_status FROM providers ORDER BY id DESC LIMIT 1'));
});

T::test('Provider errors: API error, status+message failure, HTML page, redirect, empty body', function () use ($balanceOf, $serve) {
    T::true(str_contains($balanceOf('{"error":"Invalid API key"}')['error'], 'Provider error: Invalid API key'));
    T::true(str_contains($balanceOf('{"status":"fail","message":"Incorrect request"}')['error'], 'Provider error: Incorrect request'));
    T::true(str_contains($balanceOf('{"success":false,"msg":"IP not allowed"}')['error'], 'IP not allowed'));
    T::true(str_contains($balanceOf('<!DOCTYPE html><html><body>Just a moment…</body></html>')['error'], 'HTML page instead of JSON'));
    $serve('', 301, ['location' => 'https://www.provider.test/api/v2']);
    $r = ProviderSyncService::checkProvider(Fx::provider());
    T::true(str_contains($r['error'], 'redirects (HTTP 301) to https://www.provider.test/api/v2'), $r['error']);
    T::true(str_contains($balanceOf('')['error'], 'empty body'));
    // Order-status responses legitimately say "Fail": that is an order status, not a request failure.
    $serve('{"status":"Fail","charge":"0","remains":"100"}');
    $st = ProviderFactory::make(Database::instance()->fetch('SELECT * FROM providers WHERE id = ?', [Fx::provider()]))->status('55');
    T::eq('cancelled', $st->status);
});

T::test('Provider services: plain list, wrapped list, keyed object, price field; clear error when nothing matches', function () use ($serve) {
    $row = ['service' => 1, 'name' => 'IG Likes', 'category' => 'Instagram', 'type' => 'Default', 'rate' => '$0.50', 'min' => '10', 'max' => '10,000', 'refill' => 'true', 'cancel' => false];
    foreach ([[$row], ['services' => [$row]], ['data' => [$row]], ['1' => array_diff_key($row, ['service' => 1])]] as $payload) {
        $serve(json_encode($payload));
        $pid = Fx::provider();
        $r = ProviderSyncService::fetchCatalog($pid);
        T::eq(1, $r['count'], json_encode($payload));
        $ps = Database::instance()->fetch('SELECT * FROM provider_services WHERE provider_id = ?', [$pid]);
        T::eq(['1', '0.500000', 10, 10000, 1], [$ps['provider_service_id'], $ps['rate'], (int) $ps['min_quantity'], (int) $ps['max_quantity'], (int) $ps['refill']]);
        T::eq(null, Database::instance()->fetchColumn('SELECT syncing_since FROM providers WHERE id = ?', [$pid]), 'syncing flag cleared');
    }
    $serve(json_encode([['id' => 7, 'title' => 'x', 'cost' => 1]]));
    T::throws(App\Core\Exceptions\ValidationException::class, fn () => ProviderSyncService::fetchCatalog(Fx::provider()), 'none of the 1 items has the API v2 fields');
});

T::test('Provider auth: key can be sent as a bearer header instead of a form field', function () {
    $seen = null;
    Fx::http(['https://provider.test/' => static function (string $m, string $u, array $opts) use (&$seen) {
        $seen = $opts;
        return Fx::json(['balance' => '1']);
    }]);
    T::true(ProviderSyncService::checkProvider(Fx::provider('https://provider.test/api/v2', ['key_in' => 'bearer']))['ok']);
    T::eq('Bearer provkey123', $seen['headers']['Authorization'] ?? null);
    T::true(!isset($seen['form']['key']), 'key not in the form');
    T::eq('balance', $seen['form']['action']);
    ProviderSyncService::checkProvider(Fx::provider());
    T::eq('provkey123', $seen['form']['key'], 'standard: form field');
});

T::test('Provider states: connected, error, never checked, syncing, disabled', function () {
    $base = ['status' => 'active', 'connection_status' => 'ok', 'last_checked_at' => now(), 'last_synced_at' => null, 'syncing_since' => null];
    T::eq('ok', ProviderSyncService::displayStatus($base));
    T::eq('error', ProviderSyncService::displayStatus(['connection_status' => 'error'] + $base));
    T::eq('never', ProviderSyncService::displayStatus(['connection_status' => 'unknown', 'last_checked_at' => null] + $base));
    T::eq('syncing', ProviderSyncService::displayStatus(['syncing_since' => now()] + $base));
    T::eq('ok', ProviderSyncService::displayStatus(['syncing_since' => gmdate('Y-m-d H:i:s', time() - 3600)] + $base), 'stale syncing flag ignored');
    T::eq('disabled', ProviderSyncService::displayStatus(['status' => 'disabled', 'connection_status' => 'error'] + $base));
    // An unexpected exception inside a check is reported, not a 500.
    $pid = Fx::provider();
    ProviderFactory::override($pid, new class implements App\Providers\ProviderAdapterInterface {
        public function addOrder(array $params): string { return '1'; }
        public function status(string $id): App\Providers\ProviderOrderStatus { throw new RuntimeException('x'); }
        public function multiStatus(array $ids): array { return []; }
        public function cancel(array $ids): array { return []; }
        public function refill(string $id): string { return '1'; }
        public function refillStatus(string $id): string { return 'pending'; }
        public function services(): array { return []; }
        public function balance(): array { throw new TypeError('boom'); }
    });
    $r = ProviderSyncService::checkProvider($pid);
    ProviderFactory::override($pid, null);
    T::true(!$r['ok'] && str_contains($r['error'], 'Internal error'), $r['error'] ?? '');
});

T::test('Admin providers: add (HTTPS required unless allowed), edit keeps key, list shows states, import skips provider subscriptions', function () use ($provAdmin, $serve, $pdb) {
    login_as_admin($provAdmin);
    $serve('{"balance":"55.10","currency":"USD"}');
    $save = ['_token' => csrf(), 'name' => 'SMM Exporter', 'api_url' => ' https://provider.test/api/v2 ', 'api_key' => 'provkey123', 'currency' => 'USD', 'exchange_rate' => '1', 'timeout' => '30', 'adapter' => 'standard_v2', 'status' => 'active', 'config' => ''];
    $r = http('POST', '/' . admin_path() . '/providers/save', $save);
    T::eq(302, $r->status());
    $p = $pdb->fetch("SELECT * FROM providers WHERE name = 'SMM Exporter' ORDER BY id DESC LIMIT 1");
    T::eq(['https://provider.test/api/v2', 'ok', '55.100000'], [$p['api_url'], $p['connection_status'], $p['balance']], 'URL trimmed, connection tested');
    T::true(App\Core\Crypto::decrypt($p['api_key_enc']) === 'provkey123');
    // Plain HTTP needs explicit opt-in.
    $before = (int) $pdb->fetchColumn('SELECT COUNT(*) FROM providers');
    http('POST', '/' . admin_path() . '/providers/save', ['api_url' => 'http://provider.test/api/v2', 'name' => 'Plain', '_token' => csrf()] + $save);
    T::eq($before, (int) $pdb->fetchColumn('SELECT COUNT(*) FROM providers'), 'http:// rejected without opt-in');
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'Allow plain HTTP'));
    Fx::http(['http://provider.test/' => static fn () => Fx::json(['balance' => '1'])]);
    http('POST', '/' . admin_path() . '/providers/save', ['api_url' => 'http://provider.test/api/v2', 'name' => 'Plain', 'allow_http' => '1', '_token' => csrf()] + $save);
    T::eq($before + 1, (int) $pdb->fetchColumn('SELECT COUNT(*) FROM providers'));
    // Edit without re-entering the key keeps it.
    $serve('{"balance":"1"}');
    http('POST', '/' . admin_path() . '/providers/save', ['id' => $p['id'], 'api_key' => '', 'name' => 'SMM Exporter 2', '_token' => csrf()] + $save);
    T::eq('provkey123', App\Core\Crypto::decrypt((string) $pdb->fetchColumn('SELECT api_key_enc FROM providers WHERE id = ?', [$p['id']])));
    // Invalid config values are rejected with the allowed keys listed.
    http('POST', '/' . admin_path() . '/providers/save', ['id' => $p['id'], 'config' => '{"key_in":"cookie"}', '_token' => csrf()] + $save);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'key_in'));
    // Index shows each state label and the diagnostic, never the key.
    $pdb->update('providers', ['connection_status' => 'error', 'last_error' => 'Unexpected balance response: no numeric "balance" field.'], ['id' => $p['id']]);
    $html = http('GET', '/' . admin_path() . '/providers')->body();
    T::true(str_contains($html, 'Unexpected balance response') && str_contains($html, 'View the full response in the API log'));
    T::true(str_contains($html, '>Connected<') && str_contains($html, '>Error<') && !str_contains($html, 'provkey123'));
    // Catalog with a provider-side subscription service: fetched, but not importable.
    $serve(json_encode([
        ['service' => 11, 'name' => 'IG Likes', 'category' => 'Instagram', 'type' => 'Default', 'rate' => '0.5', 'min' => 10, 'max' => 1000],
        ['service' => 12, 'name' => 'IG Auto Likes', 'category' => 'Instagram', 'type' => 'Subscriptions', 'rate' => '0.9', 'min' => 10, 'max' => 1000],
    ]));
    http('POST', '/' . admin_path() . '/providers/' . $p['id'] . '/fetch', ['_token' => csrf()]);
    T::true(str_contains(end($_SESSION['_flash'])['message'], '1 of them are provider-side subscriptions'));
    http('POST', '/' . admin_path() . '/providers/' . $p['id'] . '/import', ['_token' => csrf(), 'ids' => ['11', '12'], 'markup' => '20', 'category_mode' => 'provider']);
    T::true(str_contains(end($_SESSION['_flash'])['message'], '1 services imported') && str_contains(end($_SESSION['_flash'])['message'], '1 provider-side "Subscriptions"'));
    T::eq(1, (int) $pdb->fetchColumn('SELECT COUNT(*) FROM services WHERE provider_id = ?', [$p['id']]));
    T::eq('0.600000', $pdb->fetchColumn("SELECT rate FROM services WHERE provider_id = ? AND provider_service_id = '11'", [$p['id']]), '20% markup');
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});
