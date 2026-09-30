<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Social platforms — the one central list. LIST holds the code-side
 * definitions (default name, icon, name-detection pattern); the `platforms`
 * table holds what admins manage in Admin → Platforms (name, ON/OFF, New
 * Order shortcut, order). Each category has a platform (categories.platform,
 * set in Admin → Categories); when it is empty the platform is detected from
 * the category name. Turning a platform OFF hides its categories and services
 * from customers without changing any category, service or order.
 */
final class Platforms
{
    /** key => [label, icon, regex over the lower-cased name] — first match wins, order matters. */
    public const LIST = [
        'instagram' => ['Instagram', 'instagram', '/\b(instagram|insta|ig)\b/'],
        'facebook' => ['Facebook', 'facebook', '/\b(facebook|fb)\b/'],
        'tiktok' => ['TikTok', 'tiktok', '/\b(tiktok|tik tok)\b/'],
        'youtube' => ['YouTube', 'youtube', '/\b(youtube|yt|shorts)\b/'],
        'telegram' => ['Telegram', 'telegram', '/\b(telegram|tg)\b/'],
        'x' => ['X', 'x-brand', '/\b(twitter|tweets?|retweets?)\b|(^|[\s\[(\/-])x([\s\])\/-]|$)|x\.com/'],
        'threads' => ['Threads', 'threads', '/\bthreads\b/'],
        'spotify' => ['Spotify', 'spotify', '/\bspotify\b/'],
        'twitch' => ['Twitch', 'twitch', '/\btwitch\b/'],
        'vk' => ['VK', 'vk', '/\b(vk|vkontakte|vk\.com)\b/'],
        'kick' => ['Kick', 'kick', '/\bkick\b/'],
        'discord' => ['Discord', 'discord', '/\bdiscord\b/'],
        'linkedin' => ['LinkedIn', 'linkedin', '/\blinked ?in\b/'],
        'whatsapp' => ['WhatsApp', 'whatsapp', '/\bwhats ?app\b/'],
        'snapchat' => ['Snapchat', 'snapchat', '/\bsnap ?chat\b/'],
        'pinterest' => ['Pinterest', 'pinterest', '/\bpinterest\b/'],
        'soundcloud' => ['SoundCloud', 'soundcloud', '/\bsound ?cloud\b/'],
        'reddit' => ['Reddit', 'reddit', '/\breddit\b/'],
        'website' => ['Website traffic', 'globe', '/\b(website|web traffic|traffic|seo)\b/'],
    ];

    public const OTHER = 'other';

    /** Platforms with their own New Order card on a fresh install (admins change it in Admin → Platforms). */
    public const DEFAULT_SHORTCUTS = ['instagram', 'tiktok', 'youtube', 'facebook', 'x', 'telegram', 'spotify', 'threads', 'vk', 'twitch'];

    /** @var array<string, array{key:string,name:string,status:string,shortcut:bool,sort_order:int}>|null */
    private static ?array $states = null;
    /** @var list<int>|null */
    private static ?array $hiddenCategories = null;

    /**
     * Admin-managed state of every known platform (table `platforms`), in
     * display order. Definitions (icon, detection pattern) stay in LIST;
     * name, ON/OFF and shortcut come from the database. Before the migration
     * has run, every platform is ON with the default shortcuts.
     */
    public static function all(): array
    {
        if (self::$states === null) {
            $rows = [];
            try {
                foreach (\App\Core\Database::instance()->fetchAll('SELECT `key`, name, status, shortcut, sort_order FROM platforms ORDER BY sort_order, name') as $r) {
                    if (isset(self::LIST[$r['key']])) {
                        $rows[$r['key']] = ['key' => $r['key'], 'name' => (string) $r['name'], 'status' => $r['status'] === 'disabled' ? 'disabled' : 'active', 'shortcut' => (int) $r['shortcut'] === 1, 'sort_order' => (int) $r['sort_order']];
                    }
                }
            } catch (\Throwable) {
                $rows = []; // table not created yet (upgrade pending)
            }
            $i = count($rows);
            foreach (self::LIST as $key => [$label]) { // platforms added to the code later
                $rows[$key] ??= ['key' => $key, 'name' => $label, 'status' => 'active', 'shortcut' => in_array($key, self::DEFAULT_SHORTCUTS, true), 'sort_order' => 100 + $i++];
            }
            self::$states = $rows;
        }
        return self::$states;
    }

    public static function reset(): void
    {
        self::$states = null;
        self::$hiddenCategories = null;
    }

    public static function isEnabled(string $key): bool
    {
        return $key === self::OTHER || (self::all()[$key]['status'] ?? 'active') === 'active';
    }

    /** Enabled platforms in display order. */
    public static function enabled(): array
    {
        return array_filter(self::all(), static fn ($p) => $p['status'] === 'active');
    }

    /** Keys of the New Order cards (enabled + "shortcut"), "Other" is added by the page. */
    public static function shortcuts(): array
    {
        return array_keys(array_filter(self::all(), static fn ($p) => $p['status'] === 'active' && $p['shortcut']));
    }

    /** Shortcut group of a platform key: itself when it has a card, otherwise "other". */
    public static function group(string $key): string
    {
        return in_array($key, self::shortcuts(), true) ? $key : self::OTHER;
    }

    /** Valid stored value for categories.platform: '' (auto-detect from the name) or a known key. */
    public static function normalize(string $key): string
    {
        return $key === self::OTHER || isset(self::LIST[$key]) ? $key : '';
    }

    /** Platform of a category: the admin's choice, else detected from its name. */
    public static function forCategory(array $category): string
    {
        $p = (string) ($category['platform'] ?? '');
        return $p !== '' && ($p === self::OTHER || isset(self::LIST[$p])) ? $p : self::detect((string) $category['name']);
    }

    /** Is the category shown to users (its platform is ON)? Category status is checked separately. */
    public static function categoryVisible(array $category): bool
    {
        return self::isEnabled(self::forCategory($category));
    }

    /** IDs of categories whose platform is OFF (hidden from users, never modified). */
    public static function hiddenCategoryIds(): array
    {
        if (self::$hiddenCategories === null) {
            self::$hiddenCategories = [];
            if (count(self::enabled()) < count(self::all())) {
                foreach (\App\Core\Database::instance()->fetchAll('SELECT id, name, platform FROM categories') as $c) {
                    if (!self::categoryVisible($c)) {
                        self::$hiddenCategories[] = (int) $c['id'];
                    }
                }
            }
        }
        return self::$hiddenCategories;
    }

    /** SQL condition (integers only) excluding categories of disabled platforms, e.g. " AND c.id NOT IN (3,4)". */
    public static function sqlVisible(string $column): string
    {
        $ids = self::hiddenCategoryIds();
        return $ids ? ' AND ' . $column . ' NOT IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
    }

    public static function detect(string $name): string
    {
        $n = mb_strtolower($name);
        foreach (self::LIST as $key => [, , $re]) {
            if (preg_match($re, $n)) {
                return $key;
            }
        }
        return self::OTHER;
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['name'] ?? (self::LIST[$key][0] ?? 'Other');
    }

    public static function icon(string $key, string $class = ''): string
    {
        return Icons::svg(self::LIST[$key][1] ?? 'layers', trim('platform-icon platform-' . $key . ' ' . $class));
    }
}
