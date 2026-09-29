<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Payment\GatewayRegistry;

/**
 * Admin decisions on gateway payments held for review (needs_review = 1):
 *
 *  - approve: credit the payment (optionally with a corrected amount) through
 *             PaymentService::complete(), the single crediting path;
 *  - reject:  close it without credit; later callbacks/cron can never credit it;
 *  - release: clear the hold and re-verify with the gateway API right away —
 *             credited only if the gateway now confirms the exact amount,
 *             otherwise it is held again.
 *
 * Every decision is taken under a row lock, requires a note, is appended to the
 * payment's review history and written to the audit log.
 */
final class PaymentReviewService
{
    /** Largest amount an admin may credit on approval (guards against typos). */
    public static function maxApprovable(array $payment): string
    {
        $verified = $payment['verified_amount'] !== null ? (string) $payment['verified_amount'] : '0';
        return Money::cmp($verified, (string) $payment['amount']) > 0 ? Money::of($verified, 2) : Money::of((string) $payment['amount'], 2);
    }

    /** Suggested credit: what the gateway confirmed (minus our fee), else the requested amount. */
    public static function suggestedAmount(array $payment): string
    {
        if ($payment['verified_amount'] === null) {
            return Money::of((string) $payment['amount'], 2);
        }
        $net = Money::sub((string) $payment['verified_amount'], (string) $payment['fee']);
        return Money::isPositive($net) ? Money::of($net, 2) : Money::of((string) $payment['amount'], 2);
    }

    public static function approve(int $paymentId, int $adminId, string $amountInput, string $note): void
    {
        $note = self::requireNote($note, 'Enter a note explaining why this payment is approved (e.g. what you checked in the gateway dashboard).');
        $db = Database::instance();
        $log = $db->transaction(static function (Database $db) use ($paymentId, $adminId, $amountInput, $note): array {
            $p = self::lockReviewable($db, $paymentId);
            $amountInput = trim($amountInput);
            if (!Money::isNumeric($amountInput) || !Money::isPositive($amountInput)) {
                throw new ValidationException('Amount to credit must be a positive number.');
            }
            $amount = Money::of($amountInput, 2);
            $max = self::maxApprovable($p);
            if (Money::cmp($amount, $max) > 0) {
                throw new ValidationException('Amount to credit cannot exceed ' . money($max) . ' (the larger of the requested and the gateway-confirmed amount). Use a balance adjustment for anything else.');
            }
            $meta = self::appendHistory($p, 'approved', $adminId, $note, ['credited' => $amount, 'requested' => Money::of((string) $p['amount'], 2)]);
            $db->update('payments', [
                'amount' => $amount,
                'needs_review' => 0,
                'meta' => json_encode($meta),
                'updated_at' => now(),
            ], ['id' => $paymentId]);
            if (!PaymentService::complete($paymentId, 'admin-review:' . $adminId)) {
                throw new ValidationException('This payment could not be credited (it may already have been credited).');
            }
            return ['requested' => $p['amount'], 'credited' => $amount, 'verified' => $p['verified_amount'], 'status_before' => $p['status'], 'reason' => $p['__reason'], 'note' => $note];
        });
        AuditService::log('payment.review.approve', 'payment', $paymentId, $log);
    }

    public static function reject(int $paymentId, int $adminId, string $reason): void
    {
        $reason = self::requireNote($reason, 'Enter a reason for rejecting this payment (shown to the user).');
        $db = Database::instance();
        $p = $db->transaction(static function (Database $db) use ($paymentId, $adminId, $reason): array {
            $p = self::lockReviewable($db, $paymentId);
            $db->update('payments', [
                'status' => $p['status'] === 'pending' ? 'failed' : $p['status'],
                'needs_review' => 0,
                'meta' => json_encode(self::appendHistory($p, 'rejected', $adminId, $reason)),
                'updated_at' => now(),
            ], ['id' => $paymentId]);
            return $p;
        });
        AuditService::log('payment.review.reject', 'payment', $paymentId, ['amount' => $p['amount'], 'verified' => $p['verified_amount'], 'status_before' => $p['status'], 'reason' => $p['__reason'], 'note' => $reason]);
        NotificationService::notify((int) $p['user_id'], 'payment', 'Deposit #' . $paymentId . ' was not credited', $reason, '/transactions');
    }

    /** @return string outcome of the fresh gateway check */
    public static function release(int $paymentId, int $adminId, string $note): string
    {
        $note = self::requireNote($note, 'Enter a note explaining why this payment is released for re-verification.');
        $db = Database::instance();
        $p = $db->transaction(static function (Database $db) use ($paymentId, $adminId, $note): array {
            $p = self::lockReviewable($db, $paymentId);
            if (!GatewayRegistry::isAutomatic($p['gateway']) || (!$p['gateway_ref'] && !$p['merchant_order_id'])) {
                throw new ValidationException('This payment has no gateway reference to re-verify.');
            }
            $meta = self::appendHistory($p, 'released', $adminId, $note);
            $db->update('payments', ['needs_review' => 0, 'meta' => json_encode($meta), 'updated_at' => now()], ['id' => $paymentId]);
            return array_merge($p, ['needs_review' => 0, 'meta' => json_encode($meta)]);
        });
        // The gateway call happens outside the lock; settle()/complete() re-check state themselves.
        $method = $db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$p['payment_method_id']]);
        $outcome = 'Gateway not available';
        if ($method) {
            $gw = GatewayRegistry::make($method);
            $r = $gw->verifyPayment($p);
            $outcome = $r->valid ? PaymentService::settle($p, $gw, $r, 'admin-review:' . $adminId) : 'Verification failed: ' . $r->error;
        }
        AuditService::log('payment.review.release', 'payment', $paymentId, ['reason' => $p['__reason'], 'note' => $note, 'outcome' => $outcome]);
        return $outcome;
    }

    /** True when an admin rejected this payment: automatic paths must never credit it. */
    public static function wasRejected(array $payment): bool
    {
        $meta = json_decode((string) $payment['meta'], true) ?: [];
        return ($meta['review_resolution'] ?? null) === 'rejected';
    }

    private static function lockReviewable(Database $db, int $paymentId): array
    {
        $p = $db->fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if (!$p) {
            throw new ValidationException('Payment not found.');
        }
        if ($p['status'] === 'completed') {
            throw new ValidationException('This payment is already completed.');
        }
        if ((int) $p['needs_review'] !== 1) {
            throw new ValidationException('This payment is not held for review (it may already have been handled).');
        }
        $p['__reason'] = (json_decode((string) $p['meta'], true) ?: [])['review'] ?? null;
        return $p;
    }

    private static function appendHistory(array $p, string $action, int $adminId, string $note, array $extra = []): array
    {
        $meta = json_decode((string) $p['meta'], true) ?: [];
        $meta['review_history'][] = array_merge([
            'action' => $action,
            'reason' => $meta['review'] ?? null,
            'verified_amount' => $p['verified_amount'],
            'admin_id' => $adminId,
            'note' => $note,
            'at' => now(),
        ], $extra);
        $meta['review_resolution'] = $action;
        unset($meta['review']);
        return $meta;
    }

    private static function requireNote(string $note, string $message): string
    {
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 3) {
            throw new ValidationException($message);
        }
        return $note;
    }
}
