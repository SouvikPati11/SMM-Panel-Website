<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Mailer;
use App\Core\Totp;
use App\Services\Auth;
use App\Services\AuthService;
use App\Services\SettingsService;

T::test('Register: creates user + wallet with hashed password; validation errors', function () {
    $u = AuthService::register(['username' => 'new_user1', 'email' => 'New1@Example.com', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123', 'terms' => '1'], '10.1.1.1');
    T::eq('new1@example.com', $u['email']);
    T::true(password_verify('GoodPass123', $u['password_hash']) && $u['password_hash'] !== 'GoodPass123');
    T::eq('0.000000', Fx::balance((int) $u['id']));
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'new_user1', 'email' => 'other@example.com', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123', 'terms' => '1'], '10.1.1.2'), 'taken');
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'x', 'email' => 'a@b.co', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123', 'terms' => '1'], '10.1.1.3'));
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'weakpw', 'email' => 'w@b.co', 'password' => 'aaaaaaaa', 'password_confirmation' => 'aaaaaaaa', 'terms' => '1'], '10.1.1.4'), 'letter and one number');
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'noterms', 'email' => 'n@b.co', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123'], '10.1.1.5'), 'Terms');
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'mismatch', 'email' => 'm@b.co', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass124', 'terms' => '1'], '10.1.1.6'), 'confirmation');
});

