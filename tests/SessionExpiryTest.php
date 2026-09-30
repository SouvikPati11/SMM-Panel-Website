<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Auth;

/*
 * Forms submitted after the session or its CSRF token expired: never
 * processed, never a misleading 404/419 page for normal browser forms, clear
 * message, redirect back to the same page (tab kept), typed values restored
 * (secrets never), sign-in first when the login expired. CSRF stays strict.
 */

$sxdb = Database::instance();
$sxAdmin = (int) $sxdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $sxdb->insert('admins', ['username' => 'sxadmin', 'email' => 'sxadmin@example.com', 'password_hash' => password_hash('SxAdminPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$sxAdminRow = $sxdb->fetch('SELECT * FROM admins WHERE id = ?', [$sxAdmin]);
$sxdb->update('admins', ['password_hash' => password_hash('SxAdminPass123', PASSWORD_DEFAULT)], ['id' => $sxAdmin]);
$settingsUrl = static fn () => url('/' . admin_path() . '/settings?tab=orders');
$sameSite = static fn () => ['HTTP_REFERER' => url('/' . admin_path() . '/settings?tab=orders'), 'HTTP_ORIGIN' => rtrim(url('/'), '/')];
$ordersForm = static fn (array $extra = []) => $extra + ['tab' => 'orders', 'min_order_amount' => '0', 'mass_order_enabled' => '1', 'mass_order_max_lines' => '321', 'order_cancel_enabled' => '1', 'refill_enabled' => '1', 'order_sync_batch' => '100', 'subscriptions_enabled' => '1', 'subscription_max_cycles' => '100', 'price_protection_mode' => 'protect', 'price_protection_margin' => '0'];
$flashText = static fn () => implode(' | ', array_column($_SESSION['_flash'] ?? [], 'message'));
$logoutAll = static function (): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['_restore'], $_SESSION['_flash'], $_SESSION['_old'], $_SESSION['_old_current'], $_SESSION['admin_intended']);
};

T::test('Settings: immediate save with a valid token still works', function () use ($sxAdmin, $ordersForm, $logoutAll) {
    $logoutAll();
    login_as_admin($sxAdmin);
    $r = http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => csrf(), 'mass_order_max_lines' => '222']));
    T::eq(302, $r->status());
    T::eq('222', (string) setting('mass_order_max_lines'));
    T::true(str_ends_with((string) $r->header('Location'), '/settings?tab=orders'));
});

T::test('Settings: stale CSRF token while signed in → 303 back to the same tab, nothing saved, typed values restored', function () use ($sxAdmin, $ordersForm, $sameSite, $flashText, $logoutAll) {
    $logoutAll();
    login_as_admin($sxAdmin);
    csrf();
    $r = http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => 'stale-token-from-an-old-page', 'mass_order_max_lines' => '444', 'recaptcha_secret_key' => 'should-never-be-kept']), $sameSite());
    T::eq(303, $r->status(), 'not 404, not 419');
    T::true(str_ends_with((string) $r->header('Location'), '/' . admin_path() . '/settings?tab=orders'), 'same page and tab');
    T::eq('222', (string) setting('mass_order_max_lines'), 'nothing saved');
    T::true(str_contains($flashText(), 'security token expired') && str_contains($flashText(), 'nothing was saved'));
    T::true(!str_contains(json_encode($_SESSION['_restore']), 'should-never-be-kept'), 'secrets are never kept');
    $page = http('GET', '/' . admin_path() . '/settings', ['tab' => 'orders']);
    T::eq(200, $page->status());
    T::true(str_contains($page->body(), 'value="444"'), 'typed value restored');
    T::true(str_contains($page->body(), 'unsaved changes were restored'));
    T::true(!isset($_SESSION['_restore']), 'restored once');
    T::true(!str_contains(http('GET', '/' . admin_path() . '/settings', ['tab' => 'orders'])->body(), 'value="444"'), 'not restored again');
    // Saving again (now with a fresh token) works.
    http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => csrf(), 'mass_order_max_lines' => '444']));
    T::eq('444', (string) setting('mass_order_max_lines'));
});

T::test('Settings: expired admin session → sign in → back on the same tab with the changes restored', function () use ($sxAdminRow, $ordersForm, $sameSite, $flashText, $logoutAll) {
    $logoutAll();
    unset($_SESSION['_csrf']); // the whole session is gone (idle timeout / garbage collection)
    $r = http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => 'token-of-the-expired-session', 'mass_order_max_lines' => '555']), $sameSite());
    T::eq(303, $r->status());
    T::eq('444', (string) setting('mass_order_max_lines'), 'nothing saved');
    T::true(str_contains($flashText(), 'session expired') && str_contains($flashText(), 'Sign in again'));
    $g = http('GET', '/' . admin_path() . '/settings', ['tab' => 'orders']);
    T::eq(302, $g->status());
    T::true(str_ends_with((string) $g->header('Location'), '/' . admin_path() . '/login'));
    T::true(isset($_SESSION['_restore']), 'kept through the sign-in redirect');
    $login = http('GET', '/' . admin_path() . '/login')->body();
    T::true(str_contains($login, 'session expired'), 'message shown on the sign-in page');
    $in = http('POST', '/' . admin_path() . '/login', ['_token' => csrf(), 'login' => $sxAdminRow['username'], 'password' => 'SxAdminPass123']);
    T::true(str_ends_with((string) $in->header('Location'), '/' . admin_path() . '/settings?tab=orders'), 'returns to the same tab: ' . $in->header('Location'));
    $page = http('GET', '/' . admin_path() . '/settings', ['tab' => 'orders']);
    T::eq(200, $page->status());
    T::true(str_contains($page->body(), 'value="555"'));
});

T::test('Settings: cross-site POST without a valid token is refused (403) and nothing is restored; JSON gets 419', function () use ($sxAdmin, $ordersForm, $logoutAll) {
    $logoutAll();
    login_as_admin($sxAdmin);
    $threw = null;
    try {
        http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => 'x', 'mass_order_max_lines' => '999']), ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/page']);
    } catch (App\Core\Exceptions\HttpException $e) {
        $threw = $e->getStatus();
    }
    T::eq(403, $threw);
    T::true(!isset($_SESSION['_restore']), 'no pre-filled form for the attacker');
    T::eq('444', (string) setting('mass_order_max_lines'));
    $j = http('POST', '/' . admin_path() . '/settings', $ordersForm(['_token' => 'x']), ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    T::eq(419, $j->status());
    T::eq('session_expired', json_decode($j->body(), true)['code'] ?? null);
    // No token at all from the same site is still refused (nothing saved).
    http('POST', '/' . admin_path() . '/settings', $ordersForm(['mass_order_max_lines' => '777']), ['HTTP_REFERER' => url('/' . admin_path() . '/settings?tab=orders')]);
    T::eq('444', (string) setting('mass_order_max_lines'));
    $logoutAll();
});

T::test('User forms: expired token on the order page redirects back with a message, not an error page', function () use ($logoutAll) {
    $logoutAll();
    $u = Fx::user('10');
    login_as_user($u);
    csrf();
    $r = http('POST', '/tickets', ['_token' => 'old', 'subject' => 'Kept subject', 'message' => 'Hello'], ['HTTP_REFERER' => url('/tickets/new')]);
    T::eq(303, $r->status());
    T::true(str_ends_with((string) $r->header('Location'), '/tickets/new'));
    T::true(str_contains(http('GET', '/tickets/new')->body(), 'Kept subject'), 'subject restored');
    Auth::logoutUser();
});
