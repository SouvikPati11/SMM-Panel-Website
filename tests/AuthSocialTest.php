<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpResponse;
use App\Services\Auth;
use App\Services\RememberService;
use App\Services\SettingsService;

/*
 * "Remember me" and "Sign in with Google": state/nonce/PKCE, ID-token claim
 * checks, account creation/linking rules, mobile + verification settings.
 * Google itself is faked at the HTTP layer (token endpoint).
 */

$sdb = Database::instance();
$signOut = static function (): void {
    Auth::logoutUser();
    unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['oauth_google'], $_SESSION['google_signup'], $_SESSION['2fa_pending_user']);
};
$cookieOf = static function (App\Core\Response $r, string $name): ?array {
    foreach ($r->cookies() as $c) {
        if ($c['name'] === $name) {
            return $c;
        }
    }
    return null;
};
$password = static fn (array $u) => ['_token' => csrf(), 'login' => $u['username'], 'password' => 'Secret123'];

T::test('Remember me: cookie issued only when ticked; hashed token; restores the session in a new browser; rotates', function () use ($sdb, $signOut, $cookieOf, $password) {
    $signOut();
    $u = Fx::user('1');
    $r = http('POST', '/login', $password($u));
    T::eq(302, $r->status());
    T::eq(null, $cookieOf($r, RememberService::cookieName()), 'no cookie without the checkbox');
    $signOut();
    $r = http('POST', '/login', $password($u) + ['remember' => '1']);
    $c = $cookieOf($r, RememberService::cookieName());
    T::true($c !== null && preg_match('/^[a-f0-9]{24}:[a-f0-9]{64}$/', $c['value']) === 1, 'selector:validator');
    T::true($c['options']['httponly'] && $c['options']['samesite'] === 'Lax');
    T::true(abs($c['options']['expires'] - (time() + 30 * 86400)) < 60, '30 days');
    [$sel, $val] = explode(':', $c['value']);
    $row = $sdb->fetch('SELECT * FROM remember_tokens WHERE selector = ?', [$sel]);
    T::eq(hash('sha256', $val), $row['token_hash'], 'only the hash is stored');
    T::true(!str_contains(json_encode($row), $val));

    // A new browser session: nothing in the session, only the cookie.
    unset($_SESSION['user_id'], $_SESSION['user_sv']);
    $r = http('GET', '/dashboard', [], ['HTTP_COOKIE' => RememberService::cookieName() . '=' . $c['value']]);
    T::eq(200, $r->status(), 'restored from the cookie');
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
    $rotated = $cookieOf($r, RememberService::cookieName());
    T::true($rotated && $rotated['value'] !== $c['value'], 'token rotated on use');
    T::eq(null, $sdb->fetchColumn('SELECT id FROM remember_tokens WHERE selector = ?', [$sel]), 'old token deleted');

    // The old (stolen) cookie no longer works.
    unset($_SESSION['user_id'], $_SESSION['user_sv']);
    $r = http('GET', '/dashboard', [], ['HTTP_COOKIE' => RememberService::cookieName() . '=' . $c['value']]);
    T::eq(302, $r->status());
    T::true(str_contains((string) $r->header('Location'), '/login'));
    T::eq('', $cookieOf($r, RememberService::cookieName())['value'] ?? null, 'invalid cookie cleared');

    // Password change (session_version) invalidates remembered browsers.
    unset($_SESSION['user_id'], $_SESSION['user_sv']);
    $sdb->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$u['id']]);
    T::eq(302, http('GET', '/dashboard', [], ['HTTP_COOKIE' => RememberService::cookieName() . '=' . $rotated['value']])->status());

    // Logout forgets this browser.
    $signOut();
    $r = http('POST', '/login', $password($u) + ['remember' => '1']);
    $c = $cookieOf($r, RememberService::cookieName());
    $r = http('POST', '/logout', ['_token' => csrf()], ['HTTP_COOKIE' => RememberService::cookieName() . '=' . $c['value']]);
    T::eq(0, (int) $sdb->fetchColumn('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$u['id']]));
    T::eq('', $cookieOf($r, RememberService::cookieName())['value'] ?? null);
    // Stateless routes (API) never create a session from the cookie.
    unset($_SESSION['user_id'], $_SESSION['user_sv']);
    $r = http('POST', '/api/v2', ['key' => str_repeat('a', 40), 'action' => 'balance'], ['HTTP_COOKIE' => RememberService::cookieName() . '=' . $c['value']]);
    T::eq(401, $r->status());
    $signOut();
});

