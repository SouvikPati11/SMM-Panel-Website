<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * URL-triggered cron (for hosts where a cron job can only fetch a URL, or
 * where the PHP CLI path is hard to find).
 *
 * The secret comes from CRON_KEY in .env when set (32+ characters); otherwise
 * from the "cron_key" setting, which an admin generates or rotates in
 * Admin → Cron (stored encrypted, see SettingsService::SECRET_KEYS). Without
 * a key the URL trigger is disabled and answers 404.
 *
 * Hostinger's cron runs a shell command, so the URL must be fetched with
 * wget/curl; both commands shown follow redirects (http→https, www) and never
 * print the page into cron e-mails beyond the one-line JSON summary.
 */
final class CronUrl
{
    public const MIN_LENGTH = 32;
    public const PATH = '/tasks/run/';

    public static function key(): string
    {
        $env = trim((string) Config::get('cron_key', ''));
        if (strlen($env) >= self::MIN_LENGTH) {
            return $env;
        }
        $stored = trim((string) setting('cron_key', ''));
        return strlen($stored) >= self::MIN_LENGTH ? $stored : '';
    }

    /** 'env' | 'settings' | null (disabled). */
    public static function source(): ?string
    {
        if (strlen(trim((string) Config::get('cron_key', ''))) >= self::MIN_LENGTH) {
            return 'env';
        }
        return self::key() !== '' ? 'settings' : null;
    }

    public static function enabled(): bool
    {
        return self::key() !== '';
    }

    public static function matches(string $token): bool
    {
        $key = self::key();
        return $key !== '' && hash_equals($key, $token);
    }

    /** Create or rotate the stored key (the old URL stops working immediately). */
    public static function generate(): string
    {
        $key = bin2hex(random_bytes(24)); // 48 hex characters
        SettingsService::set('cron_key', $key);
        return $key;
    }

    public static function url(): ?string
    {
        $key = self::key();
        return $key !== '' ? url(self::PATH . $key) : null;
    }

    /** Ready-to-paste cron commands for the URL (Hostinger "Custom" cron job, cPanel, crontab). */
    public static function commands(): array
    {
        $url = self::url();
        if ($url === null) {
            return [];
        }
        $q = escapeshellarg($url);
        return [
            'wget' => 'wget -q -O - --timeout=600 ' . $q,
            'curl' => 'curl -fsS -L --max-time 600 ' . $q,
        ];
    }
}
