<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\OrderService;
use App\Services\PriceProtection;
use App\Services\ProviderSyncService;
use App\Services\SettingsService;

/*
 * Provider price protection: cost changes detected at sync, safe price =
 * cost × (1 + margin) / (1 − largest discount), modes protect / auto /
 * disable / off, manual prices, existing orders untouched, order-time guard.
 */

$ppdb = Database::instance();
// Discounts are zeroed for predictable safe prices and restored at the end.
$ppLevels = $ppdb->fetchPairs('SELECT id, discount_percent FROM price_levels');
$ppUsers = $ppdb->fetchPairs('SELECT id, custom_discount FROM users WHERE custom_discount > 0');
$ppProvider = Fx::provider();
$ppCatalog = static function (string $psid, string $rate, array $extra = []) use ($ppdb, $ppProvider): void {
    $ppdb->query(
        'INSERT INTO provider_services (provider_id, provider_service_id, name, category, type, rate, min_quantity, max_quantity, is_available, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate), is_available = VALUES(is_available), min_quantity = VALUES(min_quantity), max_quantity = VALUES(max_quantity)',
        [$ppProvider, $psid, 'PP ' . $psid, 'Test', 'Default', $rate, $extra['min'] ?? 10, $extra['max'] ?? 10000, $extra['avail'] ?? 1, now()]
    );
};
$ppService = static function (string $psid, string $rate, string $cost, array $extra = []) use ($ppProvider, $ppCatalog): int {
    $ppCatalog($psid, $cost);
    return Fx::service($ppProvider, $extra + ['name' => 'PP service ' . $psid, 'provider_service_id' => $psid, 'rate' => $rate, 'provider_rate' => $cost, 'min_quantity' => 10, 'max_quantity' => 10000]);
};
$svc = static fn (int $id) => Database::instance()->fetch('SELECT * FROM services WHERE id = ?', [$id]);
$events = static fn (int $id) => Database::instance()->fetchAll('SELECT action, old_cost, new_cost, old_rate, new_rate FROM service_price_events WHERE service_id = ? ORDER BY id', [$id]);
$mode = static function (string $m, string $margin = '0'): void {
    SettingsService::set('price_protection_mode', $m);
    SettingsService::set('price_protection_margin', $margin);
    PriceProtection::reset();
};
$last = static fn (array $a) => $a ? $a[count($a) - 1] : null;
$sync = static function () use ($ppProvider): array {
    PriceProtection::reset();
    return ProviderSyncService::syncServicePrices($ppProvider);
};
$noDiscounts = static function (): void {
    Database::instance()->query('UPDATE price_levels SET discount_percent = 0');
    Database::instance()->query('UPDATE users SET custom_discount = 0');
    PriceProtection::reset();
};

T::test('Price protection: safe rate formula accounts for margin and the largest discount (rounded up)', function () use ($mode, $noDiscounts, $ppdb) {
    $noDiscounts();
    $mode('protect', '0');
    T::eq('1.000000', PriceProtection::safeRate('1.000000'));
    $mode('protect', '20');
    T::eq('1.200000', PriceProtection::safeRate('1.000000'));
    T::eq('1.200000', PriceProtection::minUserRate('1.000000'));
    $lvl = $ppdb->insert('price_levels', ['name' => 'VIP pp', 'discount_percent' => '25.00', 'min_spent' => '0', 'created_at' => now()]);
    PriceProtection::reset();
    T::eq('25.00', PriceProtection::maxDiscount());
    T::eq('1.600000', PriceProtection::safeRate('1.000000'), '1.20 / 0.75');
    // A custom discount larger than any level wins.
    $u = Fx::user('0');
    $ppdb->update('users', ['custom_discount' => '40.00'], ['id' => $u['id']]);
    PriceProtection::reset();
    T::eq('2.000000', PriceProtection::safeRate('1.000000'), '1.20 / 0.60');
    // Result after the discount never falls below cost + margin (rounding up).
    $safe = PriceProtection::safeRate('0.333333');
    T::true(App\Core\Money::cmp(App\Core\Money::sub($safe, App\Core\Money::percent($safe, '40')), PriceProtection::minUserRate('0.333333')) >= 0);
    $ppdb->query('DELETE FROM price_levels WHERE id = ?', [$lvl]);
    $noDiscounts();
    $mode('protect', '0');
});

