<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;

/**
 * Manual payments (UPI, bank transfer, crypto address…): the user pays outside
 * the site, submits the reference (and optional proof), an admin approves.
 */
final class ManualPaymentService
{
    public static function submit(array $user, int $methodId, string $amountInput, string $reference, ?array $proofFile, string $note, string $couponCode = ''): int
    {
        $db = Database::instance();
        $method = $db->fetch("SELECT * FROM payment_methods WHERE id = ? AND gateway = 'manual' AND status = 'active'", [$methodId]);
        if (!$method) {
            throw new ValidationException('Select a valid payment method.');
        }
        [$amount] = PaymentService::validateAmount($method, $amountInput);
        $reference = trim($reference);
        if (!preg_match('/^[A-Za-z0-9 _\-\/.#:]{4,120}$/', $reference)) {
            throw new ValidationException('Enter the transaction / reference ID exactly as shown in your payment app (4–120 characters).');
        }
        if ($db->fetchColumn('SELECT id FROM manual_payment_requests WHERE payment_method_id = ? AND reference = ?', [$methodId, $reference])) {
            throw new ValidationException('This transaction reference has already been submitted.');
        }
        $pending = (int) $db->fetchColumn("SELECT COUNT(*) FROM manual_payment_requests WHERE user_id = ? AND status = 'pending'", [$user['id']]);
        if ($pending >= 5) {
            throw new ValidationException('You already have several requests awaiting review. Please wait for them to be processed.');
        }
        if ((int) $method['require_proof'] === 1 && !$proofFile) {
            throw new ValidationException('Please upload a screenshot of your payment.');
        }
        $couponId = null;
        if (trim($couponCode) !== '') {
            $couponId = (int) CouponService::validate($couponCode, (int) $user['id'], $amount)['coupon']['id'];
        }
        $proofPath = $proofFile ? UploadService::storePrivateImage($proofFile, 'proofs') : null;

        try {
            $id = $db->insert('manual_payment_requests', [
                'user_id' => $user['id'],
                'payment_method_id' => $methodId,
                'amount' => $amount,
                'reference' => $reference,
                'proof_path' => $proofPath,
                'user_note' => mb_substr(trim($note), 0, 500) ?: null,
                'coupon_id' => $couponId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ] + PaymentService::bonusTerms($method));
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_mpr_reference')) {
                throw new ValidationException('This transaction reference has already been submitted.');
            }
            throw $e;
        }
        NotificationService::notifyAdmin('New manual payment #' . $id, '<p>' . e($user['username']) . ' submitted ' . e(money($amount)) . ' via ' . e($method['name']) . ' (ref ' . e($reference) . ').</p>');
        return $id;
    }

    /** Approve: creates a payments row and credits it via the single idempotent path. */
    public static function approve(int $requestId, int $adminId, ?string $amountOverride, string $note): void
    {
        $db = Database::instance();
        $paymentId = $db->transaction(static function (Database $db) use ($requestId, $adminId, $amountOverride, $note): int {
            $req = $db->fetch('SELECT * FROM manual_payment_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if (!$req) {
                throw new ValidationException('Request not found.');
            }
            if ($req['status'] !== 'pending') {
                throw new ValidationException('This request was already ' . $req['status'] . '.');
            }
            $amount = (string) $req['amount'];
            if ($amountOverride !== null && trim($amountOverride) !== '') {
                if (!Money::isNumeric($amountOverride) || !Money::isPositive($amountOverride)) {
                    throw new ValidationException('Approved amount must be a positive number.');
                }
                $amount = Money::of($amountOverride, 2);
            }
            $couponId = $req['coupon_id'] ? (int) $req['coupon_id'] : null;
            $paymentId = $db->insert('payments', [
                'user_id' => $req['user_id'],
                'payment_method_id' => $req['payment_method_id'],
                'gateway' => 'manual',
                'amount' => $amount,
                'fee' => '0',
                'currency' => strtoupper((string) setting('currency_code', 'USD')),
                'gateway_ref' => 'manual-' . $requestId,
                'status' => 'pending',
                'coupon_id' => $couponId,
                // Bonus terms as they were when the user submitted the request.
                'gw_bonus_percent' => $req['gw_bonus_percent'] ?? null,
                'gw_bonus_fixed' => $req['gw_bonus_fixed'] ?? null,
                'gw_bonus_min' => $req['gw_bonus_min'] ?? null,
                'meta' => json_encode(['manual_request' => $requestId, 'reference' => $req['reference'], 'admin_id' => $adminId]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $db->update('manual_payment_requests', [
                'status' => 'approved',
                'payment_id' => $paymentId,
                'admin_id' => $adminId,
                'admin_note' => mb_substr($note, 0, 500) ?: null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ], ['id' => $requestId]);
            PaymentService::complete($paymentId, 'admin:' . $adminId);
            return $paymentId;
        });
        AuditService::log('manual_payment.approve', 'manual_payment', $requestId, ['payment_id' => $paymentId, 'amount_override' => $amountOverride]);
    }

    public static function reject(int $requestId, int $adminId, string $reason): void
    {
        $db = Database::instance();
        $req = $db->fetch('SELECT * FROM manual_payment_requests WHERE id = ?', [$requestId]);
        if (!$req || $req['status'] !== 'pending') {
            throw new ValidationException('This request is not pending.');
        }
        if (trim($reason) === '') {
            throw new ValidationException('Enter a reason for rejection (shown to the user).');
        }
        $n = $db->query("UPDATE manual_payment_requests SET status = 'rejected', admin_id = ?, admin_note = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [$adminId, mb_substr($reason, 0, 500), now(), now(), $requestId])->rowCount();
        if ($n !== 1) {
            throw new ValidationException('This request is not pending.');
        }
        AuditService::log('manual_payment.reject', 'manual_payment', $requestId, ['reason' => $reason]);
        NotificationService::notify((int) $req['user_id'], 'payment', 'Payment request #' . $requestId . ' was rejected', $reason, '/funds');
    }
}
