<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;

/**
 * Deposit promo codes: a coupon adds a bonus (percent or fixed, optionally capped)
 * to a qualifying deposit made through an allowed online gateway (all online
 * gateways, or the ones selected in Admin → Promo codes; never manual payments). Limits are re-checked under a row lock when the
 * payment completes, so concurrent use cannot exceed usage limits.
 */
final class CouponService
{
    /**
     * Validate for a prospective deposit with the chosen payment method.
     * Manual payments never take promo codes; a code limited to certain
     * gateways is refused on any other. @return array{coupon:array, bonus:string}
     */
    public static function validate(string $code, int $userId, string $amount, ?array $method = null): array
    {
        if ($method !== null && ($method['gateway'] ?? '') === 'manual') {
            throw new ValidationException('Promo codes cannot be used with manual payments. Choose an online payment method to use a code.');
        }
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new ValidationException('Invalid promo code.');
        }
        $coupon = Database::instance()->fetch('SELECT * FROM coupons WHERE code = ?', [$code]);
        $error = $coupon ? (self::check($coupon, $userId, $amount) ?? ($method !== null ? self::gatewayError($coupon, $method) : null)) : 'Invalid promo code.';
        if ($error) {
            throw new ValidationException($error);
        }
        return ['coupon' => $coupon, 'bonus' => self::bonus($coupon, $amount)];
    }

    public static function check(array $coupon, int $userId, string $amount): ?string
    {
        $now = time();
        if ($coupon['status'] !== 'active') {
            return 'This promo code is not active.';
        }
        if ($coupon['starts_at'] && strtotime($coupon['starts_at'] . ' UTC') > $now) {
            return 'This promo code is not active yet.';
        }
        if ($coupon['expires_at'] && strtotime($coupon['expires_at'] . ' UTC') < $now) {
            return 'This promo code has expired.';
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return 'This promo code has reached its usage limit.';
        }
        $userUses = (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM coupon_usage WHERE coupon_id = ? AND user_id = ?', [$coupon['id'], $userId]);
        if ($userUses >= max(1, (int) $coupon['per_user_limit'])) {
            return 'You have already used this promo code.';
        }
        if (Money::cmp($amount, (string) $coupon['min_deposit']) < 0) {
            return 'This promo code requires a minimum deposit of ' . money($coupon['min_deposit']) . '.';
        }
        return null;
    }

    /** IDs of the payment methods a code is limited to ([] = every online gateway). */
    public static function methodIds(int $couponId): array
    {
        return array_map('intval', array_column(Database::instance()->fetchAll('SELECT payment_method_id FROM coupon_payment_methods WHERE coupon_id = ?', [$couponId]), 'payment_method_id'));
    }

    public static function allowsMethod(array $coupon, array $method): bool
    {
        if (($method['gateway'] ?? '') === 'manual') {
            return false;
        }
        return (int) ($coupon['all_gateways'] ?? 1) === 1 || in_array((int) $method['id'], self::methodIds((int) $coupon['id']), true);
    }

    private static function gatewayError(array $coupon, array $method): ?string
    {
        if (self::allowsMethod($coupon, $method)) {
            return null;
        }
        $names = Database::instance()->fetchAll("SELECT pm.name FROM coupon_payment_methods c JOIN payment_methods pm ON pm.id = c.payment_method_id WHERE c.coupon_id = ? AND pm.status = 'active' AND pm.gateway <> 'manual' ORDER BY pm.sort_order, pm.id", [$coupon['id']]);
        return 'This promo code cannot be used with ' . $method['name'] . '.' . ($names ? ' It works with: ' . implode(', ', array_column($names, 'name')) . '.' : '');
    }

    public static function bonus(array $coupon, string $amount): string
    {
        $bonus = $coupon['type'] === 'percent' ? Money::percent($amount, (string) $coupon['value'], 4) : Money::of((string) $coupon['value'], 4);
        if ($coupon['max_discount'] !== null && Money::cmp($bonus, (string) $coupon['max_discount']) > 0) {
            $bonus = Money::of((string) $coupon['max_discount'], 4);
        }
        return $bonus;
    }

    /**
     * Redeem inside PaymentService::complete()'s transaction.
     * Returns the bonus credited ("0" if the coupon is no longer valid).
     */
    public static function redeem(int $couponId, int $userId, array $payment): string
    {
        $db = Database::instance();
        $coupon = $db->fetch('SELECT * FROM coupons WHERE id = ? FOR UPDATE', [$couponId]);
        if (!$coupon) {
            return '0';
        }
        if ($db->fetchColumn('SELECT id FROM coupon_usage WHERE payment_id = ?', [$payment['id']])) {
            return '0'; // already redeemed for this payment
        }
        $error = self::check($coupon, $userId, (string) $payment['amount']);
        if ($error) {
            \App\Core\Logger::info("Coupon {$coupon['code']} not applied to payment {$payment['id']}: {$error}", [], 'payment');
            return '0';
        }
        $bonus = self::bonus($coupon, (string) $payment['amount']);
        if (!Money::isPositive($bonus)) {
            return '0';
        }
        $db->insert('coupon_usage', ['coupon_id' => $couponId, 'user_id' => $userId, 'payment_id' => $payment['id'], 'amount' => $bonus, 'created_at' => now()]);
        $db->query('UPDATE coupons SET used_count = used_count + 1, updated_at = ? WHERE id = ?', [now(), $couponId]);
        WalletService::apply($userId, $bonus, 'bonus', 'payment:' . $payment['id'] . ':bonus', 'Promo code ' . $coupon['code'] . ' bonus', ['payment_id' => (int) $payment['id']]);
        return $bonus;
    }
}
