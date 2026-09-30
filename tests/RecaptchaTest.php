<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpResponse;
use App\Services\Auth;
use App\Services\RecaptchaService;
use App\Services\SettingsService;

/*
 * Google reCAPTCHA on Login / Sign up (Admin → Settings → Users):
 * OFF = no widget, no call; ON = server-side siteverify before any credential
 * check or account creation. Google is faked at the HTTP layer.
 */

$rcdb = Database::instance();
$rcCalls = [];
/** Fake siteverify: $answer(form) returns the JSON Google would send. */
$rcFake = static function (?callable $answer = null) use (&$rcCalls): void {
    $rcCalls = [];
    Fx::http([RecaptchaService::VERIFY_URL => static function ($m, $u, $o) use (&$rcCalls, $answer) {
        $rcCalls[] = $o['form'];
        if ($answer) {
            return $answer($o['form']);
        }
        return Fx::json($o['form']['response'] === 'good-token' ? ['success' => true, 'hostname' => 'panel.test'] : ['success' => false, 'error-codes' => ['invalid-input-response']]);
    }]);
};
$rcOn = static function (string $version = 'v2', string $score = '0.5'): void {
    SettingsService::setMany(['recaptcha_enabled' => '1', 'recaptcha_version' => $version, 'recaptcha_site_key' => '6LtestSiteKey_abc', 'recaptcha_secret_key' => 'rc-secret-XYZ', 'recaptcha_min_score' => $score]);
};
$rcOff = static fn () => SettingsService::setMany(['recaptcha_enabled' => '0']);
$rcOut = static function (): void {
    Auth::logoutUser();
    unset($_SESSION['2fa_pending_user']);
};
$rcFlash = static fn () => !empty($_SESSION['_flash']) ? (string) (end($_SESSION['_flash'])['message'] ?? '') : '';
// Each call from its own address so the login throttle (10/min per IP) never interferes.
$rcLogin = static fn (array $u, array $extra = [], ?string $ip = null) => http('POST', '/login', $extra + ['_token' => csrf(), 'login' => $u['username'], 'password' => 'Secret123'], ['REMOTE_ADDR' => $ip ?? '203.0.113.' . random_int(1, 250)]);
$rcRegister = static fn (string $name, array $extra = []) => http('POST', '/register', $extra + ['_token' => csrf(), 'username' => $name, 'email' => $name . '@example.com', 'password' => 'Passw0rd99', 'password_confirmation' => 'Passw0rd99', 'terms' => '1'], ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 250)]);

T::test('reCAPTCHA OFF: no widget or Google script, no verification call; login and sign-up work as before', function () use ($rcFake, $rcOff, $rcOut, $rcLogin, $rcRegister, &$rcCalls, $rcdb) {
    $rcOut();
    $rcOff();
    $rcFake();
    foreach (['/login', '/register'] as $page) {
        $r = http('GET', $page);
        T::true(!str_contains($r->body(), 'g-recaptcha') && !str_contains($r->body(), 'recaptcha/api.js'), $page);
        T::true(!str_contains((string) $r->header('Content-Security-Policy'), 'google.com'), 'default CSP');
    }
    $u = Fx::user('0');
    T::true(str_contains((string) $rcLogin($u)->header('Location'), '/dashboard'));
    $rcOut();
    $rcRegister('rcoffuser');
    T::eq(1, (int) $rcdb->fetchColumn("SELECT COUNT(*) FROM users WHERE username = 'rcoffuser'"));
    T::eq([], $rcCalls, 'Google never called while OFF');
    $rcOut();
});

T::test('reCAPTCHA ON (v2): widget + CSP on both forms; the secret key is never sent to the browser', function () use ($rcOn, $rcOff, $rcOut) {
    $rcOut();
    $rcOn('v2');
    foreach (['/login', '/register'] as $page) {
        $r = http('GET', $page);
        $b = $r->body();
        T::true(str_contains($b, 'class="g-recaptcha" data-sitekey="6LtestSiteKey_abc"') && str_contains($b, 'https://www.google.com/recaptcha/api.js'), $page);
        T::true(!str_contains($b, 'rc-secret-XYZ'), 'secret not rendered');
        $csp = (string) $r->header('Content-Security-Policy');
        T::true(str_contains($csp, "script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/") && str_contains($csp, 'frame-src https://www.google.com/recaptcha/'), $csp);
    }
    // Other pages keep the strict default policy.
    T::true(!str_contains((string) http('GET', '/forgot-password')->header('Content-Security-Policy'), 'google.com'));
    // Enabled but no keys → treated as OFF (never locks everyone out).
    SettingsService::setMany(['recaptcha_site_key' => '']);
    T::true(!str_contains(http('GET', '/login')->body(), 'g-recaptcha'));
    $rcOff();
});

