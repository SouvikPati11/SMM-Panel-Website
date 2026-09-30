<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Runtime settings editable from the admin panel. Loaded once per request.
 * Secret values (e.g. SMTP password) are encrypted at rest.
 */
final class SettingsService
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        // General
        'site_name' => 'SMM Panel',
        'site_tagline' => 'Social media growth services for resellers and creators',
        'site_logo' => '',
        'site_favicon' => '',
        'timezone' => 'UTC',
        'maintenance_mode' => '0',
        'maintenance_message' => 'We are performing scheduled maintenance. Please check back soon.',
        'contact_email' => '',
        'contact_telegram' => '',
        'contact_whatsapp' => '',
        'contact_address' => '',
        'social_facebook' => '',
        'social_instagram' => '',
        'social_x' => '',
        'social_youtube' => '',
        'social_telegram' => '',
        'social_tiktok' => '',
        'footer_text' => '',
        // Currency
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'currency_position' => 'before',
        'currency_decimals' => '2',
        'currency_switch_enabled' => '1',
        // Users
        'registration_enabled' => '1',
        'email_verification' => '0',
        'email_verification_since' => '', // users created before verification was switched on are not blocked
        'registration_mobile' => '0',
        'registration_mobile_required' => '0', // legacy (before 2026_10_12); see registration_mobile_optional
        'registration_mobile_optional' => '0', // with the mobile field ON: 0 = required, 1 = may be left empty
        // Sign in with Google (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in .env take precedence)
        'google_login_enabled' => '0',
        'google_client_id' => '',
        'google_client_secret' => '',
        // Google reCAPTCHA on login + registration (RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY in .env take precedence)
        'recaptcha_enabled' => '0',
        'recaptcha_version' => 'v2', // v2 = "I'm not a robot" checkbox, v3 = invisible score
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
        'recaptcha_min_score' => '0.5',
        'login_max_attempts' => '5',
        'login_lockout_minutes' => '15',
        'default_price_level' => '',
        // Orders
        'min_order_amount' => '0',
        'mass_order_enabled' => '1',
        'mass_order_max_lines' => '100',
        'order_cancel_enabled' => '1',
        'refill_enabled' => '1',
        'order_sync_batch' => '100',
        'subscriptions_enabled' => '1',
        'subscription_max_cycles' => '100',
        // Provider price protection: off | protect | auto | disable (see ProviderSyncService::applyCostChanges)
        'price_protection_mode' => 'protect',
        'price_protection_margin' => '0',
        // Funds
        // Legacy global limits: converted into each gateway's min/max by migration 2026_10_12 and no longer enforced.
        'min_deposit' => '1',
        'max_deposit' => '10000',
        'deposit_limits_per_gateway' => '1',
        'payment_expiry_minutes' => '60',
        // Referral
        'referral_enabled' => '1',
        'referral_percent' => '5',
        'referral_min_withdrawal' => '10',
        'referral_same_ip_block' => '1',
        // API
        'api_enabled' => '1',
        'api_rate_limit' => '60',
        'api_rate_window' => '60',
        // Tickets
        'ticket_attachments' => '1',
        'ticket_max_open' => '5',
        // SEO
        'seo_title' => 'SMM Panel — Social Media Marketing Services',
        'seo_description' => 'Fast, reliable social media marketing services with instant delivery, API access and 24/7 support.',
        'seo_keywords' => '',
        'seo_og_image' => '',
        'seo_robots_extra' => '',
        'seo_google_verification' => '',
        'seo_bing_verification' => '',
        'blog_enabled' => '1',
        // Email
        'mail_driver' => '',
        'mail_host' => '',
        'mail_port' => '587',
        'mail_encryption' => 'tls',
        'mail_username' => '',
        'mail_password' => '',
        'mail_from' => '',
        'mail_from_name' => '',
        'email_notify_orders' => '0',
        'email_notify_payments' => '1',
        'email_notify_tickets' => '1',
        'admin_notify_email' => '',
    ];

    public const SECRET_KEYS = ['mail_password', 'google_client_secret', 'recaptcha_secret_key'];

    public static function boot(): void
    {
        self::load();
    }

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::instance()->fetchAll('SELECT `key`, `value`, is_secret FROM settings') as $row) {
                    $v = $row['value'];
                    if ((int) $row['is_secret'] === 1 && $v) {
                        try {
                            $v = Crypto::decrypt($v);
                        } catch (\Throwable) {
                            $v = '';
                        }
                    }
                    self::$cache[$row['key']] = $v;
                }
            } catch (\Throwable) {
                // DB not ready (installer) — defaults only
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();
        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, ?string $value): void
    {
        $secret = in_array($key, self::SECRET_KEYS, true);
        $stored = $secret && $value !== null && $value !== '' ? Crypto::encrypt($value) : $value;
        Database::instance()->query(
            'INSERT INTO settings (`key`, `value`, is_secret, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), is_secret = VALUES(is_secret), updated_at = VALUES(updated_at)',
            [$key, $stored, $secret ? 1 : 0, now()]
        );
        self::load();
        self::$cache[$key] = $value;
    }

    /** @param array<string,?string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function mailConfig(): array
    {
        $env = \App\Core\Config::get('mail');
        $driver = self::get('mail_driver') ?: $env['driver'];
        return [
            'driver' => $driver,
            'host' => self::get('mail_host') ?: $env['host'],
            'port' => (int) (self::get('mail_host') ? self::get('mail_port') : $env['port']),
            'encryption' => self::get('mail_host') ? self::get('mail_encryption') : $env['encryption'],
            'username' => self::get('mail_host') ? self::get('mail_username') : $env['username'],
            'password' => self::get('mail_host') ? self::get('mail_password') : $env['password'],
            'from' => self::get('mail_from') ?: $env['from'],
            'from_name' => self::get('mail_from_name') ?: ($env['from_name'] ?: self::get('site_name')),
        ];
    }
}
