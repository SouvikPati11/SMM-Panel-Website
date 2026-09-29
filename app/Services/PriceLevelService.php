<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;

/**
 * Price levels by deposit threshold.
 *
 * Qualifying amount = LIFETIME CREDITED DEPOSITS (wallets.total_deposits), not
 * the current balance: it only grows when a deposit is actually credited to
 * the ledger (gateway payment completed, manual payment approved, or an admin
 * balance change recorded as a deposit). Pending, held-for-review, failed,
 * rejected and expired payments never reach the ledger, so they never count.
 * The ledger reference is unique per payment, so nothing is counted twice.
 *
 * A user's level is automatic unless an admin assigned one (price_level_manual = 1).
 * Automatic level = the qualifying level with the highest threshold (ties: higher
 * discount). Levels without a threshold (NULL) are manual-only.
 */
final class PriceLevelService
{
    /** @return list<array> automatic levels, lowest threshold first */
    public static function autoLevels(): array
    {
        return Database::instance()->fetchAll('SELECT * FROM price_levels WHERE min_deposit IS NOT NULL ORDER BY min_deposit ASC, discount_percent ASC, id ASC');
    }

    public static function qualifying(int $userId): string
    {
        return Money::of((string) (Database::instance()->fetchColumn('SELECT total_deposits FROM wallets WHERE user_id = ?', [$userId]) ?? '0'));
    }

    /** Level an automatic user qualifies for with $amount, or null. */
    public static function levelFor(string $amount, ?array $levels = null): ?array
    {
        $best = null;
        foreach ($levels ?? self::autoLevels() as $l) {
            if (Money::cmp($amount, (string) $l['min_deposit']) >= 0) {
                $best = $l; // ordered ascending, so the last qualifying one is the highest
            }
        }
        return $best;
    }

    /**
     * Re-evaluate one automatic user's level (called inside the deposit transaction).
     * Returns the level id now stored.
     */
    public static function sync(int $userId): ?int
    {
        $db = Database::instance();
        $u = $db->fetch('SELECT id, price_level_id, price_level_manual FROM users WHERE id = ?', [$userId]);
        if (!$u || (int) $u['price_level_manual'] === 1) {
            return $u ? ($u['price_level_id'] !== null ? (int) $u['price_level_id'] : null) : null;
        }
        $level = self::levelFor(self::qualifying($userId));
        $new = $level ? (int) $level['id'] : self::fallbackLevelId();
        $old = $u['price_level_id'] !== null ? (int) $u['price_level_id'] : null;
        if ($new !== $old) {
            $db->update('users', ['price_level_id' => $new, 'updated_at' => now()], ['id' => $userId]);
            AuditService::log('price_level.auto', 'user', $userId, ['from' => $old, 'to' => $new, 'qualifying' => self::qualifying($userId)], 'system', null);
        }
        return $new;
    }

    /** Re-evaluate every automatic user (after levels are edited). @return int users changed */
    public static function syncAll(): int
    {
        $db = Database::instance();
        $levels = self::autoLevels();
        $fallback = self::fallbackLevelId();
        $changed = 0;
        foreach ($db->fetchAll('SELECT u.id, u.price_level_id, w.total_deposits FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.price_level_manual = 0 AND u.deleted_at IS NULL') as $u) {
            $level = self::levelFor((string) $u['total_deposits'], $levels);
            $new = $level ? (int) $level['id'] : $fallback;
            if ($new !== ($u['price_level_id'] !== null ? (int) $u['price_level_id'] : null)) {
                $db->update('users', ['price_level_id' => $new], ['id' => $u['id']]);
                $changed++;
            }
        }
        return $changed;
    }

    /** Admin choice: a level id (manual) or null = automatic by deposits. */
    public static function assign(int $userId, ?int $levelId): void
    {
        $db = Database::instance();
        if ($levelId !== null && !$db->fetchColumn('SELECT id FROM price_levels WHERE id = ?', [$levelId])) {
            throw new \App\Core\Exceptions\ValidationException('Unknown price level.');
        }
        $db->update('users', ['price_level_id' => $levelId, 'price_level_manual' => $levelId !== null ? 1 : 0, 'updated_at' => now()], ['id' => $userId]);
        if ($levelId === null) {
            self::sync($userId);
        }
    }

    /**
     * Everything the UI shows about a user's level.
     * @return array{current:?array, manual:bool, qualifying:string, next:?array, remaining:string, percent:int}
     */
    public static function progress(array $user): array
    {
        $db = Database::instance();
        $qualifying = self::qualifying((int) $user['id']);
        $manual = (int) ($user['price_level_manual'] ?? 0) === 1;
        $current = !empty($user['price_level_id']) ? $db->fetch('SELECT * FROM price_levels WHERE id = ?', [(int) $user['price_level_id']]) : null;
        $next = null;
        foreach (self::autoLevels() as $l) {
            if (Money::cmp((string) $l['min_deposit'], $qualifying) > 0 && (!$current || Money::cmp((string) $l['discount_percent'], (string) $current['discount_percent']) > 0)) {
                $next = $l;
                break;
            }
        }
        $remaining = $next ? Money::sub((string) $next['min_deposit'], $qualifying) : '0';
        $floor = $current && $current['min_deposit'] !== null ? (string) $current['min_deposit'] : '0';
        $span = $next ? Money::sub((string) $next['min_deposit'], $floor) : '0';
        $percent = $next && Money::isPositive($span) ? (int) max(0, min(100, floor((float) Money::sub($qualifying, $floor) / (float) $span * 100))) : 100;
        return ['current' => $current ?: null, 'manual' => $manual, 'qualifying' => $qualifying, 'next' => $next, 'remaining' => $remaining, 'percent' => $percent];
    }

    /** Settings → default level for users who qualify for no automatic level. */
    private static function fallbackLevelId(): ?int
    {
        $d = (string) setting('default_price_level', '');
        return $d !== '' && ctype_digit($d) && Database::instance()->fetchColumn('SELECT id FROM price_levels WHERE id = ?', [(int) $d]) ? (int) $d : null;
    }
}
