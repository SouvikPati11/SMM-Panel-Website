<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Payment\CallbackResult;
use App\Payment\GatewayRegistry;
use App\Payment\PaymentException;

/**
 * Deposits. The single crediting path is complete(), which is idempotent:
 * the payment row is locked, a completed payment is never credited again, and
 * the ledger reference "payment:{id}" is unique.
 */
final class PaymentService
{
    /** Active methods for the Add Funds page (automatic gateways must be implemented + configured). */
    public static function availableMethods(): array
    {
        $out = [];
        foreach (Database::instance()->fetchAll("SELECT * FROM payment_methods WHERE status = 'active' ORDER BY sort_order, id") as $m) {
            if ($m['gateway'] === 'manual') {
                $out[] = $m;
                continue;
            }
            try {
                $gw = GatewayRegistry::make($m);
                if ($gw->isImplemented() && $gw->isConfigured()) {
                    $out[] = $m;
                }
            } catch (\Throwable) {
                // unknown gateway — skip
            }
        }
        return $out;
    }

    /** Validate amount limits (global + method) and return [amount, fee]. */
    public static function validateAmount(array $method, string $amountInput): array
    {
        if (!Money::isNumeric($amountInput)) {
            throw new ValidationException('Enter a valid amount.');
        }
        $amount = Money::of($amountInput, 2);
        $min = Money::max((string) setting('min_deposit', '1'), (string) $method['min_amount']);
        $max = Money::min((string) setting('max_deposit', '100000'), (string) $method['max_amount']);
        if (Money::cmp($amount, $min) < 0) {
            throw new ValidationException('The minimum amount is ' . money($min) . '.');
        }
        if (Money::cmp($amount, $max) > 0) {
            throw new ValidationException('The maximum amount is ' . money($max) . '.');
        }
        $fee = Money::percent($amount, (string) $method['fee_percent'], 2);
        return [$amount, $fee];
    }

