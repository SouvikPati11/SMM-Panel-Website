<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;

/**
 * The ONLY component allowed to change wallet balances.
 *
 * Guarantees:
 *  - every change is a row in `transactions` with balance_before/after;
 *  - the wallet row is locked (SELECT ... FOR UPDATE) for the duration;
 *  - a unique `reference` makes each financial event idempotent — applying
 *    the same reference twice is a no-op that returns the original row;
 *  - balances cannot go negative unless the wallet explicitly allows it.
 */
final class WalletService
{
    public const TYPES = ['deposit', 'order_charge', 'refund', 'manual_adjustment', 'bonus', 'affiliate_commission', 'affiliate_withdrawal'];

    /**
     * Apply a signed amount to a wallet. Must be called inside a DB transaction
     * (it opens one if needed).
     *
     * @param string $amount   signed decimal string (negative = debit)
     * @param string|null $reference unique idempotency key, e.g. "order:15:charge"
     * @return array{transaction_id:int, duplicate:bool, balance_after:string}
     */
    public static function apply(
        int $userId,
        string $amount,
        string $type,
        ?string $reference,
        string $description,
        array $links = [],
        string $wallet = 'main'
    ): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid transaction type');
        }
        $amount = Money::of($amount);
        if (Money::isZero($amount)) {
            throw new \InvalidArgumentException('Transaction amount cannot be zero');
        }

        $db = Database::instance();
        return $db->transaction(static function (Database $db) use ($userId, $amount, $type, $reference, $description, $links, $wallet): array {
            $w = $db->fetch('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$w) {
                throw new \RuntimeException('Wallet not found');
            }

            // Idempotency: check after acquiring the lock so concurrent callers serialise here.
            if ($reference !== null) {
                $existing = $db->fetch('SELECT id, balance_after FROM transactions WHERE reference = ?', [$reference]);
                if ($existing) {
                    return ['transaction_id' => (int) $existing['id'], 'duplicate' => true, 'balance_after' => (string) $existing['balance_after']];
                }
            }

            $column = $wallet === 'referral' ? 'referral_balance' : 'balance';
            $before = Money::of((string) $w[$column]);
            $after = Money::add($before, $amount);
            if (Money::isNegative($after) && !($wallet === 'main' && (int) $w['allow_negative'] === 1)) {
                throw new ValidationException('Insufficient balance.');
            }

            $set = [$column => $after, 'updated_at' => now()];
            if ($wallet === 'main') {
                if ($type === 'deposit') {
                    $set['total_deposits'] = Money::add((string) $w['total_deposits'], $amount);
                } elseif ($type === 'order_charge') {
                    $set['total_spent'] = Money::add((string) $w['total_spent'], Money::abs($amount));
                } elseif ($type === 'refund' && isset($links['order_id'])) {
                    $set['total_spent'] = Money::max('0', Money::sub((string) $w['total_spent'], $amount));
                }
            }
            $db->update('wallets', $set, ['user_id' => $userId]);

            $txId = $db->insert('transactions', [
                'user_id' => $userId,
                'type' => $type,
                'wallet' => $wallet,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $reference,
                'description' => mb_substr($description, 0, 255),
                'order_id' => $links['order_id'] ?? null,
                'payment_id' => $links['payment_id'] ?? null,
                'admin_id' => $links['admin_id'] ?? null,
                'created_at' => now(),
            ]);

            Logger::info('Wallet change', ['user' => $userId, 'type' => $type, 'amount' => $amount, 'ref' => $reference, 'after' => $after], 'payment');
            return ['transaction_id' => $txId, 'duplicate' => false, 'balance_after' => $after];
        });
    }

    /** Admin balance adjustment — reason and admin id are mandatory. */
    public static function adminAdjust(int $userId, string $amount, string $reason, int $adminId, string $kind = 'manual_adjustment'): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException('A reason is required for manual balance changes.');
        }
        if (!Money::isNumeric($amount) || Money::isZero($amount)) {
            throw new ValidationException('Enter a non-zero amount.');
        }
        if (!in_array($kind, ['manual_adjustment', 'bonus', 'deposit'], true)) {
            $kind = 'manual_adjustment';
        }
        $ref = 'admin:' . $adminId . ':' . bin2hex(random_bytes(8));
        $res = self::apply($userId, $amount, $kind, $ref, 'Admin: ' . $reason, ['admin_id' => $adminId]);
        AuditService::log('wallet.adjust', 'user', $userId, ['amount' => $amount, 'kind' => $kind, 'reason' => $reason, 'tx' => $res['transaction_id']]);
        return $res;
    }

    public static function balance(int $userId): string
    {
        return Money::of((string) Database::instance()->fetchColumn('SELECT balance FROM wallets WHERE user_id = ?', [$userId]));
    }

    public static function createWallet(int $userId): void
    {
        Database::instance()->query('INSERT IGNORE INTO wallets (user_id, updated_at) VALUES (?, ?)', [$userId, now()]);
    }
}