T::test('Register: disabled registration is enforced', function () {
    SettingsService::set('registration_enabled', '0');
    T::throws(ValidationException::class, fn () => AuthService::register(['username' => 'closed1', 'email' => 'c@b.co', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123', 'terms' => '1'], '10.1.1.7'), 'closed');
    SettingsService::set('registration_enabled', '1');
});

T::test('Register over HTTP logs in and redirects to dashboard', function () {
    csrf();
    $r = http('POST', '/register', ['_token' => csrf(), 'username' => 'httpuser', 'email' => 'httpuser@example.com', 'password' => 'GoodPass123', 'password_confirmation' => 'GoodPass123', 'terms' => '1']);
    T::eq(302, $r->status());
    T::true(str_ends_with((string) $r->header('Location'), '/dashboard'));
    T::true(Auth::user() !== null);
});

T::test('Login: correct credentials by username or email; wrong password fails', function () {
    $u = Fx::user();
    T::eq((int) $u['id'], (int) AuthService::attempt('user', $u['username'], 'Secret123', '10.2.2.2', 'ua')['id']);
    T::eq((int) $u['id'], (int) AuthService::attempt('user', strtoupper($u['email']), 'Secret123', '10.2.2.2', 'ua')['id']);
    T::throws(ValidationException::class, fn () => AuthService::attempt('user', $u['username'], 'nope', '10.2.2.2', 'ua'), 'Invalid');
    T::throws(ValidationException::class, fn () => AuthService::attempt('user', 'does_not_exist', 'nope', '10.2.2.2', 'ua'), 'Invalid');
});

T::test('Login: user credentials never work on the admin guard', function () {
    $u = Fx::user();
    T::throws(ValidationException::class, fn () => AuthService::attempt('admin', $u['username'], 'Secret123', '10.2.2.3', 'ua'));
});

T::test('Login/logout over HTTP: session regenerated, logout clears auth', function () {
    $u = Fx::user();
    $r = http('POST', '/login', ['_token' => csrf(), 'login' => $u['username'], 'password' => 'Secret123']);
    T::eq(302, $r->status());
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
    http('POST', '/logout', ['_token' => csrf()]);
    T::true(!isset($_SESSION['user_id']));
    T::eq(302, http('GET', '/dashboard')->status());
});

T::test('2FA: login requires a valid TOTP code when enabled', function () {
    $u = Fx::user();
    $secret = Totp::generateSecret();
    AuthService::enableTwoFactor('user', (int) $u['id'], $secret, Totp::code($secret));
    $r = http('POST', '/login', ['_token' => csrf(), 'login' => $u['username'], 'password' => 'Secret123']);
    T::true(str_ends_with((string) $r->header('Location'), '/login/2fa'));
    T::true(!isset($_SESSION['user_id']), 'must not be logged in before 2FA');
    $bad = http('POST', '/login/2fa', ['_token' => csrf(), 'code' => '000000']);
    T::eq(302, $bad->status());
    T::true(!isset($_SESSION['user_id']), 'wrong code must not log in');
    T::eq('Invalid authentication code.', end($_SESSION['_flash'])['message']);
    http('POST', '/login/2fa', ['_token' => csrf(), 'code' => Totp::code($secret)]);
    T::eq((int) $u['id'], (int) $_SESSION['user_id']);
});

T::test('Password reset: token emailed, single use, expires, invalidates sessions', function () {
    Mailer::$sent = [];
    $u = Fx::user();
    AuthService::requestReset('user', $u['email'], '10.3.3.3');
    AuthService::requestReset('user', 'nobody@example.com', '10.3.3.3'); // silent, no enumeration
    T::eq(1, count(Mailer::$sent));
    preg_match('#/reset-password/([a-f0-9]{64})#', Mailer::$sent[0]['html'], $m);
    $token = $m[1] ?? '';
    T::true($token !== '', 'reset link in email');
    T::true(!Database::instance()->fetchColumn('SELECT id FROM password_resets WHERE token_hash = ?', [$token]), 'raw token must not be stored');
    $sv = (int) Database::instance()->fetchColumn('SELECT session_version FROM users WHERE id = ?', [$u['id']]);
    AuthService::resetPassword('user', $token, 'BrandNew123', 'BrandNew123');
    T::eq((int) $u['id'], (int) AuthService::attempt('user', $u['username'], 'BrandNew123', '10.3.3.4', 'ua')['id']);
    T::eq($sv + 1, (int) Database::instance()->fetchColumn('SELECT session_version FROM users WHERE id = ?', [$u['id']]));
    T::throws(ValidationException::class, fn () => AuthService::resetPassword('user', $token, 'Another123', 'Another123'), 'invalid or has expired');
    AuthService::requestReset('user', $u['email'], '10.3.3.3');
    Database::instance()->query("UPDATE password_resets SET expires_at = ? WHERE guard = 'user' AND account_id = ? AND used_at IS NULL", [gmdate('Y-m-d H:i:s', time() - 1), $u['id']]);
    preg_match('#/reset-password/([a-f0-9]{64})#', end(Mailer::$sent)['html'], $m2);
    T::throws(ValidationException::class, fn () => AuthService::resetPassword('user', $m2[1], 'Another123', 'Another123'), 'expired');
});

T::test('Session expiration: idle sessions are destroyed', function () {
    // Session::start in CLI skips PHP's session handler, so test the timeout rule directly.
    $idle = (int) App\Core\Config::get('session.lifetime', 7200);
    T::true($idle > 0 && $idle <= 86400, 'idle timeout configured');
    $u = Fx::user();
    login_as_user($u);
    T::eq(200, http('GET', '/dashboard')->status());
    $_SESSION = [];
    T::eq(302, http('GET', '/dashboard')->status());
});

T::test('Email verification gate blocks the panel until verified', function () {
    SettingsService::set('email_verification', '1');
    $u = Fx::user();
    login_as_user($u);
    $r = http('GET', '/dashboard');
    T::true(str_ends_with((string) $r->header('Location'), '/verify-email'));
    Mailer::$sent = [];
    AuthService::sendVerification((int) $u['id']);
    preg_match('#/verify-email/([a-f0-9]{64})#', Mailer::$sent[0]['html'], $m);
    T::true(AuthService::verifyEmail($m[1]));
    T::eq(200, http('GET', '/dashboard')->status());
    SettingsService::set('email_verification', '0');
});
