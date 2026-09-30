<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;

/**
 * Provider price protection: the panel never keeps selling a service below
 * what the provider now charges (plus the required margin).
 *
 * Pricing model (see OrderService::userRate): a user pays
 *     rate × (1 − discount%)     discount = max(price level, custom discount)
 * so the lowest price anyone can pay is rate × (1 − the largest discount that
 * exists). The SAFE selling rate therefore is
 *     cost × (1 + margin%) / (1 − largest discount%)
 * which keeps even the most discounted user at or above cost + margin.
 *
 * Modes (Admin → Settings → Orders → Provider price protection):
 *   off      — legacy: nothing is checked (not recommended);
 *   protect  — prices are never changed automatically; a service whose price
 *              is no longer safe is blocked from ordering until an admin
 *              raises its price or the provider cost goes back down;
 *   auto     — the selling price is raised to the safe price automatically
 *              (never lowered: a manual price above the safe price is kept);
 *   disable  — the service is disabled; re-enabled automatically once safe.
 *
 * In every mode except "off", placing an order re-checks the user's actual
 * price against cost + margin on the server, so no path sells below cost.
 * Orders already placed keep their stored rate and charge.
 */
final class PriceProtection
{
    public const MODES = [
        'protect' => 'Protection only — block ordering when a service would sell below cost + margin',
        'auto' => 'Auto-adjust — raise the selling price to the safe minimum automatically',
        'disable' => 'Disable service — switch the service off until it is safe again',
        'off' => 'Off — no protection (not recommended)',
    ];

    private static ?string $maxDiscount = null;

    public static function mode(): string
    {
        $m = (string) setting('price_protection_mode', 'protect');
        return isset(self::MODES[$m]) ? $m : 'protect';
    }

    public static function margin(): string
    {
        $m = (string) setting('price_protection_margin', '0');
        return Money::isNumeric($m) && !Money::isNegative($m) ? Money::min(Money::of($m, 2), '1000') : '0.00';
    }

    /** Largest discount any user can get (price levels and custom discounts), below 100%. */
    public static function maxDiscount(): string
    {
        if (self::$maxDiscount === null) {
            $db = Database::instance();
            $lvl = (string) ($db->fetchColumn('SELECT MAX(discount_percent) FROM price_levels') ?? '0');
            $cus = (string) ($db->fetchColumn('SELECT MAX(custom_discount) FROM users WHERE deleted_at IS NULL') ?? '0');
            self::$maxDiscount = Money::min(Money::max(Money::of($lvl ?: '0', 2), Money::of($cus ?: '0', 2)), '99.00');
        }
        return self::$maxDiscount;
    }

    public static function reset(): void
    {
        self::$maxDiscount = null;
    }

    /** Lowest price per 1000 a single user may pay: cost + margin. */
    public static function minUserRate(string $cost): string
    {
        return Money::add($cost, Money::percent($cost, self::margin()));
    }

    /** Lowest selling rate (before discounts) that stays safe for every user. */
    public static function safeRate(string $cost): string
    {
        $need = self::minUserRate($cost);
        $keep = Money::sub('100', self::maxDiscount(), 4); // % of the rate the most-discounted user pays
        $rate = Money::div(Money::mul($need, '100', 10), $keep);
        // Round up: after the discount the price must still cover cost + margin.
        if (Money::cmp(Money::sub($rate, Money::percent($rate, self::maxDiscount())), $need) < 0) {
            $rate = Money::add($rate, '0.000001');
        }
        return $rate;
    }

    /**
     * Server-side guard used when an order is placed: refuses a price below
     * cost + margin, and services blocked by protection.
     */
    public static function assertSellable(array $service, string $userRate): void
    {
        if (self::mode() === 'off') {
            return;
        }
        if ((int) ($service['price_blocked'] ?? 0) === 1) {
            throw new ValidationException('This service is temporarily unavailable while its price is updated. Please choose another service or try again later.');
        }
        $cost = (string) ($service['provider_rate'] ?? '');
        if ($cost === '' || !Money::isPositive($cost) || empty($service['provider_id'])) {
            return; // manual service or unknown cost
        }
        if (Money::cmp($userRate, self::minUserRate($cost)) < 0) {
            $db = Database::instance();
            if ($db->query('UPDATE services SET price_blocked = 1, updated_at = ? WHERE id = ? AND price_blocked = 0', [now(), (int) $service['id']])->rowCount() === 1) {
                self::event((int) $service['id'], (int) $service['provider_id'], $cost, $cost, (string) $service['rate'], (string) $service['rate'], self::safeRate($cost), 'blocked', 'An order at ' . $userRate . ' per 1000 would sell below cost + margin (' . self::minUserRate($cost) . '); ordering blocked.');
                NotificationService::notifyAdmin('Service #' . $service['id'] . ' blocked: below provider cost', '<p>' . e($service['name']) . ' would have sold below its provider cost + margin. Raise its price (Admin → Services) or change the protection mode.</p>');
            }
            Logger::warning("Order refused: service #{$service['id']} rate {$userRate} below cost+margin", [], 'pricing');
            throw new ValidationException('This service is temporarily unavailable while its price is updated. Please choose another service or try again later.');
        }
    }

