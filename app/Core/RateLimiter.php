<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter stored in MySQL (no Redis needed on shared hosting).
 */
final class RateLimiter
{
    /** Register a hit and return true if the caller is still within the limit. */
    public static function hit(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $db = Database::instance();
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $bucket = substr($bucket, 0, 190);
        $db->query(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$bucket, $window]
        );
        $hits = (int) $db->fetchColumn('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window]);
        return $hits <= $maxHits;
    }

    public static function tooMany(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $hits = (int) Database::instance()->fetchColumn('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [substr($bucket, 0, 190), $window]);
        return $hits >= $maxHits;
    }

    public static function clear(string $bucket): void
    {
        Database::instance()->query('DELETE FROM rate_limits WHERE bucket = ?', [substr($bucket, 0, 190)]);
    }

    public static function prune(): int
    {
        return Database::instance()->query('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400 * 2])->rowCount();
    }
}
