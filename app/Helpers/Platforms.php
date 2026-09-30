<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Social platforms. Each category has a platform (categories.platform, set in
 * Admin → Categories); when it is empty the platform is detected from the
 * category name, so existing catalogs work without manual tagging.
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

    /**
     * New Order page shortcut cards, in display order ("All" and "Other" are
     * added around them). Every platform not listed here is grouped under
     * "Other".
     */
    public const SHORTCUTS = ['instagram', 'tiktok', 'youtube', 'facebook', 'x', 'telegram', 'spotify', 'threads', 'vk', 'twitch'];

    /** Shortcut group of a platform key: itself when it has a card, otherwise "other". */
    public static function group(string $key): string
    {
        return in_array($key, self::SHORTCUTS, true) ? $key : self::OTHER;
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
        return self::LIST[$key][0] ?? 'Other';
    }

    public static function icon(string $key, string $class = ''): string
    {
        return Icons::svg(self::LIST[$key][1] ?? 'layers', trim('platform-icon platform-' . $key . ' ' . $class));
    }
}
