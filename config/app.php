<?php

use App\Core\Env;

/*
 * Central configuration. Values come from .env; anything that an administrator
 * should be able to change at runtime lives in the `settings` table instead
 * (see App\Services\SettingsService).
 */
return [
    'name'     => Env::get('APP_NAME', 'SMM Panel'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => (bool) Env::get('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'key'      => (string) Env::get('APP_KEY', ''),
    'admin_path' => trim((string) Env::get('ADMIN_PATH', 'admin'), '/') ?: 'admin',
    'force_https' => (bool) Env::get('FORCE_HTTPS', false),
    // When behind a proxy/CDN (e.g. Cloudflare) list trusted proxy IPs here so
    // the real client IP is read from X-Forwarded-For. Empty = trust none.
    'trusted_proxies' => array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', '')))),

    'db' => [
        'host'     => Env::get('DB_HOST', 'localhost'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        'name'     => Env::get('DB_NAME', ''),
        'user'     => Env::get('DB_USER', ''),
        'pass'     => (string) Env::get('DB_PASS', ''),
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'     => Env::get('SESSION_NAME', 'smmpanel_sid'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 7200),  // idle timeout, seconds
        'absolute' => (int) Env::get('SESSION_ABSOLUTE', 86400 * 7),
    ],

    'mail' => [
        'driver'     => Env::get('MAIL_DRIVER', 'smtp'),         // smtp | mail | log
        'host'       => Env::get('MAIL_HOST', ''),
        'port'       => (int) Env::get('MAIL_PORT', 587),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),     // tls | ssl | none
        'username'   => Env::get('MAIL_USERNAME', ''),
        'password'   => (string) Env::get('MAIL_PASSWORD', ''),
        'from'       => Env::get('MAIL_FROM', ''),
        'from_name'  => Env::get('MAIL_FROM_NAME', ''),
    ],

    'uploads' => [
        'max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
    ],

    'cron_key' => (string) Env::get('CRON_KEY', ''),
];
