<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Install\Migrator;
use App\Services\PaymentService;
use App\Services\SettingsService;

/*
 * Deposit limits are configured per payment gateway and enforced on the
 * server; the old global min/max settings are converted once by migration.
 */

$gdb = Database::instance();
$method = static function (string $name, string $min, string $max, string $gateway = 'manual') use ($gdb): array {
    $id = $gdb->insert('payment_methods', ['gateway' => $gateway, 'name' => $name, 'min_amount' => $min, 'max_amount' => $max, 'fee_percent' => '0', 'require_proof' => 0, 'status' => 'active', 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()]);
    return $gdb->fetch('SELECT * FROM payment_methods WHERE id = ?', [$id]);
};

T::test('Gateway limits: each gateway enforces its own minimum/maximum; the old global setting no longer applies', function () use ($method) {
    $a = $method('Gateway A', '5', '1000');
    $b = $method('Gateway B', '10', '2000');
    $c = $method('Gateway C', '1', '50');
    SettingsService::setMany(['min_deposit' => '50', 'max_deposit' => '60']); // legacy values must be ignored
    T::eq('5.00', PaymentService::validateAmount($a, '5')[0]);
    T::throws(ValidationException::class, fn () => PaymentService::validateAmount($a, '4.99'), 'minimum deposit with Gateway A is $5.00');
    T::throws(ValidationException::class, fn () => PaymentService::validateAmount($b, '9.99'), 'minimum deposit with Gateway B is $10.00');
    T::eq('10.00', PaymentService::validateAmount($b, '10.00')[0]);
    T::eq('1.00', PaymentService::validateAmount($c, '1')[0], 'lower than the old global minimum');
    T::throws(ValidationException::class, fn () => PaymentService::validateAmount($c, '50.01'), 'maximum deposit with Gateway C is $50.00');
    foreach (['', 'abc', '-5', '0', '1e3', '5.001'] as $bad) {
        T::throws(ValidationException::class, fn () => PaymentService::validateAmount($a, $bad));
    }
    T::eq('5.10', PaymentService::validateAmount($a, '5.100')[0], 'trailing zeros are fine');
    SettingsService::setMany(['min_deposit' => '1', 'max_deposit' => '10000']);
});

T::test('Gateway limits (HTTP): funds page shows the selected gateway limits; server rejects too-small deposits with a clear error', function () use ($method, $gdb) {
    $m = $method('UPI small', '3', '400');
    $u = Fx::user('0');
    login_as_user($u);
    $html = http('GET', '/funds')->body();
    T::true((bool) preg_match('/value="' . $m['id'] . '"[^>]*data-min="3.00" data-max="400.00"/', $html), 'limits carried by the gateway choice');
    T::true(str_contains($html, 'Min $3.00 · max $400.00'));
    $before = (int) $gdb->fetchColumn('SELECT COUNT(*) FROM manual_payment_requests');
    http('POST', '/funds/manual', ['_token' => csrf(), 'method_id' => $m['id'], 'amount' => '2.99', 'reference' => 'UTR111']);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'The minimum deposit with UPI small is $3.00'));
    T::eq($before, (int) $gdb->fetchColumn('SELECT COUNT(*) FROM manual_payment_requests'), 'nothing recorded');
    http('POST', '/funds/manual', ['_token' => csrf(), 'method_id' => $m['id'], 'amount' => '3', 'reference' => 'UTR112']);
    T::eq($before + 1, (int) $gdb->fetchColumn('SELECT COUNT(*) FROM manual_payment_requests'));
    T::eq('0.000000', Fx::balance((int) $u['id']), 'manual deposits are credited only after review');
    App\Services\Auth::logoutUser();
});

T::test('Gateway limits (admin): minimum must be positive and ≤ maximum; settings no longer offer a global minimum', function () use ($gdb) {
    $admin = (int) $gdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $gdb->insert('admins', ['username' => 'gwadmin', 'email' => 'gwadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $id = (int) $gdb->fetchColumn("SELECT id FROM payment_methods WHERE gateway = 'manual' ORDER BY id LIMIT 1");
    $form = http('GET', '/' . admin_path() . '/gateways/' . $id . '/edit')->body();
    T::true(str_contains($form, 'Minimum deposit ('));
    $cur = $gdb->fetch('SELECT * FROM payment_methods WHERE id = ?', [$id]);
    $save = static fn (string $min, string $max) => http('POST', '/' . admin_path() . '/gateways/save', ['_token' => csrf(), 'id' => $id, 'name' => $cur['name'], 'min_amount' => $min, 'max_amount' => $max, 'fee_percent' => '0', 'sort_order' => '0', 'status' => $cur['status']]);
    $save('0', '100');
    T::eq($cur['min_amount'], $gdb->fetchColumn('SELECT min_amount FROM payment_methods WHERE id = ?', [$id]), 'zero minimum rejected');
    $save('20', '10');
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'Maximum must be greater than minimum'));
    $save('2.5', '800');
    T::eq(['2.5000', '800.0000'], array_values($gdb->fetch('SELECT min_amount, max_amount FROM payment_methods WHERE id = ?', [$id])));
    $save(rtrim(rtrim($cur['min_amount'], '0'), '.'), rtrim(rtrim($cur['max_amount'], '0'), '.'));
    $funds = http('GET', '/' . admin_path() . '/settings', ['tab' => 'funds'])->body();
    T::true(!str_contains($funds, 'name="min_deposit"') && str_contains($funds, 'per payment gateway'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Migration: the global deposit limits are folded into each gateway once (effective limits unchanged), re-runs are no-ops', function () use ($gdb, $method) {
    $snapshot = $gdb->fetchAll('SELECT id, min_amount, max_amount FROM payment_methods');
    $low = $method('Legacy low', '1', '10000');
    $high = $method('Legacy high', '20', '300');
    SettingsService::setMany(['min_deposit' => '7', 'max_deposit' => '500', 'deposit_limits_per_gateway' => '0']);
    $gdb->query("DELETE FROM schema_migrations WHERE version = '2026_10_12_subscription_type_oauth_gateway_limits'");
    T::eq(['2026_10_12_subscription_type_oauth_gateway_limits'], Migrator::run());
    $row = static fn (array $m) => $gdb->fetch('SELECT min_amount, max_amount FROM payment_methods WHERE id = ?', [$m['id']]);
    T::eq(['min_amount' => '7.0000', 'max_amount' => '500.0000'], $row($low), 'max(global min, gateway min) / min(global max, gateway max)');
    T::eq(['min_amount' => '20.0000', 'max_amount' => '300.0000'], $row($high));
    T::eq('1', $gdb->fetchColumn("SELECT `value` FROM settings WHERE `key` = 'deposit_limits_per_gateway'"));
    // Running the step again (e.g. schema_migrations lost) must not convert twice.
    SettingsService::setMany(['min_deposit' => '99']);
    $gdb->query("DELETE FROM schema_migrations WHERE version = '2026_10_12_subscription_type_oauth_gateway_limits'");
    Migrator::run();
    T::eq(['min_amount' => '7.0000', 'max_amount' => '500.0000'], $row($low));
    T::eq([], Migrator::run(), 'nothing pending');
    SettingsService::setMany(['min_deposit' => '1', 'max_deposit' => '10000']);
    foreach ($snapshot as $m) { // other tests rely on the seeded limits
        $gdb->update('payment_methods', ['min_amount' => $m['min_amount'], 'max_amount' => $m['max_amount']], ['id' => $m['id']]);
    }
});