T::test('Price protection (protect): unchanged, decrease, increase, large increase; prices never silently overwritten', function () use ($ppService, $ppCatalog, $svc, $events, $mode, $sync, $noDiscounts, $last) {
    $noDiscounts();
    $mode('protect', '10');
    $id = $ppService('pp1', '2.000000', '1.000000');
    // Unchanged cost: no event, nothing changes.
    $sync();
    T::eq([], $events($id));
    T::eq(['2.000000', 0], [$svc($id)['rate'], (int) $svc($id)['price_blocked']]);
    // Decrease: recorded, manual price kept.
    $ppCatalog('pp1', '0.800000');
    $sync();
    T::eq(['0.800000', '2.000000', 0], [$svc($id)['provider_rate'], $svc($id)['rate'], (int) $svc($id)['price_blocked']]);
    T::eq('decrease', $last($events($id))['action']);
    // Increase that is still safe (1.5 × 1.10 = 1.65 ≤ 2.00): recorded, nothing blocked.
    $ppCatalog('pp1', '1.500000');
    $sync();
    T::eq(['increase', 0], [$last($events($id))['action'], (int) $svc($id)['price_blocked']]);
    // Large increase (3.00 × 1.10 = 3.30 > 2.00): blocked, price NOT changed.
    $ppCatalog('pp1', '3.000000');
    $r = $sync();
    T::eq(['2.000000', 1, 'active'], [$svc($id)['rate'], (int) $svc($id)['price_blocked'], $svc($id)['status']]);
    T::eq('blocked', $last($events($id))['action']);
    T::eq(1, $r['protection']['blocked'] ?? 0);
    // Repeated sync at the same cost: no duplicate events.
    $n = count($events($id));
    $sync();
    T::eq($n, count($events($id)));
    // Cost falls back: unblocked automatically.
    $ppCatalog('pp1', '1.000000');
    $sync();
    T::eq([0, 'unblocked'], [(int) $svc($id)['price_blocked'], $last($events($id))['action']]);
});

T::test('Price protection (auto): raises the manual price to the safe minimum, never lowers it; auto-sync services follow markup', function () use ($ppService, $ppCatalog, $svc, $events, $mode, $sync, $noDiscounts, $last) {
    $noDiscounts();
    $mode('auto', '10');
    $id = $ppService('pp2', '2.000000', '1.000000');
    $ppCatalog('pp2', '3.000000');
    $sync();
    T::eq(['3.300000', 0], [$svc($id)['rate'], (int) $svc($id)['price_blocked']]);
    $ev = $last($events($id));
    T::eq(['repriced', '2.000000', '3.300000'], [$ev['action'], $ev['old_rate'], $ev['new_rate']]);
    $ppCatalog('pp2', '0.500000');
    $sync();
    T::eq('3.300000', $svc($id)['rate'], 'a decrease never lowers the price');
    // Auto-sync service with a markup below the margin: markup price is applied, then raised to the safe price.
    $a = $ppService('pp3', '1.050000', '1.000000', ['auto_sync' => 1, 'markup_percent' => '5.00']);
    $ppCatalog('pp3', '2.000000');
    $sync();
    T::eq(['2.000000', '2.200000'], [$svc($a)['provider_rate'], $svc($a)['rate']]);
    // Auto-sync with a healthy markup: follows the markup exactly.
    $b = $ppService('pp4', '1.500000', '1.000000', ['auto_sync' => 1, 'markup_percent' => '50.00']);
    $ppCatalog('pp4', '2.000000');
    $sync();
    T::eq('3.000000', $svc($b)['rate']);
    $mode('protect', '0');
});

