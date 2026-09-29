<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Install\Migrator;
use App\Services\CurrencyService;
use App\Services\ManualPaymentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PriceLevelService;
use App\Services\SettingsService;
use App\Services\WalletService;

/*
 * Display currencies (base currency stays the accounting currency) and price
 * levels by lifetime credited deposits.
 */

$db = Database::instance();
CurrencyService::save(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'position' => 'before', 'decimals' => 2, 'rate' => '0.92', 'enabled' => true]);
CurrencyService::save(['code' => 'INR', 'name' => 'Rupee', 'symbol' => '₹', 'position' => 'after', 'decimals' => 0, 'rate' => '83.123456', 'enabled' => true]);
CurrencyService::save(['code' => 'GBP', 'name' => 'Pound', 'symbol' => '£', 'position' => 'before', 'decimals' => 2, 'rate' => '0.79', 'enabled' => false]);

T::test('Currency: base currency comes from settings; conversions are exact decimals, display only', function () {
    $base = CurrencyService::base();
    T::eq(['USD', '1', true], [$base['code'], $base['rate'], $base['base']]);
    $all = CurrencyService::all();
    T::eq(['USD', 'EUR', 'INR'], array_keys($all), 'disabled currencies are not offered');
    T::eq('€9.20', CurrencyService::format('10', $all['EUR']));
    T::eq('831 ₹', CurrencyService::format('10', $all['INR']), 'currency decimals and position');
    T::eq('0.092000', CurrencyService::convert('0.1', $all['EUR']));
    T::eq('9.199999', CurrencyService::convert('9.99999891', $all['EUR']), 'no float drift (exact half-up)');
    T::eq('€0.0046', CurrencyService::formatRate('0.005', $all['EUR']));
});

T::test('Currency: admin validation — ISO code, positive rate, base not duplicated, code immutable', function () {
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'EURO', 'symbol' => '€', 'rate' => '1']), '3 letters');
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'CHF', 'symbol' => 'Fr', 'rate' => '0']), 'positive');
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'CHF', 'symbol' => 'Fr', 'rate' => 'abc']), 'positive');
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'USD', 'symbol' => '$', 'rate' => '1']), 'base currency');
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'EUR', 'symbol' => '€', 'rate' => '1']), 'already exists');
    T::throws(ValidationException::class, fn () => CurrencyService::save(['code' => 'EUX', 'symbol' => '€', 'rate' => '1'], 'EUR'), 'cannot be changed');
});