    /**
     * Create a pending payment and an invoice at the gateway.
     * @return array payment row (with pay_url)
     */
    public static function createGatewayPayment(array $user, int $methodId, string $amountInput, string $couponCode, string $ip): array
    {
        $db = Database::instance();
        $method = $db->fetch("SELECT * FROM payment_methods WHERE id = ? AND status = 'active'", [$methodId]);
        if (!$method || !GatewayRegistry::isAutomatic($method['gateway'])) {
            throw new ValidationException('Select a valid payment method.');
        }
        $gateway = GatewayRegistry::make($method);
        if (!$gateway->isImplemented() || !$gateway->isConfigured()) {
            throw new ValidationException('This payment method is currently unavailable.');
        }
        [$amount, $fee] = self::validateAmount($method, $amountInput);

        $couponId = null;
        if (trim($couponCode) !== '') {
            $couponId = (int) CouponService::validate($couponCode, (int) $user['id'], $amount)['coupon']['id'];
        }

        // Anti-abuse: cap simultaneous open invoices per user.
        $open = (int) $db->fetchColumn("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'pending' AND created_at > ?", [$user['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($open >= 5) {
            throw new ValidationException('You have too many unpaid invoices. Please complete or wait for them to expire.');
        }

        $expiryMin = max(10, (int) setting('payment_expiry_minutes', '60'));
        $paymentId = $db->insert('payments', [
            'user_id' => $user['id'],
            'payment_method_id' => $method['id'],
            'gateway' => $method['gateway'],
            'amount' => $amount,
            'fee' => $fee,
            'currency' => strtoupper((string) setting('currency_code', 'USD')),
            'status' => 'pending',
            'coupon_id' => $couponId,
            'ip' => $ip,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiryMin * 60),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);

        try {
            $init = $gateway->createPayment(
                $payment,
                $user,
                url('/funds/return/' . $paymentId),
                url('/webhooks/' . $method['gateway'])
            );
        } catch (PaymentException $e) {
            $db->update('payments', ['status' => 'failed', 'meta' => json_encode(['error' => mb_substr($e->getMessage(), 0, 300)]), 'updated_at' => now()], ['id' => $paymentId]);
            Logger::error('Payment creation failed: ' . $e->getMessage(), ['payment' => $paymentId], 'payment');
            throw new ValidationException($e->userMessage);
        }

        $db->update('payments', [
            'gateway_ref' => $init->gatewayRef,
            'pay_url' => $init->redirectUrl,
            'expires_at' => $init->expiresAt ?? $payment['expires_at'],
            'meta' => $init->meta ? json_encode($init->meta) : null,
            'updated_at' => now(),
        ], ['id' => $paymentId]);
        return $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    }

    /**
     * Credit a payment exactly once. Safe to call from webhooks, cron and admin
     * concurrently. Returns true if this call performed the credit.
     */
    public static function complete(int $paymentId, string $source, array $meta = []): bool
    {
        $db = Database::instance();
        $credited = $db->transaction(static function (Database $db) use ($paymentId, $source, $meta): bool {
            $p = $db->fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p) {
                throw new \RuntimeException('Payment not found');
            }
            if ($p['status'] === 'completed') {
                return false; // idempotent: already credited
            }
            $res = WalletService::apply((int) $p['user_id'], (string) $p['amount'], 'deposit', 'payment:' . $p['id'], 'Deposit via ' . self::gatewayLabel($p['gateway']) . ' #' . $p['id'], ['payment_id' => (int) $p['id']]);
            $bonus = '0';
            if ($p['coupon_id']) {
                $bonus = CouponService::redeem((int) $p['coupon_id'], (int) $p['user_id'], $p);
            }
            ReferralService::commission($p);
            $existingMeta = json_decode((string) $p['meta'], true) ?: [];
            $db->update('payments', [
                'status' => 'completed',
                'completed_at' => now(),
                'bonus_amount' => $bonus,
                'gateway_status' => isset($meta['gateway_status']) ? mb_substr((string) $meta['gateway_status'], 0, 40) : $p['gateway_status'],
                'meta' => json_encode(array_merge($existingMeta, ['completed_by' => $source], $meta)),
                'updated_at' => now(),
            ], ['id' => $paymentId]);
            return !$res['duplicate'];
        });
        if ($credited) {
            $p = $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            Logger::info("Payment #{$paymentId} completed via {$source}", ['amount' => $p['amount'], 'user' => $p['user_id']], 'payment');
            NotificationService::notify((int) $p['user_id'], 'payment', 'Funds added: ' . money($p['amount']), 'Your deposit #' . $paymentId . ' was confirmed and added to your balance.' . (Money::isPositive((string) $p['bonus_amount']) ? ' Bonus: ' . money($p['bonus_amount']) . '.' : ''), '/transactions');
        }
        return $credited;
    }

    /** Mark a non-completed payment as failed/expired/cancelled (never downgrades a completed one). */
    public static function markFinal(int $paymentId, string $status, ?string $gatewayStatus = null): void
    {
        if (!in_array($status, ['failed', 'expired', 'cancelled'], true)) {
            return;
        }
        Database::instance()->query(
            "UPDATE payments SET status = ?, gateway_status = COALESCE(?, gateway_status), updated_at = ? WHERE id = ? AND status = 'pending'",
            [$status, $gatewayStatus !== null ? mb_substr($gatewayStatus, 0, 40) : null, now(), $paymentId]
        );
    }

    /**
     * Webhook entry point for automatic gateways. Always logs the raw request.
     * Responds 200 for handled/ignorable events and 5xx only for transient
     * failures so the gateway retries later.
     */
    public static function handleWebhook(string $gatewayKey, Request $request): Response
    {
        $db = Database::instance();
        $logId = $db->insert('webhook_logs', [
            'gateway' => substr($gatewayKey, 0, 40),
            'ip' => $request->ip(),
            'headers' => json_encode(\App\Core\Logger::redact($request->headers())),
            'payload' => mb_substr($request->rawBody(), 0, 65000),
            'signature_valid' => 0,
            'created_at' => now(),
        ]);
        $finish = static function (string $result, int $status = 200, ?int $paymentId = null, bool $sigOk = false) use ($db, $logId): Response {
            $db->update('webhook_logs', ['result' => mb_substr($result, 0, 255), 'payment_id' => $paymentId, 'signature_valid' => $sigOk ? 1 : 0], ['id' => $logId]);
            return Response::text($status === 200 ? 'ok' : 'retry', $status);
        };

        $method = $db->fetch("SELECT * FROM payment_methods WHERE gateway = ? ORDER BY status = 'active' DESC, id LIMIT 1", [$gatewayKey]);
        if (!$method || !GatewayRegistry::isAutomatic($gatewayKey)) {
            return $finish('Unknown gateway', 404);
        }
        $gateway = GatewayRegistry::make($method);
        if (!$gateway->isImplemented()) {
            return $finish('Gateway not implemented; ignored', 200);
        }

        $cb = $gateway->handleCallback($request);
        if (!$cb->valid) {
            Logger::warning("Rejected {$gatewayKey} webhook: {$cb->error}", ['ip' => $request->ip()], 'webhook');
            return $finish('Rejected: ' . $cb->error, 401);
        }

        $payment = $cb->gatewayRef ? $db->fetch('SELECT * FROM payments WHERE gateway = ? AND gateway_ref = ?', [$gatewayKey, $cb->gatewayRef]) : null;
        if (!$payment && $cb->orderId && preg_match('/^PAY-(\d+)$/', $cb->orderId, $m)) {
            $payment = $db->fetch('SELECT * FROM payments WHERE id = ? AND gateway = ?', [(int) $m[1], $gatewayKey]);
        }
        if (!$payment) {
            return $finish('Payment not found for ref ' . $cb->gatewayRef, 200, null, true);
        }
        if ($cb->orderId !== null && $cb->orderId !== 'PAY-' . $payment['id']) {
            return $finish('order_id mismatch', 200, (int) $payment['id'], true);
        }
        if ($payment['status'] === 'completed') {
            return $finish('Duplicate: already completed', 200, (int) $payment['id'], true);
        }

        $db->update('payments', ['gateway_status' => mb_substr((string) $cb->gatewayStatus, 0, 40), 'updated_at' => now()], ['id' => $payment['id']]);

        if ($cb->status === 'completed') {
            // Defense in depth: confirm server-to-server before crediting.
            $verify = $gateway->verifyPayment($payment);
            if (!$verify->valid) {
                return $finish('Verification query failed: ' . $verify->error, 503, (int) $payment['id'], true);
            }
            if ($verify->status !== 'completed') {
                return $finish('Webhook said paid but API status is ' . $verify->gatewayStatus, 200, (int) $payment['id'], true);
            }
            $mismatch = self::amountMismatch($payment, $verify);
            if ($mismatch) {
                Logger::error("Payment #{$payment['id']} amount/currency mismatch: {$mismatch}", [], 'payment');
                $db->update('payments', ['meta' => json_encode(array_merge(json_decode((string) $payment['meta'], true) ?: [], ['review' => $mismatch])), 'updated_at' => now()], ['id' => $payment['id']]);
                NotificationService::notifyAdmin('Payment needs review #' . $payment['id'], '<p>' . e($mismatch) . '</p>');
                return $finish('Amount mismatch — held for review', 200, (int) $payment['id'], true);
            }
            $did = self::complete((int) $payment['id'], 'webhook', ['gateway_status' => $verify->gatewayStatus]);
            return $finish($did ? 'Credited' : 'Already credited', 200, (int) $payment['id'], true);
        }
        if (in_array($cb->status, ['failed', 'expired', 'cancelled'], true)) {
            self::markFinal((int) $payment['id'], $cb->status, $cb->gatewayStatus);
            return $finish('Marked ' . $cb->status, 200, (int) $payment['id'], true);
        }
        return $finish('Status ' . $cb->gatewayStatus . ' noted', 200, (int) $payment['id'], true);
    }

    /** Compare what the gateway says was paid with what we asked for. */
    public static function amountMismatch(array $payment, CallbackResult $r): ?string
    {
        $expected = Money::of(Money::add((string) $payment['amount'], (string) $payment['fee']), 2);
        if ($r->currency !== null && strtoupper($r->currency) !== strtoupper((string) $payment['currency'])) {
            return "currency {$r->currency} != {$payment['currency']}";
        }
        if ($r->amount !== null && Money::isNumeric((string) $r->amount) && Money::cmp(Money::round((string) $r->amount, 2), $expected) < 0) {
            return "amount {$r->amount} < {$expected}";
        }
        return null;
    }

    /**
     * Cron: poll gateways for pending payments (covers missed webhooks) and
     * expire invoices past their deadline.
     */
    public static function verifyPending(int $limit = 50): array
    {
        $db = Database::instance();
        $rows = $db->fetchAll(
            "SELECT p.*, pm.credentials_enc, pm.config, pm.gateway AS m_gateway, pm.id AS m_id, pm.status AS m_status, pm.name AS m_name
             FROM payments p JOIN payment_methods pm ON pm.id = p.payment_method_id
             WHERE p.status = 'pending' AND p.gateway <> 'manual' AND p.gateway_ref IS NOT NULL AND p.created_at < ?
             ORDER BY p.updated_at ASC LIMIT " . max(1, $limit),
            [gmdate('Y-m-d H:i:s', time() - 120)]
        );
        $out = ['checked' => 0, 'completed' => 0, 'expired' => 0, 'failed' => 0];
        foreach ($rows as $p) {
            $out['checked']++;
            $method = ['id' => $p['m_id'], 'gateway' => $p['m_gateway'], 'credentials_enc' => $p['credentials_enc'], 'config' => $p['config'], 'status' => $p['m_status'], 'name' => $p['m_name']];
            try {
                $gw = GatewayRegistry::make($method);
                if (!$gw->isImplemented()) {
                    continue;
                }
                $r = $gw->verifyPayment($p);
            } catch (\Throwable $e) {
                Logger::warning('verifyPending error: ' . $e->getMessage(), ['payment' => $p['id']], 'payment');
                continue;
            }
            $db->update('payments', ['updated_at' => now()], ['id' => $p['id']]);
            if (!$r->valid) {
                continue;
            }
            if ($r->status === 'completed') {
                if ($m = self::amountMismatch($p, $r)) {
                    Logger::error("Payment #{$p['id']} held: {$m}", [], 'payment');
                    continue;
                }
                if (self::complete((int) $p['id'], 'cron', ['gateway_status' => $r->gatewayStatus])) {
                    $out['completed']++;
                }
            } elseif (in_array($r->status, ['failed', 'expired', 'cancelled'], true)) {
                self::markFinal((int) $p['id'], $r->status, $r->gatewayStatus);
                $out[$r->status === 'failed' ? 'failed' : 'expired']++;
            }
        }
        // Pending invoices well past expiry with no gateway confirmation → expired.
        $grace = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
        $out['expired'] += $db->query("UPDATE payments SET status = 'expired', updated_at = ? WHERE status = 'pending' AND gateway <> 'manual' AND expires_at IS NOT NULL AND expires_at < ?", [now(), $grace])->rowCount();
        return $out;
    }

    public static function gatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            'oxapay' => 'OxaPay',
            'cryptomus' => 'Cryptomus',
            'p2gateway' => 'P2Gateway',
            'manual' => 'Manual payment',
            default => ucfirst($gateway),
        };
    }
}
