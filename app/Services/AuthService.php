<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Totp;
use App\Core\Validator;

/**
 * Registration, login with brute-force protection, password reset, email
 * verification and TOTP two-factor authentication — for both guards.
 */
final class AuthService
{
    public const PASSWORD_RULE = 'required|min:8|max:128';
    /** Email verification links expire after 48 hours. */
    public const VERIFY_TTL = 172800;

    // ---------------------------------------------------------------- register

    public static function register(array $input, string $ip, string $refCode = ''): array
    {
        if (setting('registration_enabled', '1') !== '1') {
            throw new ValidationException('Registration is currently closed.');
        }
        $data = Validator::check($input, [
            'username' => 'required|username',
            'email' => 'required|email|max:190',
            'password' => self::PASSWORD_RULE . '|confirmed',
        ]);
        if (empty($input['terms'])) {
            throw new ValidationException('You must accept the Terms of Service.');
        }
        self::assertStrongPassword($data['password']);
        $mobile = self::validateMobile((string) ($input['mobile'] ?? ''));

        $db = Database::instance();
        $email = strtolower($data['email']);
        if ($db->fetchColumn('SELECT id FROM users WHERE username = ?', [$data['username']])) {
            throw new ValidationException('That username is already taken.');
        }
        if ($db->fetchColumn('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ValidationException('An account with that email already exists.');
        }
        // Anti-abuse: limit registrations per IP per day.
        $recent = (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE register_ip = ? AND created_at > ?', [$ip, gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ($recent >= 5) {
            throw new ValidationException('Too many accounts were created from your network today. Please try again later.');
        }

        $userId = self::createAccount([
            'username' => $data['username'],
            'email' => $email,
            'mobile' => $mobile,
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        ], $ip, $refCode);
        AuditService::log('user.register', 'user', $userId, [], 'user', $userId);
        if (setting('email_verification', '0') === '1') {
            self::sendVerification($userId);
        }
        return $db->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    /**
     * Finish a "Sign in with Google" registration: the Google profile is already
     * verified (email_verified claim), the user picks a username and, when the
     * site asks for it, a mobile number. No password is set until they choose one.
     * @param array{sub:string,email:string,name:?string} $profile
     */
    public static function registerWithGoogle(array $profile, array $input, string $ip, string $refCode = ''): array
    {
        if (setting('registration_enabled', '1') !== '1') {
            throw new ValidationException('Registration is currently closed.');
        }
        $data = Validator::check($input, ['username' => 'required|username']);
        if (empty($input['terms'])) {
            throw new ValidationException('You must accept the Terms of Service.');
        }
        $mobile = self::validateMobile((string) ($input['mobile'] ?? ''));
        $db = Database::instance();
        $email = strtolower($profile['email']);
        if ($db->fetchColumn('SELECT id FROM users WHERE username = ?', [$data['username']])) {
            throw new ValidationException('That username is already taken.');
        }
        if ($db->fetchColumn('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ValidationException('An account with this email already exists. Sign in with your password, then connect Google in Account → Security.');
        }
        if ($db->fetchColumn("SELECT id FROM user_social_accounts WHERE provider = 'google' AND provider_user_id = ?", [$profile['sub']])) {
            throw new ValidationException('This Google account is already connected to an account. Sign in instead.');
        }
        $recent = (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE register_ip = ? AND created_at > ?', [$ip, gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ($recent >= 5) {
            throw new ValidationException('Too many accounts were created from your network today. Please try again later.');
        }
        $userId = self::createAccount([
            'username' => $data['username'],
            'email' => $email,
            'mobile' => $mobile,
            'name' => $profile['name'] ? mb_substr((string) $profile['name'], 0, 100) : null,
            // Unusable random password: signing in with a password is impossible until the user sets one.
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'password_set' => 0,
            'email_verified_at' => now(), // Google verified the address (email_verified = true)
        ], $ip, $refCode, static function (Database $db, int $id) use ($profile, $email): void {
            $db->insert('user_social_accounts', ['user_id' => $id, 'provider' => 'google', 'provider_user_id' => $profile['sub'], 'email' => $email, 'created_at' => now(), 'last_login_at' => now()]);
        });
        AuditService::log('user.register', 'user', $userId, ['via' => 'google'], 'user', $userId);
        return $db->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    /** Insert the user, wallet and automatic price level atomically. */
    private static function createAccount(array $fields, string $ip, string $refCode, ?callable $inTransaction = null): int
    {
        $db = Database::instance();
        $userId = $db->transaction(static function (Database $db) use ($fields, $ip, $inTransaction): int {
            $level = setting('default_price_level', '');
            $id = $db->insert('users', $fields + [
                'status' => 'active',
                'price_level_id' => $level !== '' && ctype_digit((string) $level) ? (int) $level : null,
                'referral_code' => self::uniqueReferralCode(),
                'register_ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            WalletService::createWallet($id);
            PriceLevelService::sync($id); // automatic level (threshold 0 or the default level)
            if ($inTransaction) {
                $inTransaction($db, $id);
            }
            return $id;
        });
        if ($refCode !== '') {
            ReferralService::attach($userId, $refCode, $ip);
        }
        return $userId;
    }

    /**
     * Mobile field mode from Admin → Settings → Users: off | optional | required.
     * "Mobile number ON" means required, unless the admin also ticked "optional".
     */
    public static function mobileMode(): string
    {
        if (setting('registration_mobile', '0') !== '1') {
            return 'off';
        }
        return setting('registration_mobile_optional', '0') === '1' ? 'optional' : 'required';
    }

    /**
     * Validate a mobile number for the current mode. Returns the normalized
     * number (E.164-style: optional "+", 7–15 digits) or null when not collected.
     */
    public static function validateMobile(string $raw, ?string $mode = null): ?string
    {
        $mode ??= self::mobileMode();
        if ($mode === 'off') {
            return null; // never stored while the field is disabled
        }
        $raw = trim($raw);
        if ($raw === '') {
            if ($mode === 'required') {
                throw new ValidationException('Enter your mobile number.');
            }
            return null;
        }
        $n = preg_replace('/[\s().\-]/', '', $raw);
        if (str_starts_with($n, '00')) {
            $n = '+' . substr($n, 2);
        }
        if (!preg_match('/^\+?[1-9]\d{6,14}$/', $n)) {
            throw new ValidationException('Enter a valid mobile number, including the country code (e.g. +44 7700 900123).');
        }
        return $n;
    }

    /**
     * Whether this user must verify their email before using the panel.
     * Accounts that existed before verification was switched on are not locked
     * out (unless they later change their email address).
     */
    public static function needsVerification(array $user): bool
    {
        if (setting('email_verification', '0') !== '1' || !empty($user['email_verified_at'])) {
            return false;
        }
        $since = (string) setting('email_verification_since', '');
        if ($since === '') {
            return true;
        }
        $relevant = max((string) ($user['created_at'] ?? ''), (string) ($user['email_changed_at'] ?? ''));
        return $relevant >= $since;
    }

    public static function assertStrongPassword(string $password): void
    {
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('Password must contain at least one letter and one number.');
        }
        $common = ['password1', 'password123', '12345678a', 'qwerty123', 'abc12345', 'iloveyou1', 'admin123', 'welcome1'];
        if (in_array(strtolower($password), $common, true)) {
            throw new ValidationException('That password is too common. Please choose another.');
        }
    }

    private static function uniqueReferralCode(): string
    {
        $db = Database::instance();
        do {
            $code = strtolower(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10));
        } while ($db->fetchColumn('SELECT id FROM users WHERE referral_code = ?', [$code]));
        return $code;
    }

    // ---------------------------------------------------------------- login

    /**
     * Verify credentials with lockout. Returns the account row (caller decides
     * whether a 2FA step is required).
     * @param 'user'|'admin' $guard
     */
    public static function attempt(string $guard, string $identifier, string $password, string $ip, string $ua): array
    {
        $db = Database::instance();
        $identifier = mb_substr(strtolower(trim($identifier)), 0, 190);
        $max = max(3, (int) setting('login_max_attempts', '5'));
        $lockMin = max(1, (int) setting('login_lockout_minutes', '15'));
        $since = gmdate('Y-m-d H:i:s', time() - $lockMin * 60);

        $failsByIdent = (int) $db->fetchColumn('SELECT COUNT(*) FROM login_attempts WHERE guard = ? AND identifier = ? AND success = 0 AND created_at > ?', [$guard, $identifier, $since]);
        $failsByIp = (int) $db->fetchColumn('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        if ($failsByIdent >= $max || $failsByIp >= $max * 4) {
            throw new ValidationException("Too many failed attempts. Please wait {$lockMin} minutes and try again.");
        }

        $table = $guard === 'admin' ? 'admins' : 'users';
        $extra = $guard === 'user' ? ' AND deleted_at IS NULL' : '';
        $row = $db->fetch("SELECT * FROM {$table} WHERE (LOWER(username) = ? OR email = ?){$extra} LIMIT 1", [$identifier, $identifier]);

        // Constant-ish time: always run password_verify.
        $hash = $row['password_hash'] ?? '$2y$12$juQg7Rsx..ypsYGcOdy2bODyGOOFR5j5xczPx8O/8ezgHh40dHgvm';
        $ok = password_verify($password, $hash) && $row !== null;

        $db->insert('login_attempts', ['guard' => $guard, 'identifier' => $identifier, 'ip' => $ip, 'success' => $ok ? 1 : 0, 'user_agent' => $ua, 'created_at' => now()]);
        if (!$ok) {
            throw new ValidationException('Invalid username/email or password.');
        }
        $active = $guard === 'admin' ? $row['status'] === 'active' : $row['status'] === 'active';
        if (!$active) {
            throw new ValidationException($guard === 'admin' ? 'This administrator account is disabled.' : 'Your account has been ' . $row['status'] . '. Please contact support.');
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $db->update($table, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $row['id']]);
        }
        return $row;
    }

    public static function completeLogin(string $guard, array $row, string $ip, bool $remember = false): void
    {
        $table = $guard === 'admin' ? 'admins' : 'users';
        Database::instance()->update($table, ['last_login_at' => now(), 'last_login_ip' => $ip], ['id' => $row['id']]);
        if ($guard === 'admin') {
            Auth::loginAdmin($row);
            AuditService::log('admin.login', 'admin', (int) $row['id'], [], 'admin', (int) $row['id']);
        } else {
            Auth::loginUser($row);
            if ($remember) {
                RememberService::issue($row);
            }
            AuditService::log('user.login', 'user', (int) $row['id'], [], 'user', (int) $row['id']);
        }
    }

    // ---------------------------------------------------------------- 2FA

    public static function twoFactorSecret(array $row): ?string
    {
        if ((int) ($row['twofa_enabled'] ?? 0) !== 1 || empty($row['twofa_secret'])) {
            return null;
        }
        try {
            return Crypto::decrypt($row['twofa_secret']);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function enableTwoFactor(string $guard, int $id, string $secret, string $code): void
    {
        if (!Totp::verify($secret, $code)) {
            throw new ValidationException('The verification code is incorrect. Check your device clock and try again.');
        }
        $table = $guard === 'admin' ? 'admins' : 'users';
        Database::instance()->update($table, ['twofa_secret' => Crypto::encrypt($secret), 'twofa_enabled' => 1, 'updated_at' => now()], ['id' => $id]);
        AuditService::log($guard . '.2fa_enabled', $guard, $id);
    }

    public static function disableTwoFactor(string $guard, array $row, string $password): void
    {
        if (!password_verify($password, $row['password_hash'])) {
            throw new ValidationException('Your password is incorrect.');
        }
        $table = $guard === 'admin' ? 'admins' : 'users';
        Database::instance()->update($table, ['twofa_secret' => null, 'twofa_enabled' => 0, 'updated_at' => now()], ['id' => $row['id']]);
        AuditService::log($guard . '.2fa_disabled', $guard, (int) $row['id']);
    }

    // ---------------------------------------------------------------- passwords

    public static function changePassword(string $guard, array $row, string $current, string $new, string $confirm): void
    {
        // Accounts created with Google have no password yet: they set one without a "current" password.
        $settingFirst = $guard === 'user' && (int) ($row['password_set'] ?? 1) === 0;
        if (!$settingFirst && !password_verify($current, $row['password_hash'])) {
            throw new ValidationException('Your current password is incorrect.');
        }
        Validator::check(['password' => $new, 'password_confirmation' => $confirm], ['password' => self::PASSWORD_RULE . '|confirmed'], ['password' => 'New password']);
        self::assertStrongPassword($new);
        $table = $guard === 'admin' ? 'admins' : 'users';
        $db = Database::instance();
        $db->query("UPDATE {$table} SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), now(), $row['id']]);
        if ($guard === 'user') {
            $db->query('UPDATE users SET password_set = 1 WHERE id = ?', [$row['id']]);
            RememberService::forgetAll((int) $row['id']);
        }
        $fresh = $db->fetch("SELECT * FROM {$table} WHERE id = ?", [$row['id']]);
        // Keep this session, invalidate all others.
        $guard === 'admin' ? Auth::loginAdmin($fresh) : Auth::loginUser($fresh);
        AuditService::log($guard . '.password_changed', $guard, (int) $row['id']);
    }

    /** Always behaves the same whether or not the email exists (no account enumeration). */
    public static function requestReset(string $guard, string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Enter a valid email address.');
        }
        $db = Database::instance();
        $table = $guard === 'admin' ? 'admins' : 'users';
        $row = $db->fetch("SELECT id, email, username FROM {$table} WHERE email = ?" . ($guard === 'user' ? ' AND deleted_at IS NULL' : ''), [$email]);
        if (!$row) {
            return;
        }
        $recent = (int) $db->fetchColumn('SELECT COUNT(*) FROM password_resets WHERE guard = ? AND account_id = ? AND created_at > ?', [$guard, $row['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($recent >= 3) {
            return;
        }
        $token = Crypto::randomToken(32);
        $db->insert('password_resets', [
            'guard' => $guard,
            'account_id' => $row['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => now(),
        ]);
        $link = $guard === 'admin' ? admin_url('reset-password/' . $token) : url('/reset-password/' . $token);
        MailService::sendNow($row['email'], 'Reset your password', '<p>Hi ' . e($row['username']) . ',</p><p>We received a request to reset your password. This link expires in 60 minutes.</p>'
            . MailService::button($link, 'Reset password') . '<p>If you did not request this, you can ignore this email. Request IP: ' . e($ip) . '</p>');
    }

    public static function findResetToken(string $guard, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::instance()->fetch('SELECT * FROM password_resets WHERE guard = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?', [$guard, hash('sha256', $token), now()]);
    }

    public static function resetPassword(string $guard, string $token, string $password, string $confirm): void
    {
        $reset = self::findResetToken($guard, $token);
        if (!$reset) {
            throw new ValidationException('This reset link is invalid or has expired.');
        }
        Validator::check(['password' => $password, 'password_confirmation' => $confirm], ['password' => self::PASSWORD_RULE . '|confirmed']);
        self::assertStrongPassword($password);
        $db = Database::instance();
        $table = $guard === 'admin' ? 'admins' : 'users';
        $db->transaction(static function (Database $db) use ($reset, $table, $password, $guard): void {
            $n = $db->query('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $reset['id']])->rowCount();
            if ($n !== 1) {
                throw new ValidationException('This reset link has already been used.');
            }
            $db->query("UPDATE {$table} SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), now(), $reset['account_id']]);
            if ($guard === 'user') {
                $db->query('UPDATE users SET password_set = 1 WHERE id = ?', [$reset['account_id']]);
                $db->query('DELETE FROM remember_tokens WHERE user_id = ?', [$reset['account_id']]);
            }
            $db->query('UPDATE password_resets SET used_at = ? WHERE guard = ? AND account_id = ? AND used_at IS NULL', [now(), $guard, $reset['account_id']]);
        });
        AuditService::log($guard . '.password_reset', $guard, (int) $reset['account_id'], [], 'system');
    }

    // ---------------------------------------------------------------- email verification

    public static function sendVerification(int $userId): void
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || $user['email_verified_at']) {
            return;
        }
        $token = Crypto::randomToken(32);
        $db->query('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        $db->insert('email_verifications', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::VERIFY_TTL), 'created_at' => now()]);
        MailService::sendNow($user['email'], 'Verify your email address', '<p>Welcome to ' . e(site_name()) . ', ' . e($user['username']) . '!</p><p>Please confirm your email address to activate your account.</p>' . MailService::button(url('/verify-email/' . $token), 'Verify email'));
    }

    public static function verifyEmail(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $db = Database::instance();
        $row = $db->fetch('SELECT * FROM email_verifications WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), now()]);
        if (!$row) {
            return false;
        }
        $db->update('users', ['email_verified_at' => now()], ['id' => $row['user_id']]);
        $db->query('DELETE FROM email_verifications WHERE user_id = ?', [$row['user_id']]);
        return true;
    }
}