    /**
     * Apply the protection mode to one service after its provider cost was
     * (re)read. $service must contain id, provider_id, rate, status,
     * price_blocked, price_disabled. Returns the action taken (or null).
     */
    public static function evaluate(array $service, ?string $oldCost, string $newCost, string $source): ?string
    {
        $db = Database::instance();
        $mode = self::mode();
        $id = (int) $service['id'];
        $rate = (string) $service['rate'];
        $safe = self::safeRate($newCost);
        $changed = $oldCost === null || Money::cmp($oldCost, $newCost) !== 0;
        $direction = $oldCost === null ? 'set' : (Money::cmp($newCost, $oldCost) > 0 ? 'increase' : 'decrease');
        $update = [];
        $action = null;
        $msg = '';

        if ($mode !== 'off' && Money::cmp($rate, $safe) < 0) {
            if ($mode === 'auto') {
                $update = ['rate' => $safe, 'price_blocked' => 0];
                $action = 'repriced';
                $msg = "Selling price raised from {$rate} to {$safe} (safe minimum for cost {$newCost}).";
            } elseif ($mode === 'disable') {
                if ($service['status'] === 'active') { // a service an admin disabled by hand is left alone
                    $update = ['status' => 'disabled', 'price_disabled' => 1];
                    $action = 'disabled';
                    $msg = "Disabled: price {$rate} is below the safe minimum {$safe} for cost {$newCost}.";
                }
            } elseif ((int) $service['price_blocked'] === 0) {
                $update = ['price_blocked' => 1];
                $action = 'blocked';
                $msg = "Ordering blocked: price {$rate} is below the safe minimum {$safe} for cost {$newCost}. Raise the price to unblock.";
            }
        } else {
            // Safe (or protection off): lift blocks that protection itself put in place.
            if ((int) $service['price_blocked'] === 1) {
                $update['price_blocked'] = 0;
                $action = 'unblocked';
                $msg = "Price {$rate} is safe again for cost {$newCost}; ordering unblocked.";
            }
            if ((int) $service['price_disabled'] === 1) {
                $update['price_disabled'] = 0;
                if ($service['status'] === 'disabled') {
                    $update['status'] = 'active';
                }
                $action = 'restored';
                $msg = "Price {$rate} is safe again for cost {$newCost}; service re-enabled.";
            }
        }
        if ($update) {
            $db->update('services', $update + ['updated_at' => now()], ['id' => $id]);
            AuditService::log('service.price_protection', 'service', $id, ['action' => $action, 'mode' => $mode, 'cost' => [$oldCost, $newCost], 'rate' => [$rate, $update['rate'] ?? $rate], 'source' => $source], 'system');
        }
        if ($changed || $action) {
            $label = $direction === 'set' ? 'Provider cost recorded' : 'Provider cost ' . ($direction === 'increase' ? 'increased' : 'decreased') . ' from ' . $oldCost;
            self::event($id, isset($service['provider_id']) ? (int) $service['provider_id'] : null, $oldCost, $newCost, $rate, (string) ($update['rate'] ?? $rate), $safe, $action ?? $direction, trim($label . " to {$newCost}. " . $msg . " ({$source})"));
            if (in_array($action, ['repriced', 'disabled', 'blocked'], true)) {
                NotificationService::notifyAdmin('Provider price change: service #' . $id, '<p>' . e(trim($label . " to {$newCost}. " . $msg)) . '</p>');
            }
        }
        return $action;
    }

    public static function event(int $serviceId, ?int $providerId, ?string $oldCost, ?string $newCost, ?string $oldRate, ?string $newRate, ?string $safe, string $action, string $message): void
    {
        Database::instance()->insert('service_price_events', [
            'service_id' => $serviceId, 'provider_id' => $providerId ?: null, 'old_cost' => $oldCost, 'new_cost' => $newCost,
            'old_rate' => $oldRate, 'new_rate' => $newRate, 'safe_rate' => $safe, 'action' => $action, 'mode' => self::mode(),
            'message' => mb_substr($message, 0, 500), 'created_at' => now(),
        ]);
    }

    /** Services currently blocked/disabled by protection or priced below the safe minimum. */
    public static function alerts(): array
    {
        $rows = Database::instance()->fetchAll(
            "SELECT s.id, s.name, s.rate, s.provider_rate, s.status, s.price_blocked, s.price_disabled, s.auto_sync, p.name AS provider
             FROM services s LEFT JOIN providers p ON p.id = s.provider_id
             WHERE s.provider_id IS NOT NULL AND s.provider_rate IS NOT NULL AND s.provider_rate > 0 ORDER BY s.id"
        );
        $out = [];
        foreach ($rows as $r) {
            $r['safe_rate'] = self::safeRate((string) $r['provider_rate']);
            $r['below'] = Money::cmp((string) $r['rate'], $r['safe_rate']) < 0;
            if ($r['below'] || (int) $r['price_blocked'] === 1 || (int) $r['price_disabled'] === 1) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
