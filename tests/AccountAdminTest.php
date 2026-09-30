<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\SettingsService;
use App\Services\WalletService;

/*
 * Registration settings (mobile, email verification), admin balance add/remove
 * with ledger + audit, and admin order-status transitions.
 */

$reg = static function (array $extra = []): array {
    static $n = 0;
    $n++;
    return AuthService::register($extra + ['username' => 'reguser' . $n . 'x', 'email' => "reg{$n}x@example.com", 'password' => 'Passw0rd99', 'password_confirmation' => 'Passw0rd99', 'terms' => '1'], '10.0.' . intdiv($n, 200) . '.' . ($n % 200));
};
$withSettings = static function (array $s, callable $fn): void {
    $old = [];
    foreach ($s as $k => $v) {
        $old[$k] = (string) setting($k);
        SettingsService::set($k, $v);
    }
    try {
        $fn();
    } finally {
        foreach ($old as $k => $v) {
            SettingsService::set($k, $v);
        }
    }
};
$db = Database::instance();
$superId = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'accadmin', 'email' => 'accadmin@example.com', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$supportId = $db->insert('admins', ['username' => 'accsupport', 'email' => 'accsupport@example.com', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 0, 'created_at' => now(), 'updated_at' => now()]);
$db->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Support'", [$supportId]);

// ------------------------------------------------------------------ registration: mobile

T::test('Registration: mobile OFF → not required and never stored', function () use ($reg, $withSettings) {
    $withSettings(['registration_mobile' => '0'], function () use ($reg) {
        T::eq('off', AuthService::mobileMode());
        $u = $reg(['mobile' => '+44 7700 900123']);
        T::eq(null, $u['mobile']);
        T::true(!str_contains(http('GET', '/register')->body(), 'name="mobile"'), 'field hidden');
    });
});

T::test('Registration: mobile ON + "optional" → validated and normalized when given, may be empty', function () use ($reg, $withSettings) {
    $withSettings(['registration_mobile' => '1', 'registration_mobile_optional' => '1'], function () use ($reg) {
        T::eq('optional', AuthService::mobileMode());
        T::true(str_contains(http('GET', '/register')->body(), 'name="mobile"'));
        T::eq(null, $reg()['mobile']);
        T::eq('+447700900123', $reg(['mobile' => '+44 (7700) 900-123'])['mobile']);
        T::eq('+447700900123', $reg(['mobile' => '0044 7700 900123'])['mobile'], '00 prefix → +');
        foreach (['12345', 'abc12345678', '+0 1234567', '+1234567890123456', '<script>'] as $bad) {
            T::throws(ValidationException::class, fn () => $reg(['mobile' => $bad]), 'valid mobile');
        }
    });
});

T::test('Registration: mobile ON → required: empty rejected; switching the field off never breaks existing users', function () use ($reg, $withSettings) {
    $withSettings(['registration_mobile' => '1', 'registration_mobile_optional' => '0'], function () use ($reg) {
        T::eq('required', AuthService::mobileMode(), 'ON alone means required');
        T::throws(ValidationException::class, fn () => $reg(), 'Enter your mobile');
        T::eq('+15550100123', $reg(['mobile' => '+1 555 010 0123'])['mobile']);
    });
    $withSettings(['registration_mobile' => '0', 'registration_mobile_optional' => '0'], function () use ($reg) {
        T::eq('off', AuthService::mobileMode(), 'nothing is required while the field is off');
        T::eq(null, $reg()['mobile']);
    });
});