T::test('Price protection (disable): disables unsafe services, re-enables them when safe, leaves admin-disabled services alone', function () use ($ppService, $ppCatalog, $svc, $mode, $sync, $noDiscounts, $ppdb) {
    $noDiscounts();
    $mode('disable', '0');
    $id = $ppService('pp5', '1.000000', '0.500000');
    $ppCatalog('pp5', '2.000000');
    $sync();
    T::eq(['disabled', 1, '1.000000'], [$svc($id)['status'], (int) $svc($id)['price_disabled'], $svc($id)['rate']]);
    $ppCatalog('pp5', '0.700000');
    $sync();
    T::eq(['active', 0], [$svc($id)['status'], (int) $svc($id)['price_disabled']]);
    // Disabled by an admin: never re-enabled by protection.
    $m = $ppService('pp6', '1.000000', '0.500000');
    $ppdb->update('services', ['status' => 'disabled'], ['id' => $m]);
    $ppCatalog('pp6', '2.000000');
    $sync();
    $ppCatalog('pp6', '0.600000');
    $sync();
    T::eq(['disabled', 0], [$svc($m)['status'], (int) $svc($m)['price_disabled']]);
    $mode('protect', '0');
});

T::test('Price protection (off): cost is tracked but prices and availability are untouched', function () use ($ppService, $ppCatalog, $svc, $mode, $sync, $noDiscounts) {
    $noDiscounts();
    $mode('off', '0');
    $id = $ppService('pp7', '1.000000', '0.500000');
    $ppCatalog('pp7', '5.000000');
    $sync();
    T::eq(['5.000000', '1.000000', 0, 'active'], [$svc($id)['provider_rate'], $svc($id)['rate'], (int) $svc($id)['price_blocked'], $svc($id)['status']]);
    $mode('protect', '0');
});

T::test('Price protection: existing orders keep their price; new orders are refused below cost + margin (server-side)', function () use ($ppService, $ppCatalog, $svc, $mode, $sync, $noDiscounts, $ppdb) {
    $noDiscounts();
    $mode('protect', '0');
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['order' => 9901])]);
    $id = $ppService('pp8', '2.000000', '1.000000');
    $u = Fx::user('100');
    $o = OrderService::place((int) $u['id'], $id, ['link' => 'https://instagram.com/p/x', 'quantity' => 1000]);
    $before = $ppdb->fetch('SELECT rate, charge, status FROM orders WHERE id = ?', [$o['id']]);
    $ppCatalog('pp8', '4.000000');
    $sync();
    T::eq($before, $ppdb->fetch('SELECT rate, charge, status FROM orders WHERE id = ?', [$o['id']]), 'existing order untouched');
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $id, ['link' => 'https://instagram.com/p/y', 'quantity' => 1000]), 'temporarily unavailable');
    // Raising the price by hand unblocks it (evaluated on admin save).
    $ppdb->update('services', ['rate' => '4.500000'], ['id' => $id]);
    PriceProtection::evaluate($svc($id), '4.000000', '4.000000', 'admin edit');
    $o2 = OrderService::place((int) $u['id'], $id, ['link' => 'https://instagram.com/p/z', 'quantity' => 1000]);
    T::eq('4.500000', $ppdb->fetchColumn('SELECT charge FROM orders WHERE id = ?', [$o2['id']]));
    // A discounted user whose price would fall below cost is refused even if the service is not flagged yet.
    $d = $ppService('pp9', '1.100000', '1.000000');
    $vip = Fx::user('100');
    $ppdb->update('users', ['custom_discount' => '50.00'], ['id' => $vip['id']]);
    T::throws(ValidationException::class, fn () => OrderService::place((int) $vip['id'], $d, ['link' => 'https://instagram.com/p/v', 'quantity' => 1000]), 'temporarily unavailable');
    T::eq('100.000000', Fx::balance((int) $vip['id']), 'nothing charged');
    T::eq(1, (int) $svc($d)['price_blocked']);
    $noDiscounts();
});