T::test('Login page: remember-me, forgot password, register link; Google button only when enabled and configured', function () use ($signOut) {
    $signOut();
    $html = http('GET', '/login')->body();
    T::true(str_contains($html, 'name="remember"') && str_contains($html, '/forgot-password') && str_contains($html, '/register'));
    T::true(!str_contains($html, 'Continue with Google'));
    SettingsService::setMany(['google_login_enabled' => '1', 'google_client_id' => '', 'google_client_secret' => '']);
    T::true(!str_contains(http('GET', '/login')->body(), 'Continue with Google'), 'enabled but not configured → hidden');
    $r = http('GET', '/auth/google');
    T::eq(302, $r->status());
    T::true(str_contains((string) $r->header('Location'), '/login'));
    SettingsService::setMany(['google_login_enabled' => '0']);
});

// ---------------------------------------------------------------- Google

$gClient = '1234567890-test.apps.googleusercontent.com';
$gEnable = static function () use ($gClient): void {
    SettingsService::setMany(['google_login_enabled' => '1', 'google_client_id' => $gClient, 'google_client_secret' => 'GOCSPX-test-secret']);
};
$jwt = static fn (array $claims) => rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.sig';
/**
 * Start the flow and complete Google's side: returns the callback response.
 * $claims overrides the ID token; $tamper can change state/code.
 */
$google = static function (array $claims = [], string $intent = 'login', ?callable $tamper = null, ?array $tokenError = null) use ($gClient, $jwt): App\Core\Response {
    $start = $intent === 'link' ? http('POST', '/account/google/connect', ['_token' => csrf()]) : http('GET', '/auth/google', ['intent' => $intent]);
    $loc = (string) $start->header('Location');
    T::true(str_starts_with($loc, 'https://accounts.google.com/o/oauth2/v2/auth?'), 'redirects to Google: ' . $loc);
    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    $verifier = $_SESSION['oauth_google']['verifier'];
    T::eq(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $q['code_challenge'], 'PKCE S256');
    T::eq(['code', 'openid email profile', 'S256', $gClient], [$q['response_type'], $q['scope'], $q['code_challenge_method'], $q['client_id']]);
    $params = ['state' => $q['state'], 'code' => '4/test-code'];
    if ($tamper) {
        $params = $tamper($params);
    }
    Fx::http([App\Services\GoogleAuthService::TOKEN_URL => static function ($m, $u, $o) use ($claims, $q, $verifier, $gClient, $jwt, $tokenError) {
        T::eq($verifier, $o['form']['code_verifier'], 'code_verifier sent');
        T::eq('GOCSPX-test-secret', $o['form']['client_secret']);
        if ($tokenError) {
            return Fx::json($tokenError, 400);
        }
        return Fx::json(['access_token' => 'ya29.x', 'id_token' => $jwt($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => $gClient, 'exp' => time() + 3600, 'iat' => time(),
            'nonce' => $q['nonce'], 'sub' => '1000001', 'email' => 'gnew@example.com', 'email_verified' => true, 'name' => 'Gina New',
        ])]);
    }]);
    return http('GET', '/auth/google/callback', $params);
};
$flash = static fn () => !empty($_SESSION['_flash']) ? (string) (end($_SESSION['_flash'])['message'] ?? '') : '';

