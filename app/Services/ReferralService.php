<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;

/**
 * Affiliate program: the referrer earns referral_percent of each real deposit
 * made by users they referred. Earnings accrue in the separate referral wallet
 * and can be moved to the main balance once they reach the minimum.
 */
final class ReferralService
{
    /** Link a new user to a referrer at registration, applying anti-abuse checks. */
    public static function attach(int $newUserId, string $code, string $ip): void
    {
        if (setting('referral_enabled', '1') !== '1' || $code === '') {
            return;
        }
        $db = Database::instance();
        $referrer = $db->fetch("SELECT id, register_ip, last_login_ip, status FROM users WHERE referral_code = ? AND deleted_at IS NULL", [$code]);
        if (!$referrer || (int) $referrer['id'] === $newUserId || $referrer['status'] !== 'active') {
            return;
        }
        $status = 'active';
        if (setting('referral_same_ip_block', '1') === '1' && in_array($ip, [$referrer['register_ip'], $referrer['last_login_ip']], true)) {
            $status = 'blocked'; // same network as referrer — likely self-referral
        }
        $db->insert('referrals', ['referrer_id' => $referrer['id'], 'referred_id' => $newUserId, 'ip' => $ip, 'status' => $status, 'created_at' => now()]);
        if ($status === 'active') {
            $db->update('users', ['referred_by' => $referrer['id']], ['id' => $newUserId]);
        }
    }

    /** Called inside PaymentService::complete()'s transaction for real deposits only. */
    public static function commission(array $payment): void
    {
        if (setting('referral_enabled', '1') !== '1') {
            return;
        }
        $db = Database::instance();
        $ref = $db->fetch("SELECT r.referrer_id FROM referrals r JOIN users u ON u.id = r.referrer_id WHERE r.referred_id = ? AND r.status = 'active' AND u.status = 'active' AND u.deleted_at IS NULL", [$payment['user_id']]);
        if (!$ref || (int) $ref['referrer_id'] === (int) $payment['user_id']) {
            return;
        }
        $percent = Money::of((string) setting('referral_percent', '0'), 2);
        if (!Money::isPositive($percent)) {
            return;
        }
        if ($db->fetchColumn('SELECT id FROM referral_transactions WHERE payment_id = ?', [$payment['id']])) {
            return;
        }
        $commission = Money::percent((string) $payment['amount'], $percent);
        if (!Money::isPositive($commission)) {
            return;
        }
        $db->insert('referral_transactions', [
            'referrer_id' => $ref['referrer_id'],
            'referred_id' => $payment['user_id'],
            'payment_id' => $payment['id'],
            'base_amount' => $payment['amount'],
            'percent' => $percent,
            'commission' => $commission,
            'created_at' => now(),
        ]);
        WalletService::apply((int) $ref['referrer_id'], $commission, 'affiliate_commission', 'payment:' . $payment['id'] . ':ref', 'Referral commission (' . $percent . '% of deposit #' . $payment['id'] . ')', ['payment_id' => (int) $payment['id']], 'referral');
    }

    /** Move referral earnings to the main balance. */
    public static function withdraw(int $userId): string
    {
        $min = Money::of((string) setting('referral_min_withdrawal', '0'));
        return Database::instance()->transaction(static function (Database $db) use ($userId, $min): string {
            $w = $db->fetch('SELECT referral_balance FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            $bal = Money::of((string) ($w['referral_balance'] ?? '0'));
            if (!Money::isPositive($bal)) {
                throw new ValidationException('You have no referral earnings to transfer.');
            }
            if (Money::cmp($bal, $min) < 0) {
                throw new ValidationException('The minimum transfer amount is ' . money($min) . '.');
            }
            $ref = 'refwd:' . $userId . ':' . bin2hex(random_bytes(6));
            WalletService::apply($userId, Money::negate($bal), 'affiliate_withdrawal', $ref . ':out', 'Referral earnings moved to balance', [], 'referral');
            WalletService::apply($userId, $bal, 'affiliate_commission', $ref . ':in', 'Referral earnings transfer');
            return $bal;
        });
    }

    public static function stats(int $userId): array
    {
        $db = Database::instance();
        return [
            'referrals' => (int) $db->fetchColumn("SELECT COUNT(*) FROM referrals WHERE referrer_id = ? AND status = 'active'", [$userId]),
            'earned' => Money::of((string) ($db->fetchColumn('SELECT COALESCE(SUM(commission),0) FROM referral_transactions WHERE referrer_id = ?', [$userId]) ?? '0')),
            'available' => Money::of((string) ($db->fetchColumn('SELECT referral_balance FROM wallets WHERE user_id = ?', [$userId]) ?? '0')),
        ];
    }
}