T::test('Registration (HTTP): admin switches Mobile ON/OFF in Settings; the register page and backend follow', function () use ($withSettings, $superId) {
    $db = Database::instance();
    $settingsPost = static fn (string $mobile, string $optional) => http('POST', '/' . admin_path() . '/settings', ['_token' => csrf(), 'tab' => 'users', 'registration_enabled' => '1', 'email_verification' => '0',
        'registration_mobile' => $mobile, 'registration_mobile_optional' => $optional, 'google_login_enabled' => '0', 'google_client_id' => '', 'login_max_attempts' => '5', 'login_lockout_minutes' => '15', 'default_price_level' => '']);
    $register = static function (string $name, array $extra = []) {
        App\Services\Auth::logoutUser();
        return http('POST', '/register', $extra + ['_token' => csrf(), 'username' => $name, 'email' => $name . '@example.com', 'password' => 'Passw0rd99', 'password_confirmation' => 'Passw0rd99', 'terms' => '1'], ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 250)]);
    };
    $withSettings(['registration_mobile' => '0', 'registration_mobile_optional' => '0', 'email_verification' => '0'], function () use ($settingsPost, $register, $db, $superId) {
        login_as_admin($superId);
        $settingsPost('1', '0');
        T::eq(['1', '0'], [setting('registration_mobile'), setting('registration_mobile_optional')]);
        unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
        App\Services\Auth::logoutUser();
        $page = http('GET', '/register')->body();
        T::true((bool) preg_match('/<input[^>]+name="mobile"[^>]+required/', $page), 'field shown and required');
        $register('mobhttp1');
        T::eq(null, $db->fetchColumn("SELECT id FROM users WHERE username = 'mobhttp1'"), 'rejected server-side without a number');
        T::true(str_contains(end($_SESSION['_flash'])['message'] ?? '', 'Enter your mobile number'));
        $register('mobhttp2', ['mobile' => '+44 7700 900111']);
        T::eq('+447700900111', $db->fetchColumn("SELECT mobile FROM users WHERE username = 'mobhttp2'"));

        login_as_admin($superId);
        $settingsPost('0', '0');
        unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
        App\Services\Auth::logoutUser();
        T::true(!str_contains(http('GET', '/register')->body(), 'name="mobile"'), 'field not rendered when OFF');
        $register('mobhttp3', ['mobile' => '+44 7700 900222']);
        T::eq([null], [$db->fetchColumn("SELECT mobile FROM users WHERE username = 'mobhttp3'")], 'accepted without a number; a posted number is ignored');
        T::true((bool) $db->fetchColumn("SELECT id FROM users WHERE username = 'mobhttp3'"));
        App\Services\Auth::logoutUser();
    });
});

// ------------------------------------------------------------------ email verification

T::test('Verification OFF: new users are never blocked', function () use ($reg, $withSettings) {
    $withSettings(['email_verification' => '0'], function () use ($reg) {
        $u = $reg();
        T::true(!AuthService::needsVerification($u));
        login_as_user($u);
        T::eq(200, http('GET', '/dashboard')->status());
    });
});

T::test('Verification ON: new user gets a secure, expiring token; blocked until verified; resend works', function () use ($reg, $withSettings) {
    $withSettings(['email_verification' => '1', 'email_verification_since' => gmdate('Y-m-d H:i:s', time() - 60)], function () use ($reg) {
        $db = Database::instance();
        $u = $reg();
        T::true(AuthService::needsVerification($u));
        $row = $db->fetch('SELECT * FROM email_verifications WHERE user_id = ?', [$u['id']]);
        T::true((bool) preg_match('/^[a-f0-9]{64}$/', $row['token_hash']), 'only a SHA-256 hash is stored');
        $ttl = strtotime($row['expires_at'] . ' UTC') - time();
        T::true($ttl > AuthService::VERIFY_TTL - 60 && $ttl <= AuthService::VERIFY_TTL, 'expires after 48h');
        login_as_user($u);
        $r = http('GET', '/dashboard');
        T::eq(302, $r->status());
        T::true(str_contains((string) $r->header('Location'), '/verify-email'));
        $resend = http('POST', '/verify-email/resend', ['_token' => csrf()]);
        T::eq(302, $resend->status());
        $new = $db->fetch('SELECT * FROM email_verifications WHERE user_id = ?', [$u['id']]);
        T::true($new['token_hash'] !== $row['token_hash'], 'resend issues a fresh token (old one invalidated)');
        T::eq(1, (int) $db->fetchColumn('SELECT COUNT(*) FROM email_verifications WHERE user_id = ?', [$u['id']]));
        // Verify with a known token.
        $token = bin2hex(random_bytes(32));
        $db->update('email_verifications', ['token_hash' => hash('sha256', $token)], ['user_id' => $u['id']]);
        T::true(!AuthService::verifyEmail(bin2hex(random_bytes(32))), 'unknown token rejected');
        T::true(AuthService::verifyEmail($token));
        T::true(!AuthService::verifyEmail($token), 'single use');
        T::eq(200, http('GET', '/dashboard')->status());
    });
});