T::test('reCAPTCHA ON: login refused without / with a bad token (no authentication, inline error); good token signs in', function () use ($rcOn, $rcOff, $rcOut, $rcFake, $rcLogin, $rcFlash, &$rcCalls, $rcdb) {
    $rcOut();
    $rcOn('v2');
    $rcFake();
    $u = Fx::user('0');
    $before = (int) $rcdb->fetchColumn('SELECT COUNT(*) FROM login_attempts');
    $r = $rcLogin($u);
    T::eq(302, $r->status());
    T::true(str_ends_with((string) $r->header('Location'), '/login') || str_contains((string) $r->header('Location'), '/login'), 'back to the form');
    T::true(!isset($_SESSION['user_id']), 'not authenticated');
    T::true(str_contains($rcFlash(), 'not a robot'));
    T::eq([], $rcCalls, 'missing token: rejected without calling Google');
    $rcLogin($u, ['g-recaptcha-response' => 'forged-token'], '198.51.100.7');
    T::true(!isset($_SESSION['user_id']));
    T::eq(1, count($rcCalls));
    T::eq(['rc-secret-XYZ', 'forged-token', '198.51.100.7'], [$rcCalls[0]['secret'], $rcCalls[0]['response'], $rcCalls[0]['remoteip']], 'secret sent server-to-server only');
    T::eq($before, (int) $rcdb->fetchColumn('SELECT COUNT(*) FROM login_attempts'), 'password not even checked');
    // Wrong password with a valid token still fails normally.
    $rcLogin($u, ['g-recaptcha-response' => 'good-token', 'password' => 'Wrong123']);
    T::true(!isset($_SESSION['user_id']));
    $r = $rcLogin($u, ['g-recaptcha-response' => 'good-token']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
    $rcOut();
    $rcOff();
});

T::test('reCAPTCHA ON: sign-up creates no account on failure; succeeds with a verified token', function () use ($rcOn, $rcOff, $rcOut, $rcFake, $rcRegister, $rcFlash, $rcdb) {
    $rcOut();
    $rcOn('v2');
    $rcFake();
    $rcRegister('rcbot1');
    $rcRegister('rcbot2', ['g-recaptcha-response' => 'forged-token']);
    T::eq(0, (int) $rcdb->fetchColumn("SELECT COUNT(*) FROM users WHERE username IN ('rcbot1', 'rcbot2')"));
    T::true(str_contains($rcFlash(), 'not a robot'));
    T::true(!isset($_SESSION['user_id']));
    $r = $rcRegister('rchuman', ['g-recaptcha-response' => 'good-token']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard') || str_contains((string) $r->header('Location'), '/verify-email'));
    T::eq(1, (int) $rcdb->fetchColumn("SELECT COUNT(*) FROM users WHERE username = 'rchuman'"));
    $rcOut();
    $rcOff();
});

T::test('reCAPTCHA: Google unreachable / error responses fail closed with a clear message', function () use ($rcOn, $rcOff, $rcOut, $rcLogin, $rcFlash) {
    $rcOut();
    $rcOn('v2');
    $u = Fx::user('0');
    foreach ([
        static fn () => new HttpResponse(0, '', 10000, 'Operation timed out', false),
        static fn () => new HttpResponse(500, 'oops', 5),
        static fn () => new HttpResponse(200, '<html>not json</html>', 5),
    ] as $resp) {
        Fx::http([RecaptchaService::VERIFY_URL => static fn () => $resp()]);
        $rcLogin($u, ['g-recaptcha-response' => 'good-token']);
        T::true(!isset($_SESSION['user_id']));
        T::true(str_contains($rcFlash(), 'could not verify the security check'));
    }
    $rcOff();
});

T::test('reCAPTCHA v3: score and action are enforced; widget is invisible', function () use ($rcOn, $rcOff, $rcOut, $rcLogin, $rcFake) {
    $rcOut();
    $rcOn('v3', '0.5');
    $b = http('GET', '/login')->body();
    T::true(str_contains($b, 'data-recaptcha-v3="6LtestSiteKey_abc"') && str_contains($b, 'data-recaptcha-action="login"') && str_contains($b, 'api.js?render=6LtestSiteKey_abc'));
    $u = Fx::user('0');
    foreach ([['score' => 0.3, 'action' => 'login'], ['score' => 0.9, 'action' => 'register'], ['action' => 'login']] as $bad) {
        $rcFake(static fn () => Fx::json(['success' => true] + $bad));
        $rcLogin($u, ['g-recaptcha-response' => 'v3-token']);
        T::true(!isset($_SESSION['user_id']), json_encode($bad));
    }
    $rcFake(static fn () => Fx::json(['success' => true, 'score' => 0.7, 'action' => 'login']));
    T::true(str_contains((string) $rcLogin($u, ['g-recaptcha-response' => 'v3-token'])->header('Location'), '/dashboard'));
    $rcOut();
    $rcOff();
});

T::test('reCAPTCHA: CSRF still enforced first; duplicate submission of a used token is refused by Google', function () use ($rcOn, $rcOff, $rcOut, $rcFake, $rcLogin, &$rcCalls) {
    $rcOut();
    $rcOn('v2');
    $used = [];
    $rcFake(static function (array $f) use (&$used) {
        $dup = isset($used[$f['response']]);
        $used[$f['response']] = true;
        return Fx::json($dup ? ['success' => false, 'error-codes' => ['timeout-or-duplicate']] : ['success' => true]);
    });
    $u = Fx::user('0');
    $threw = false;
    try {
        $r = $rcLogin($u, ['_token' => 'bad', 'g-recaptcha-response' => 'once-token']);
        T::true($r->status() === 419 || $r->status() === 403, 'CSRF rejected: ' . $r->status());
    } catch (\Throwable) {
        $threw = true;
    }
    T::true($threw || !isset($_SESSION['user_id']));
    T::eq([], $rcCalls, 'CSRF checked before reCAPTCHA');
    $rcLogin($u, ['g-recaptcha-response' => 'once-token']);
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
    $rcOut();
    $rcLogin($u, ['g-recaptcha-response' => 'once-token']);
    T::true(!isset($_SESSION['user_id']), 'replayed token refused');
    $rcOff();
});

T::test('reCAPTCHA admin settings: keys required to enable; secret encrypted at rest, masked, kept when empty', function () use ($rcOff, $rcdb, $rcFlash) {
    $rcOff();
    SettingsService::setMany(['recaptcha_site_key' => '', 'recaptcha_secret_key' => '']);
    $admin = (int) $rcdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $rcdb->insert('admins', ['username' => 'rcadmin', 'email' => 'rcadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $base = ['_token' => csrf(), 'tab' => 'users', 'registration_enabled' => '1', 'email_verification' => '0', 'registration_mobile' => '0', 'registration_mobile_optional' => '0', 'login_max_attempts' => '5', 'login_lockout_minutes' => '15', 'default_price_level' => '', 'recaptcha_version' => 'v2', 'recaptcha_min_score' => '0.5'];
    http('POST', '/' . admin_path() . '/settings', ['recaptcha_enabled' => '1'] + $base);
    T::true(str_contains($rcFlash(), 'enter the site key and secret key'));
    T::eq('0', (string) setting('recaptcha_enabled'));
    http('POST', '/' . admin_path() . '/settings', ['recaptcha_enabled' => '1', 'recaptcha_site_key' => '6LadminSite', 'recaptcha_secret_key' => '<bad secret>'] + $base);
    T::true(str_contains($rcFlash(), 'secret key looks invalid'));
    http('POST', '/' . admin_path() . '/settings', ['recaptcha_enabled' => '1', 'recaptcha_site_key' => '6LadminSite', 'recaptcha_secret_key' => '6LadminSecret_123', 'recaptcha_version' => 'v9'] + $base);
    T::eq('0', (string) setting('recaptcha_enabled'), 'unknown version refused: ' . $rcFlash());
    http('POST', '/' . admin_path() . '/settings', ['recaptcha_enabled' => '1', 'recaptcha_site_key' => '6LadminSite', 'recaptcha_secret_key' => '6LadminSecret_123'] + $base);
    T::eq(['1', '6LadminSite', '6LadminSecret_123'], [(string) setting('recaptcha_enabled'), (string) setting('recaptcha_site_key'), (string) setting('recaptcha_secret_key')]);
    $raw = (string) $rcdb->fetchColumn("SELECT value FROM settings WHERE `key` = 'recaptcha_secret_key'");
    T::true($raw !== '' && !str_contains($raw, '6LadminSecret_123'), 'encrypted at rest');
    $page = http('GET', '/' . admin_path() . '/settings', ['tab' => 'users'])->body();
    T::true(str_contains($page, 'Google reCAPTCHA') && !str_contains($page, '6LadminSecret_123') && str_contains($page, 'saved — leave empty to keep'));
    http('POST', '/' . admin_path() . '/settings', ['recaptcha_enabled' => '1', 'recaptcha_site_key' => '6LadminSite'] + $base);
    T::eq('6LadminSecret_123', (string) setting('recaptcha_secret_key'), 'kept when left empty');
    $audit = (string) $rcdb->fetchColumn("SELECT details FROM audit_logs WHERE action = 'settings.users' ORDER BY id DESC LIMIT 1");
    T::true(!str_contains($audit, '6LadminSecret_123'), 'secret never in the audit log');
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $rcOff();
});
