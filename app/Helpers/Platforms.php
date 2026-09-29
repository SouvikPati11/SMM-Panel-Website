<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Social platforms, detected from category / service names so every panel's
 * existing catalog gets icons and order-page shortcuts without manual tagging.
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
        'x' => ['X / Twitter', 'x-brand', '/\b(twitter|tweets?|retweets?)\b|(^|[\s\[(\/-])x([\s\])\/-]|$)|x\.com/'],
        'threads' => ['Threads', 'threads', '/\bthreads\b/'],
        'spotify' => ['Spotify', 'spotify', '/\bspotify\b/'],
        'twitch' => ['Twitch', 'twitch', '/\btwitch\b/'],
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
