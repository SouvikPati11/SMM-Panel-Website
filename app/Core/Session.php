<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened native PHP sessions: HttpOnly + SameSite=Lax cookies, Secure on HTTPS,
 * strict mode, idle and absolute timeouts, ID regeneration on privilege change.
 */
final class Session
{
    private static bool $started = false;

    public static function start(Request $request): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }

        $dir = STORAGE_PATH . '/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) { // deprecated (and defaults are strong) from PHP 8.4
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) Config::get('session.lifetime', 7200));

        $basePath = rtrim((string) parse_url((string) Config::get('url', ''), PHP_URL_PATH), '/');
        session_name((string) Config::get('session.name', 'smmpanel_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($basePath ?: '') . '/',
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $now = time();
        $idle = (int) Config::get('session.lifetime', 7200);
        $absolute = (int) Config::get('session.absolute', 604800);
        $created = (int) ($_SESSION['_created'] ?? $now);
        $last = (int) ($_SESSION['_last'] ?? $now);
        if (($now - $last) > $idle || ($now - $created) > $absolute) {
            self::destroy();
            session_start();
            $created = $now;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last'] = $now;

        // Rotate session id periodically to limit fixation windows.
        if (!isset($_SESSION['_rotated']) || $now - (int) $_SESSION['_rotated'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_rotated'] = $now;
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_rotated'] = time();
        unset($_SESSION['_csrf']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function flashInput(array $input): void
    {
        foreach (['password', 'password_confirmation', 'current_password', 'new_password', 'api_key', 'secret'] as $k) {
            unset($input[$k]);
        }
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, string $default = ''): string
    {
        $v = $_SESSION['_old_current'][$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Called once per request: moves flashed input to "current" and clears it. */
    public static function ageFlashInput(): void
    {
        $_SESSION['_old_current'] = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }
}