T::test('Google: new user completes sign-up (username, mobile per setting, terms), email verified, no password; next login is one click', function () use ($gEnable, $google, $signOut, $sdb, $flash) {
    $signOut();
    $gEnable();
    SettingsService::setMany(['registration_mobile' => '1', 'registration_mobile_optional' => '0', 'email_verification' => '1', 'email_verification_since' => now()]);
    $r = $google();
    T::eq(302, $r->status());
    T::true(str_contains((string) $r->header('Location'), '/auth/google/complete'));
    T::eq(null, $sdb->fetchColumn("SELECT id FROM users WHERE email = 'gnew@example.com'"), 'no account before the user confirms');
    $page = http('GET', '/auth/google/complete')->body();
    T::true(str_contains($page, 'gnew@example.com') && str_contains($page, 'value="gnew"') && (bool) preg_match('/name="mobile"[^>]*required/', $page));
    http('POST', '/auth/google/complete', ['_token' => csrf(), 'username' => 'gnew', 'terms' => '1']);
    T::true(str_contains($flash(), 'Enter your mobile number'), 'mobile required by site setting');
    http('POST', '/auth/google/complete', ['_token' => csrf(), 'username' => 'gnew', 'mobile' => '+1 555 010 0199']);
    T::true(str_contains($flash(), 'Terms of Service'));
    $r = http('POST', '/auth/google/complete', ['_token' => csrf(), 'username' => 'gnew', 'mobile' => '+1 555 010 0199', 'terms' => '1']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    $u = $sdb->fetch("SELECT * FROM users WHERE email = 'gnew@example.com'");
    T::eq(['gnew', '+15550100199', 0], [$u['username'], $u['mobile'], (int) $u['password_set']]);
    T::true($u['email_verified_at'] !== null, 'Google verified the email, so verification is satisfied');
    T::eq(false, App\Services\AuthService::needsVerification($u));
    T::eq('1000001', $sdb->fetchColumn("SELECT provider_user_id FROM user_social_accounts WHERE user_id = ? AND provider = 'google'", [$u['id']]));
    T::eq((int) $u['id'], (int) $_SESSION['user_id'], 'signed in');
    T::eq(200, http('GET', '/dashboard')->status());
    // Password sign-in is impossible until the user sets one (random unusable hash).
    $signOut();
    http('POST', '/login', ['_token' => csrf(), 'login' => 'gnew', 'password' => '']);
    T::true(!isset($_SESSION['user_id']));
    // Second visit: straight in, no duplicate account.
    $r = $google();
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    T::eq(1, (int) $sdb->fetchColumn("SELECT COUNT(*) FROM users WHERE email = 'gnew@example.com'"));
    // Sets a first password without a "current password".
    http('POST', '/account/password', ['_token' => csrf(), 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123']);
    T::eq(1, (int) $sdb->fetchColumn('SELECT password_set FROM users WHERE id = ?', [$u['id']]));
    $signOut();
    SettingsService::setMany(['registration_mobile' => '0', 'email_verification' => '0']);
});

T::test('Google: state is required, single-use and bound to this browser; ID token claims are checked', function () use ($gEnable, $google, $signOut, $flash) {
    $signOut();
    $gEnable();
    $r = $google([], 'login', static fn ($p) => ['state' => str_repeat('0', 48)] + $p);
    T::true(!isset($_SESSION['user_id']) && str_contains($flash(), 'expired or was not started from this browser'), 'forged state');
    // Replaying a callback (state already used) fails.
    $r = $google(['sub' => '1000001', 'email' => 'gnew@example.com']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    $signOut();
    $r = http('GET', '/auth/google/callback', ['state' => 'whatever', 'code' => 'x']);
    T::true(!isset($_SESSION['user_id']));
    foreach ([
        ['aud' => 'someone-else.apps.googleusercontent.com'],
        ['iss' => 'https://evil.example'],
        ['exp' => time() - 3600],
        ['nonce' => 'not-the-nonce'],
    ] as $bad) {
        $signOut();
        $google($bad + ['sub' => '1000001', 'email' => 'gnew@example.com']);
        T::true(!isset($_SESSION['user_id']), 'rejected: ' . json_encode($bad));
        T::true(str_contains($flash(), 'could not be verified'), $flash());
    }
    $signOut();
    $google(['sub' => '2000002', 'email' => 'unverified@example.com', 'email_verified' => false]);
    T::true(!isset($_SESSION['user_id']) && str_contains($flash(), 'not verified'));
    $google([], 'login', null, ['error' => 'invalid_grant']);
    T::true(!isset($_SESSION['user_id']) && str_contains($flash(), 'could not be completed'));
    $google([], 'login', null, ['error' => 'redirect_uri_mismatch']);
    T::true(str_contains($flash(), 'misconfigured (redirect_uri_mismatch)'));
    http('GET', '/auth/google/callback', ['error' => 'access_denied', 'state' => 'x']);
    T::true(str_contains($flash(), 'cancelled'));
});

T::test('Google: existing accounts — verified email links, unverified email is refused, 2FA and suspension respected', function () use ($gEnable, $google, $signOut, $sdb, $flash) {
    $signOut();
    $gEnable();
    $verified = Fx::user('0', ['email' => 'owner@example.com', 'email_verified_at' => now()]);
    $r = $google(['sub' => '3000003', 'email' => 'owner@example.com']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    T::eq((int) $verified['id'], (int) $_SESSION['user_id']);
    T::eq('3000003', $sdb->fetchColumn("SELECT provider_user_id FROM user_social_accounts WHERE user_id = ?", [$verified['id']]));
    T::eq(1, (int) $sdb->fetchColumn("SELECT COUNT(*) FROM users WHERE email = 'owner@example.com'"), 'no duplicate');

    $signOut();
    $unverified = Fx::user('0', ['email' => 'squat@example.com']);
    $google(['sub' => '4000004', 'email' => 'squat@example.com']);
    T::true(!isset($_SESSION['user_id']), 'pre-hijacking protection');
    T::true(str_contains($flash(), 'Sign in with your password, then connect Google'));
    T::eq(0, (int) $sdb->fetchColumn('SELECT COUNT(*) FROM user_social_accounts WHERE user_id = ?', [$unverified['id']]));

    $signOut();
    $sdb->update('users', ['twofa_enabled' => 1, 'twofa_secret' => App\Core\Crypto::encrypt('JBSWY3DPEHPK3PXP')], ['id' => $verified['id']]);
    $r = $google(['sub' => '3000003', 'email' => 'owner@example.com']);
    T::true(str_contains((string) $r->header('Location'), '/login/2fa'), '2FA still required');
    T::true(!isset($_SESSION['user_id']));
    $signOut();
    $sdb->update('users', ['twofa_enabled' => 0, 'status' => 'suspended'], ['id' => $verified['id']]);
    $google(['sub' => '3000003', 'email' => 'owner@example.com']);
    T::true(!isset($_SESSION['user_id']) && str_contains($flash(), 'suspended'));
    $sdb->update('users', ['status' => 'active'], ['id' => $verified['id']]);

    // Registration closed: an unknown Google account cannot create an account.
    $signOut();
    SettingsService::set('registration_enabled', '0');
    $google(['sub' => '5000005', 'email' => 'closed@example.com']);
    T::true(!isset($_SESSION['google_signup']) && str_contains($flash(), 'registration is currently closed'));
    SettingsService::set('registration_enabled', '1');
});

T::test('Google: connect/disconnect from Security (CSRF-protected), one account per Google identity', function () use ($gEnable, $google, $signOut, $sdb, $flash) {
    $signOut();
    $gEnable();
    $a = Fx::user('0');
    login_as_user($a);
    T::true(str_contains(http('GET', '/account/security')->body(), 'Connect Google'));
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('POST', '/account/google/connect', []), 'could not be verified');
    $r = $google(['sub' => '6000006', 'email' => 'a-google@example.com'], 'link');
    T::true(str_contains((string) $r->header('Location'), '/account/security'));
    T::eq('6000006', $sdb->fetchColumn("SELECT provider_user_id FROM user_social_accounts WHERE user_id = ?", [$a['id']]));
    // The same Google identity cannot be attached to a second account.
    $signOut();
    $b = Fx::user('0');
    login_as_user($b);
    $google(['sub' => '6000006', 'email' => 'a-google@example.com'], 'link');
    T::true(str_contains($flash(), 'already connected to another account'));
    T::eq(0, (int) $sdb->fetchColumn('SELECT COUNT(*) FROM user_social_accounts WHERE user_id = ?', [$b['id']]));
    // Disconnect (password account).
    $signOut();
    login_as_user($a);
    http('POST', '/account/google/disconnect', ['_token' => csrf()]);
    T::eq(0, (int) $sdb->fetchColumn('SELECT COUNT(*) FROM user_social_accounts WHERE user_id = ?', [$a['id']]));
    // A Google-only account cannot disconnect before setting a password.
    $g = Fx::user('0', ['password_set' => 0]);
    $sdb->insert('user_social_accounts', ['user_id' => $g['id'], 'provider' => 'google', 'provider_user_id' => '7000007', 'email' => 'g7@example.com', 'created_at' => now()]);
    $signOut();
    login_as_user($g);
    http('POST', '/account/google/disconnect', ['_token' => csrf()]);
    T::true(str_contains($flash(), 'Set a password first'));
    T::eq(1, (int) $sdb->fetchColumn('SELECT COUNT(*) FROM user_social_accounts WHERE user_id = ?', [$g['id']]));
    $signOut();
});

T::test('Google settings: admin needs credentials to enable; secret encrypted at rest, never shown, kept when left empty', function () use ($signOut, $sdb, $flash) {
    $signOut();
    SettingsService::setMany(['google_login_enabled' => '0', 'google_client_id' => '', 'google_client_secret' => '']);
    $admin = (int) $sdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $sdb->insert('admins', ['username' => 'gsetadmin', 'email' => 'gsetadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $post = static fn (array $extra) => http('POST', '/' . admin_path() . '/settings', $extra + ['_token' => csrf(), 'tab' => 'users', 'registration_enabled' => '1', 'email_verification' => '0', 'registration_mobile' => '0', 'registration_mobile_optional' => '0', 'login_max_attempts' => '5', 'login_lockout_minutes' => '15', 'default_price_level' => '']);
    $post(['google_login_enabled' => '1', 'google_client_id' => '']);
    T::true(str_contains($flash(), 'enter the Client ID and Client secret'));
    T::eq('0', setting('google_login_enabled'));
    $post(['google_login_enabled' => '1', 'google_client_id' => 'abc-123.apps.googleusercontent.com', 'google_client_secret' => 'GOCSPX-supersecret']);
    T::eq('1', setting('google_login_enabled'));
    $row = $sdb->fetch("SELECT * FROM settings WHERE `key` = 'google_client_secret'");
    T::eq(1, (int) $row['is_secret']);
    T::true($row['value'] !== 'GOCSPX-supersecret' && !str_contains((string) $row['value'], 'supersecret'), 'encrypted');
    $page = http('GET', '/' . admin_path() . '/settings', ['tab' => 'users'])->body();
    T::true(!str_contains($page, 'GOCSPX-supersecret') && str_contains($page, '/auth/google/callback'), 'secret never rendered; redirect URI shown');
    T::true(!str_contains((string) $sdb->fetchColumn("SELECT details FROM audit_logs WHERE action = 'settings.users' ORDER BY id DESC LIMIT 1"), 'supersecret'), 'not in the audit log');
    // Saving again without a secret keeps it.
    $post(['google_login_enabled' => '1', 'google_client_id' => 'abc-123.apps.googleusercontent.com', 'google_client_secret' => '']);
    T::eq('GOCSPX-supersecret', App\Services\GoogleAuthService::config()['client_secret']);
    $post(['google_login_enabled' => '0', 'google_client_id' => '']);
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('reCAPTCHA ON does not affect Sign in with Google (OAuth callback needs no token)', function () use ($gEnable, $google, $signOut, $sdb) {
    $signOut();
    $gEnable();
    SettingsService::setMany(['recaptcha_enabled' => '1', 'recaptcha_version' => 'v2', 'recaptcha_site_key' => '6LtestSiteKey', 'recaptcha_secret_key' => 'rc-test-secret']);
    $u = Fx::user('0', ['email' => 'rcgoogle@example.com', 'email_verified_at' => now()]);
    $r = $google(['sub' => '7700077', 'email' => 'rcgoogle@example.com']);
    T::true(str_contains((string) $r->header('Location'), '/dashboard'));
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
    SettingsService::setMany(['recaptcha_enabled' => '0']);
    $signOut();
});
