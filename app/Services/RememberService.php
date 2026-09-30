<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Cookie;
use App\Core\Database;

/**
 * "Remember me" for customer logins.
 *
 * The cookie holds selector:validator. Only SHA-256(validator) is stored, the
 * selector finds the row (no timing oracle on the secret), every use rotates
 * the token, and tokens die with the account's session_version (password
 * change, "sign out all sessions", admin reset) or after 30 days.
 * Sessions stay short-lived; the cookie only re-creates one.
 */
final class RememberService
{
    public const DAYS = 30;

    public static function cookieName(): string
    {
        return (string) Config::get('session.name', 'smmpanel_sid') . '_rm';
    }

    public static function issue(array $user): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::DAYS * 86400;
        Database::instance()->insert('remember_tokens', [
            'user_id' => (int) $user['id'],
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'session_version' => (int) $user['session_version'],
            'user_agent' => mb_substr((string) App::request()->userAgent(), 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'created_at' => now(),
        ]);
        Cookie::queue(self::cookieName(), $selector . ':' . $validator, $expires, App::request());
    }

    /** Log the visitor in from a valid cookie. Returns the user row, or null. */
    public static function restore(): ?array
    {
        $request = App::request();
        $raw = $request->cookie(self::cookieName());
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
            Cookie::forget(self::cookieName(), $request);
            return null;
        }
        $db = Database::instance();
        $row = $db->fetch('SELECT * FROM remember_tokens WHERE selector = ?', [$m[1]]);
        if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $m[2])) || strtotime($row['expires_at'] . ' UTC') < time()) {
            if ($row) {
                // A known selector with the wrong validator: the token may have been stolen and
                // already used. Drop it; the owner simply signs in again.
                $db->delete('remember_tokens', ['id' => $row['id']]);
            }
            Cookie::forget(self::cookieName(), $request);
            return null;
        }
        $user = $db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$row['user_id']]);
        $db->delete('remember_tokens', ['id' => $row['id']]);
        if (!$user || $user['status'] !== 'active' || (int) $user['session_version'] !== (int) $row['session_version']) {
            Cookie::forget(self::cookieName(), $request);
            return null;
        }
        Auth::loginUser($user);
        self::issue($user); // rotate
        $db->update('users', ['last_login_at' => now(), 'last_login_ip' => $request->ip()], ['id' => $user['id']]);
        AuditService::log('user.login_remembered', 'user', (int) $user['id'], [], 'user', (int) $user['id']);
        return $user;
    }

    /** Forget this browser (logout). */
    public static function forget(): void
    {
        $request = App::request();
        $raw = (string) $request->cookie(self::cookieName());
        if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
            Database::instance()->delete('remember_tokens', ['selector' => $m[1]]);
        }
        if ($raw !== '') {
            Cookie::forget(self::cookieName(), $request);
        }
    }

    public static function forgetAll(int $userId): void
    {
        Database::instance()->delete('remember_tokens', ['user_id' => $userId]);
    }
}