T::test('Price protection: provider catalog unavailable / failed fetch changes nothing', function () use ($ppService, $ppCatalog, $svc, $events, $mode, $sync, $noDiscounts, $ppProvider) {
    $noDiscounts();
    $mode('protect', '0');
    $id = $ppService('pp10', '2.000000', '1.000000');
    $ppCatalog('pp10', '9.000000', ['avail' => 0]);
    $sync();
    T::eq(['1.000000', '2.000000', 0, 'active'], [$svc($id)['provider_rate'], $svc($id)['rate'], (int) $svc($id)['price_blocked'], $svc($id)['status']]);
    T::eq([], $events($id));
    // Fetch fails (provider error): catalog and prices untouched.
    $ppCatalog('pp10', '1.000000');
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['error' => 'Invalid API key'])]);
    try {
        ProviderSyncService::fetchCatalog($ppProvider);
    } catch (\Throwable) {
    }
    T::eq(['1.000000', '2.000000', 0], [$svc($id)['provider_rate'], $svc($id)['rate'], (int) $svc($id)['price_blocked']]);
});

T::test('Price protection (admin): settings validated, price-change page lists events and blocked services', function () use ($ppService, $ppCatalog, $mode, $sync, $noDiscounts, $ppdb) {
    $noDiscounts();
    $mode('protect', '0');
    $id = $ppService('pp11', '1.000000', '0.500000');
    $ppCatalog('pp11', '3.000000');
    $sync();
    $admin = (int) $ppdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $ppdb->insert('admins', ['username' => 'ppadmin', 'email' => 'ppadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $html = http('GET', '/' . admin_path() . '/services/price-changes')->body();
    T::true(str_contains($html, 'PP service pp11'), 'a'); T::true(str_contains($html, 'Ordering blocked'), 'b'); T::true(str_contains($html, 'Needs attention'), 'c');
    $list = http('GET', '/' . admin_path() . '/services', ['status' => 'price', 'q' => 'pp11'])->body();
    T::true(str_contains($list, 'PP service pp11') && str_contains($list, 'Price blocked'));
    $ordersTab = ['_token' => csrf(), 'tab' => 'orders', 'min_order_amount' => '0', 'mass_order_enabled' => '1', 'mass_order_max_lines' => '100', 'order_cancel_enabled' => '1', 'refill_enabled' => '1', 'order_sync_batch' => '100', 'subscriptions_enabled' => '1', 'subscription_max_cycles' => '100'];
    http('POST', '/' . admin_path() . '/settings', ['price_protection_mode' => 'nonsense', 'price_protection_margin' => '5'] + $ordersTab);
    T::eq('protect', setting('price_protection_mode'));
    http('POST', '/' . admin_path() . '/settings', ['price_protection_mode' => 'auto', 'price_protection_margin' => '-1'] + $ordersTab);
    T::eq('protect', setting('price_protection_mode'), 'negative margin refused');
    http('POST', '/' . admin_path() . '/settings', ['price_protection_mode' => 'auto', 'price_protection_margin' => '10'] + $ordersTab);
    T::eq(['auto', '10'], [(string) setting('price_protection_mode'), (string) setting('price_protection_margin')]);
    // Switching to auto re-evaluates immediately: the blocked service is repriced to 3.30.
    T::eq(['3.300000', 0], [$ppdb->fetchColumn('SELECT rate FROM services WHERE id = ?', [$id]), (int) $ppdb->fetchColumn('SELECT price_blocked FROM services WHERE id = ?', [$id])]);
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    // Users never see the admin page.
    $mode('protect', '0');
});

T::test('Price protection: restore discounts changed by these tests', function () use ($ppLevels, $ppUsers, $ppdb) {
    foreach ($ppLevels as $id => $d) {
        $ppdb->update('price_levels', ['discount_percent' => $d], ['id' => $id]);
    }
    foreach ($ppUsers as $id => $d) {
        $ppdb->update('users', ['custom_discount' => $d], ['id' => $id]);
    }
    PriceProtection::reset();
    T::true(true);
});