T::test('Currency: user selection is persisted, changes display only — balances, ledger and charges stay in base', function () {
    $db = Database::instance();
    $u = Fx::user('10');
    $txBefore = $db->fetchAll('SELECT * FROM transactions WHERE user_id = ?', [$u['id']]);
    T::throws(ValidationException::class, fn () => CurrencyService::setUserCurrency((int) $u['id'], 'GBP'), 'not available');
    T::throws(ValidationException::class, fn () => CurrencyService::setUserCurrency((int) $u['id'], 'XXX'), 'not available');
    login_as_user($u);
    http('POST', '/account/currency', ['_token' => csrf(), 'currency' => 'EUR']);
    T::eq('EUR', $db->fetchColumn('SELECT currency FROM users WHERE id = ?', [$u['id']]));
    T::eq('10.000000', Fx::balance((int) $u['id']), 'balance not converted');
    T::eq($txBefore, $db->fetchAll('SELECT * FROM transactions WHERE user_id = ?', [$u['id']]), 'ledger untouched');
    $html = http('GET', '/dashboard')->body();
    T::true(str_contains($html, '€9.20'), 'balance shown in EUR');
    T::true(str_contains($html, '"code":"EUR"') && str_contains($html, '"rate":"0.92000000"'), 'client formatter gets the same rate');
    $funds = http('GET', '/funds')->body();
    T::true(str_contains($funds, '$10.00') && str_contains($funds, 'credited in <strong>USD</strong>'), 'deposits stay in base currency');
    // Charges are computed and stored in base.
    $svc = Fx::service(null, ['name' => 'Currency charge svc']);
    $o = OrderService::place((int) $u['id'], $svc, ['link' => 'https://x.com/cur', 'quantity' => '1000']);
    T::eq('2.500000', $o['charge']);
    T::eq('7.500000', Fx::balance((int) $u['id']));
    // Admin pages and the API always show base, even for a user with EUR selected.
    $db2 = Database::instance();
    $admin = (int) $db2->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db2->insert('admins', ['username' => 'curadmin', 'email' => 'curadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $adminHtml = http('GET', '/' . admin_path() . '/users/' . $u['id'])->body();
    T::true(str_contains($adminHtml, '$7.5000') && !str_contains($adminHtml, '€6.90'), 'admin sees base currency');
    // Back to base.
    CurrencyService::setUserCurrency((int) $u['id'], 'USD');
    T::eq(null, $db->fetchColumn('SELECT currency FROM users WHERE id = ?', [$u['id']]));
    App\Core\App::$forceInstalled = true;
});

T::test('Currency: switching disabled or currency disabled → base shown, stored choice kept', function () {
    $u = Fx::user('10', ['currency' => 'EUR']);
    login_as_user($u);
    SettingsService::set('currency_switch_enabled', '0');
    try {
        T::true(str_contains(http('GET', '/dashboard')->body(), '$10.00'));
    } finally {
        SettingsService::set('currency_switch_enabled', '1');
    }
    Database::instance()->update('currencies', ['enabled' => 0], ['code' => 'EUR']);
    try {
        T::true(str_contains(http('GET', '/dashboard')->body(), '$10.00'), 'disabled currency falls back to base');
        T::eq('EUR', Database::instance()->fetchColumn('SELECT currency FROM users WHERE id = ?', [$u['id']]));
    } finally {
        Database::instance()->update('currencies', ['enabled' => 1], ['code' => 'EUR']);
    }
});

// ------------------------------------------------------------------ price levels

$db->query('DELETE FROM price_levels');
$L = [];
foreach ([['Level 1', '0', '0'], ['Level 2', '5', '100'], ['Level 3', '10', '500'], ['Level 4', '15', '1000']] as [$n, $d, $min]) {
    $L[$n] = $db->insert('price_levels', ['name' => $n, 'discount_percent' => $d, 'min_deposit' => $min, 'created_at' => now()]);
}
$manualOnly = $db->insert('price_levels', ['name' => 'Partner', 'discount_percent' => '25', 'min_deposit' => null, 'created_at' => now()]);
$method = (int) $db->fetchColumn("SELECT id FROM payment_methods WHERE gateway = 'oxapay'");
$payment = static function (int $userId, string $amount, string $status = 'pending', int $review = 0) use ($method): int {
    return Database::instance()->insert('payments', ['user_id' => $userId, 'payment_method_id' => $method, 'gateway' => 'oxapay', 'amount' => $amount, 'fee' => '0', 'currency' => 'USD', 'status' => $status, 'needs_review' => $review, 'gateway_ref' => 'LVL' . bin2hex(random_bytes(5)), 'created_at' => now(), 'updated_at' => now()]);
};
$level = static fn (int $uid) => (int) Database::instance()->fetchColumn('SELECT price_level_id FROM users WHERE id = ?', [$uid]);

T::test('Levels: a new user starts at the lowest threshold; thresholds use lifetime credited deposits', function () use ($L, $level) {
    $u = Fx::user();
    PriceLevelService::sync((int) $u['id']);
    T::eq($L['Level 1'], $level((int) $u['id']));
    WalletService::apply((int) $u['id'], '99.99', 'deposit', 'lvl:test:1', 'deposit');
    T::eq($L['Level 1'], $level((int) $u['id']), '99.99 < 100');
    WalletService::apply((int) $u['id'], '0.01', 'deposit', 'lvl:test:2', 'deposit');
    T::eq($L['Level 2'], $level((int) $u['id']), 'exactly 100 qualifies');
    WalletService::apply((int) $u['id'], '-50', 'order_charge', 'lvl:test:3', 'spend');
    T::eq($L['Level 2'], $level((int) $u['id']), 'spending does not lower the level (lifetime deposits, not balance)');
    WalletService::apply((int) $u['id'], '900', 'deposit', 'lvl:test:4', 'deposit');
    T::eq($L['Level 4'], $level((int) $u['id']));
    T::eq('1000.000000', PriceLevelService::qualifying((int) $u['id']));
    $p = PriceLevelService::progress(Database::instance()->fetch('SELECT * FROM users WHERE id = ?', [$u['id']]));
    T::eq([null, 'Level 4'], [$p['next'], $p['current']['name']]);
});

T::test('Levels: pending, held-for-review, failed, expired and rejected deposits never qualify', function () use ($L, $level, $payment) {
    $u = Fx::user();
    $uid = (int) $u['id'];
    PriceLevelService::sync($uid);
    $payment($uid, '600', 'pending');
    $payment($uid, '600', 'pending', 1); // held for review
    $payment($uid, '600', 'failed');
    $payment($uid, '600', 'expired');
    $mid = (int) Database::instance()->fetchColumn("SELECT id FROM payment_methods WHERE gateway = 'manual' LIMIT 1");
    Database::instance()->update('payment_methods', ['status' => 'active', 'require_proof' => 0], ['id' => $mid]);
    $req = ManualPaymentService::submit(Database::instance()->fetch('SELECT * FROM users WHERE id = ?', [$uid]), $mid, '600', 'REF' . bin2hex(random_bytes(4)), null, '');
    ManualPaymentService::reject($req, 1, 'Not received');
    T::eq('0.000000', PriceLevelService::qualifying($uid));
    T::eq($L['Level 1'], $level($uid));
    T::eq('0.000000', Fx::balance($uid));
});

T::test('Levels: a credited payment qualifies once (replays never double-count)', function () use ($L, $level, $payment) {
    $u = Fx::user();
    $uid = (int) $u['id'];
    $pid = $payment($uid, '500');
    T::true(PaymentService::complete($pid, 'webhook'));
    T::eq($L['Level 3'], $level($uid));
    T::true(!PaymentService::complete($pid, 'cron'), 'duplicate completion is a no-op');
    T::true(!PaymentService::complete($pid, 'webhook'));
    T::eq('500.000000', PriceLevelService::qualifying($uid));
    // The level discount is applied to prices.
    $svc = Fx::service(null, ['name' => 'Level price svc']); // 2.50 / 1000
    $user = Database::instance()->fetch('SELECT * FROM users WHERE id = ?', [$uid]);
    T::eq('2.250000', OrderService::userRate(Database::instance()->fetch('SELECT * FROM services WHERE id = ?', [$svc]), $user), '10% off');
    // A manually approved manual payment counts too; a held payment approved by an admin counts when credited.
    $held = $payment($uid, '500', 'pending', 1);
    T::eq($L['Level 3'], $level($uid));
    App\Services\PaymentReviewService::approve($held, 1, '500', 'Verified in dashboard');
    T::eq($L['Level 4'], $level($uid));
});

T::test('Levels: manual assignment is kept; "automatic" re-evaluates; manual-only levels are never auto-assigned', function () use ($L, $level, $manualOnly) {
    $u = Fx::user();
    $uid = (int) $u['id'];
    PriceLevelService::assign($uid, $manualOnly);
    WalletService::apply($uid, '2000', 'deposit', 'lvl:manual:1', 'deposit');
    T::eq($manualOnly, $level($uid), 'deposits never override an admin-assigned level');
    PriceLevelService::assign($uid, null);
    T::eq($L['Level 4'], $level($uid));
    $rich = Fx::user();
    WalletService::apply((int) $rich['id'], '99999', 'deposit', 'lvl:rich:1', 'deposit');
    T::true($level((int) $rich['id']) !== $manualOnly, 'manual-only level (no threshold) is never automatic');
});

T::test('Levels: editing thresholds re-evaluates automatic users; admin sees level, qualifying, next and remaining', function () use ($L, $level) {
    $db = Database::instance();
    $u = Fx::user();
    $uid = (int) $u['id'];
    WalletService::apply($uid, '250', 'deposit', 'lvl:edit:1', 'deposit');
    T::eq($L['Level 2'], $level($uid));
    $admin = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1');
    login_as_admin($admin);
    http('POST', '/' . admin_path() . '/price-levels/save', ['_token' => csrf(), 'id' => (string) $L['Level 3'], 'name' => 'Level 3', 'description' => 'Pro resellers', 'discount_percent' => '10', 'min_deposit' => '200']);
    T::eq($L['Level 3'], $level($uid), 'moved up after the threshold was lowered');
    T::eq('Pro resellers', $db->fetchColumn('SELECT description FROM price_levels WHERE id = ?', [$L['Level 3']]));
    $html = http('GET', '/' . admin_path() . '/users/' . $uid)->body();
    T::true(str_contains($html, 'Qualifying deposits') && str_contains($html, '$250.00') && str_contains($html, 'Level 4') && str_contains($html, '$750.00'), 'current, qualifying, next and remaining');
    $levels = http('GET', '/' . admin_path() . '/price-levels')->body();
    T::true(str_contains($levels, 'Pro resellers') && str_contains($levels, 'manual only'));
    http('POST', '/' . admin_path() . '/price-levels/save', ['_token' => csrf(), 'id' => (string) $L['Level 3'], 'name' => 'Level 3', 'description' => '', 'discount_percent' => '10', 'min_deposit' => '100']);
    T::eq(100.0, (float) $db->fetchColumn('SELECT min_deposit FROM price_levels WHERE id = ?', [$L['Level 2']]), 'unchanged');
});

T::test('Levels: migration keeps existing assignments and never auto-promotes (NULL thresholds)', function () {
    $db = Database::instance();
    $step = (new ReflectionMethod(Migrator::class, 'steps'))->invoke(null)['2026_10_05_subscriptions_currency_levels'];
    // Simulate a pre-upgrade install: drop the new columns, restore an old-style level + manual assignment.
    $vip = $db->insert('price_levels', ['name' => 'Old VIP', 'discount_percent' => '20', 'min_deposit' => '0', 'created_at' => now()]);
    $assigned = Fx::user();
    $plain = Fx::user();
    WalletService::apply((int) $plain['id'], '5000', 'deposit', 'mig:plain', 'deposit');
    $db->update('users', ['price_level_id' => $vip], ['id' => $assigned['id']]);
    $db->update('users', ['price_level_id' => null], ['id' => $plain['id']]);
    $db->update('wallets', ['total_deposits' => '1'], ['user_id' => $plain['id']]); // stale cache before upgrade
    $db->pdo()->exec('ALTER TABLE users DROP COLUMN price_level_manual');
    $db->pdo()->exec('ALTER TABLE price_levels DROP COLUMN min_deposit');
    $step($db);
    $step($db); // idempotent
    T::eq(1, (int) $db->fetchColumn('SELECT price_level_manual FROM users WHERE id = ?', [$assigned['id']]), 'existing assignment kept as manual');
    T::eq(0, (int) $db->fetchColumn('SELECT price_level_manual FROM users WHERE id = ?', [$plain['id']]));
    T::eq(0, (int) $db->fetchColumn('SELECT COUNT(*) FROM price_levels WHERE min_deposit IS NOT NULL'), 'existing levels become manual-only');
    T::eq('5000.000000', $db->fetchColumn('SELECT total_deposits FROM wallets WHERE user_id = ?', [$plain['id']]), 'qualifying amount re-derived from the ledger');
    PriceLevelService::syncAll();
    T::eq(null, $db->fetchColumn('SELECT price_level_id FROM users WHERE id = ?', [$plain['id']]), 'nobody auto-promoted by the upgrade');
    T::eq($vip, (int) $db->fetchColumn('SELECT price_level_id FROM users WHERE id = ?', [$assigned['id']]));
});