T::test('Verification: expired tokens are rejected', function () use ($reg, $withSettings) {
    $withSettings(['email_verification' => '1', 'email_verification_since' => ''], function () use ($reg) {
        $u = $reg();
        $token = bin2hex(random_bytes(32));
        Database::instance()->update('email_verifications', ['token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['user_id' => $u['id']]);
        T::true(!AuthService::verifyEmail($token));
        T::true(AuthService::needsVerification(Database::instance()->fetch('SELECT * FROM users WHERE id = ?', [$u['id']])));
    });
});

T::test('Verification: switching it ON via admin settings does not lock out existing users (unless they change email)', function () use ($reg, $withSettings, $superId) {
    $withSettings(['email_verification' => '0', 'email_verification_since' => ''], function () use ($reg, $superId) {
        $db = Database::instance();
        $existing = $reg();
        $db->update('users', ['created_at' => gmdate('Y-m-d H:i:s', time() - 86400)], ['id' => $existing['id']]);
        login_as_admin($superId);
        http('POST', '/' . admin_path() . '/settings', ['_token' => csrf(), 'tab' => 'users', 'registration_enabled' => '1', 'email_verification' => '1', 'registration_mobile' => '0', 'registration_mobile_required' => '0', 'login_max_attempts' => '5', 'login_lockout_minutes' => '15', 'default_price_level' => '']);
        SettingsService::flush();
        T::eq('1', setting('email_verification'));
        T::true(setting('email_verification_since') !== '', 'switch-on time recorded');
        $old = $db->fetch('SELECT * FROM users WHERE id = ?', [$existing['id']]);
        T::true(!AuthService::needsVerification($old), 'existing unverified account keeps working');
        $new = $reg();
        T::true(AuthService::needsVerification($new), 'new accounts must verify');
        $db->update('users', ['email_verified_at' => null, 'email_changed_at' => now()], ['id' => $existing['id']]);
        T::true(AuthService::needsVerification($db->fetch('SELECT * FROM users WHERE id = ?', [$existing['id']])), 'changing email requires verification');
    });
});

// ------------------------------------------------------------------ balance add / remove

T::test('Balance: admin add and remove create ledger rows with before/after/admin and audit entries', function () use ($superId) {
    $u = Fx::user('10');
    $db = Database::instance();
    $add = WalletService::adminAdjust((int) $u['id'], '5.25', 'Compensation for delay', $superId, 'manual_adjustment', str_repeat('a', 32));
    T::eq('15.250000', Fx::balance((int) $u['id']));
    $tx = $db->fetch('SELECT * FROM transactions WHERE id = ?', [$add['transaction_id']]);
    T::eq(['10.000000', '5.250000', '15.250000', $superId], [$tx['balance_before'], $tx['amount'], $tx['balance_after'], (int) $tx['admin_id']]);
    T::true(str_contains($tx['description'], 'Compensation for delay') && $tx['created_at'] !== null);
    $rem = WalletService::adminAdjust((int) $u['id'], '-3', 'Chargeback', $superId, 'manual_adjustment', str_repeat('b', 32));
    T::eq('12.250000', Fx::balance((int) $u['id']));
    $audit = json_decode((string) $db->fetchColumn("SELECT details FROM audit_logs WHERE action = 'wallet.adjust' AND target_id = ? ORDER BY id DESC LIMIT 1", [$u['id']]), true);
    T::eq(['-3.000000', 'Chargeback', '15.250000', '12.250000', (int) $rem['transaction_id']], [$audit['amount'], $audit['reason'], $audit['balance_before'], $audit['balance_after'], (int) $audit['tx']]);
});

T::test('Balance: reason required, no negative balance, idempotent double submit', function () use ($superId) {
    $u = Fx::user('5');
    T::throws(ValidationException::class, fn () => WalletService::adminAdjust((int) $u['id'], '1', '', $superId), 'reason');
    T::throws(ValidationException::class, fn () => WalletService::adminAdjust((int) $u['id'], '-5.01', 'too much', $superId), 'Insufficient');
    T::eq('5.000000', Fx::balance((int) $u['id']));
    $key = str_repeat('c', 32);
    WalletService::adminAdjust((int) $u['id'], '2', 'Bonus A', $superId, 'bonus', $key);
    $again = WalletService::adminAdjust((int) $u['id'], '2', 'Bonus A', $superId, 'bonus', $key);
    T::true($again['duplicate']);
    T::eq('7.000000', Fx::balance((int) $u['id']), 'applied once');
    Database::instance()->update('wallets', ['allow_negative' => 1], ['user_id' => $u['id']]);
    WalletService::adminAdjust((int) $u['id'], '-10', 'Credit line', $superId);
    T::eq('-3.000000', Fx::balance((int) $u['id']), 'negative only where the business rule allows it');
});

T::test('Balance: HTTP add/remove — permission, CSRF, adjustments history tab', function () use ($superId, $supportId) {
    $u = Fx::user('10');
    $url = '/' . admin_path() . '/users/' . $u['id'] . '/balance';
    login_as_admin($supportId); // Support role: no users.balance
    $e = null;
    try {
        http('POST', $url, ['_token' => csrf(), 'direction' => 'add', 'amount' => '5', 'reason' => 'nope']);
    } catch (HttpException $ex) {
        $e = $ex;
    }
    T::eq(403, $e?->getStatus());
    login_as_admin($superId);
    T::throws(HttpException::class, fn () => http('POST', $url, ['direction' => 'add', 'amount' => '5', 'reason' => 'no csrf']));
    $key = bin2hex(random_bytes(16));
    http('POST', $url, ['_token' => csrf(), 'direction' => 'add', 'amount' => '5', 'reason' => 'Promo credit', 'adjust_key' => $key]);
    http('POST', $url, ['_token' => csrf(), 'direction' => 'add', 'amount' => '5', 'reason' => 'Promo credit', 'adjust_key' => $key]);
    T::eq('15.000000', Fx::balance((int) $u['id']), 'double-submitted form applied once');
    http('POST', $url, ['_token' => csrf(), 'direction' => 'subtract', 'amount' => '2.5', 'reason' => 'Correction', 'adjust_key' => bin2hex(random_bytes(16))]);
    T::eq('12.500000', Fx::balance((int) $u['id']));
    $html = http('GET', '/' . admin_path() . '/users/' . $u['id'], ['tab' => 'adjustments'])->body();
    T::true(str_contains($html, 'Promo credit') && str_contains($html, 'Correction') && str_contains($html, '15.0000'), 'history shows reason and before/after');
});

// ------------------------------------------------------------------ admin order status

T::test('Order status: valid transitions only, reason required, final orders locked', function () use ($superId) {
    $u = Fx::user('20');
    $svc = Fx::service(null, ['name' => 'Manual status svc']);
    $o = OrderService::place((int) $u['id'], $svc, ['link' => 'https://x.com/s1', 'quantity' => '1000']);
    $id = (int) $o['id'];
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus($id, 'processing', null, null, $superId, ''), 'reason');
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus($id, 'refunded', null, null, $superId, 'bad'), 'not allowed');
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus($id, 'pending', null, null, $superId, 'same'), 'not allowed');
    T::eq('0.000000', OrderService::adminSetStatus($id, 'processing', 100, null, $superId, 'Started manually'));
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus($id, 'pending', null, null, $superId, 'back'), 'not allowed');
    T::eq('0.000000', OrderService::adminSetStatus($id, 'completed', null, null, $superId, 'Delivered'));
    T::eq('17.500000', Fx::balance((int) $u['id']), 'completed moves no money');
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus($id, 'cancelled', null, null, $superId, 'reopen'), 'final');
    $log = Database::instance()->fetch("SELECT * FROM order_logs WHERE order_id = ? AND event = 'admin_status' ORDER BY id DESC LIMIT 1", [$id]);
    T::eq(['processing', 'completed', 'admin:' . $superId], [$log['old_status'], $log['new_status'], $log['actor']]);
    T::true(str_contains((string) $log['message'], 'Delivered'));
    $audit = json_decode((string) Database::instance()->fetchColumn("SELECT details FROM audit_logs WHERE action = 'order.status' AND target_id = ? ORDER BY id DESC LIMIT 1", [$id]), true);
    T::eq(['processing', 'completed', 'Delivered'], [$audit['from'], $audit['to'], $audit['reason']]);
});

T::test('Order status: cancel refunds exactly once; partial refunds only the undelivered part', function () use ($superId) {
    $u = Fx::user('20');
    $svc = Fx::service(null, ['name' => 'Manual refund svc']);
    $a = OrderService::place((int) $u['id'], $svc, ['link' => 'https://x.com/r1', 'quantity' => '2000']); // 5.00
    T::eq('5.000000', OrderService::adminSetStatus((int) $a['id'], 'cancelled', null, null, $superId, 'Provider out of stock'));
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus((int) $a['id'], 'cancelled', null, null, $superId, 'again'), 'final');
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE order_id = ? AND type = 'refund'", [$a['id']]));
    T::eq('20.000000', Fx::balance((int) $u['id']));
    $b = OrderService::place((int) $u['id'], $svc, ['link' => 'https://x.com/r2', 'quantity' => '2000']);
    T::throws(ValidationException::class, fn () => OrderService::adminSetStatus((int) $b['id'], 'partial', null, 2000, $superId, 'all missing'), 'partial');
    T::eq('1.250000', OrderService::adminSetStatus((int) $b['id'], 'partial', 0, 500, $superId, '500 not delivered'));
    T::eq('16.250000', Fx::balance((int) $u['id']));
});
