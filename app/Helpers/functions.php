<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Money;
use App\Core\Session;
use App\Services\Auth;
use App\Services\SettingsService;

/** HTML-escape any value for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolute URL for an application path. */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = (string) Config::get('url', '');
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function upload_url(?string $path): string
{
    return $path ? url('uploads/' . ltrim($path, '/')) : '';
}

function route(string $name, array $params = []): string
{
    return url(App\Core\App::router()->url($name, $params));
}

function admin_path(): string
{
    return (string) Config::get('admin_path', 'admin');
}

function admin_url(string $path = ''): string
{
    return url('/' . admin_path() . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function old(string $key, mixed $default = ''): string
{
    return Session::old($key, (string) ($default ?? ''));
}

function db(): Database
{
    return Database::instance();
}

function setting(string $key, mixed $default = null): mixed
{
    return SettingsService::get($key, $default);
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function site_name(): string
{
    return (string) setting('site_name', Config::get('name', 'SMM Panel'));
}

/** Format an amount in the site currency, e.g. "$12.50". */
function money(string|int|null $amount, ?int $decimals = null): string
{
    $decimals ??= (int) setting('currency_decimals', 2);
    $symbol = (string) setting('currency_symbol', '$');
    $formatted = Money::format((string) ($amount ?? '0'), $decimals);
    $neg = str_starts_with($formatted, '-');
    $formatted = ltrim($formatted, '-');
    $out = setting('currency_position', 'before') === 'after' ? $formatted . ' ' . $symbol : $symbol . $formatted;
    return ($neg ? '-' : '') . $out;
}

/** Show a per-1000 rate in the site currency without trimming precision. */
function rate(string|int|null $amount): string
{
    $symbol = (string) setting('currency_symbol', '$');
    $f = Money::formatRate((string) ($amount ?? '0'));
    return setting('currency_position', 'before') === 'after' ? $f . ' ' . $symbol : $symbol . $f;
}

function display_tz(): DateTimeZone
{
    static $cache = [];
    $user = Auth::user();
    $tz = ($user['timezone'] ?? null) ?: (string) setting('timezone', 'UTC');
    if (!isset($cache[$tz])) {
        try {
            $cache[$tz] = new DateTimeZone($tz);
        } catch (Throwable) {
            $cache[$tz] = new DateTimeZone('UTC');
        }
    }
    return $cache[$tz];
}

/** Convert a UTC DB timestamp to the display timezone. */
function fmt_date(?string $utc, string $format = 'M j, Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(display_tz())->format($format);
    } catch (Throwable) {
        return e($utc);
    }
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - strtotime($utc . ' UTC');
    return match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => intdiv($diff, 60) . 'm ago',
        $diff < 86400 => intdiv($diff, 3600) . 'h ago',
        $diff < 86400 * 30 => intdiv($diff, 86400) . 'd ago',
        default => fmt_date($utc, 'M j, Y'),
    };
}

function str_limit(?string $s, int $len = 60): string
{
    $s = (string) $s;
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    if (function_exists('transliterator_transliterate')) {
        $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string) $text, '-') ?: 'item-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/** Map a status to a badge colour class. */
function status_badge(string $status): string
{
    $map = [
        'pending' => 'warning', 'processing' => 'info', 'in_progress' => 'info', 'completed' => 'success',
        'partial' => 'orange', 'cancelled' => 'muted', 'refunded' => 'purple', 'failed' => 'danger',
        'active' => 'success', 'suspended' => 'warning', 'banned' => 'danger', 'disabled' => 'muted', 'hidden' => 'muted',
        'open' => 'info', 'answered' => 'success', 'closed' => 'muted',
        'approved' => 'success', 'rejected' => 'danger', 'expired' => 'muted',
        'published' => 'success', 'draft' => 'muted', 'ok' => 'success', 'error' => 'danger', 'unknown' => 'muted',
        'success' => 'success', 'running' => 'info', 'skipped' => 'muted', 'revoked' => 'muted',
        'low' => 'muted', 'normal' => 'info', 'high' => 'warning', 'urgent' => 'danger',
    ];
    $cls = $map[$status] ?? 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function auth_user(): ?array
{
    return Auth::user();
}

function auth_admin(): ?array
{
    return Auth::admin();
}

function can(string $permission): bool
{
    return Auth::adminCan($permission);
}

function is_active_path(string $prefix): bool
{
    $path = App\Core\App::request()?->path() ?? '/';
    return $path === $prefix || ($prefix !== '/' && str_starts_with($path, rtrim($prefix, '/') . '/'));
}

function icon(string $name, string $class = ''): string
{
    return App\Helpers\Icons::svg($name, $class);
}

function json_attr(mixed $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
}
