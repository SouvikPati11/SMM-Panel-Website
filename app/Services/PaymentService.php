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
                if ($gw->isImplemented() && $gw->isConfigured() && $gw->supportsCurrency((string) setting('currency_code', 'USD'))) {
                    $out[] = $m;
                }
            } catch (\Throwable) {
                // unknown gateway — skip
            }
        }
        return $out;
    }

    /**
     * Validate the amount against the selected gateway's own limits and return
     * [amount, fee]. Limits are per gateway (payment_methods.min_amount /
     * max_amount); the old global min_deposit/max_deposit settings were folded
     * into every gateway by migration 2026_10_12 and are no longer read.
     */
    public static function validateAmount(array $method, string $amountInput): array
    {
        $amountInput = trim($amountInput);
        if (!Money::isNumeric($amountInput) || !Money::isPositive($amountInput)) {
            throw new ValidationException('Enter a valid amount.');
        }
        if (preg_match('/\.\d{3,}$/', $amountInput) && Money::cmp(Money::of($amountInput, 2), Money::of($amountInput)) !== 0) {
            throw new ValidationException('Enter the amount with at most 2 decimal places.');
        }
        $amount = Money::of($amountInput, 2);
        $min = Money::of((string) $method['min_amount'], 2);
        $max = Money::of((string) $method['max_amount'], 2);
        if (Money::cmp($amount, $min) < 0) {
            throw new ValidationException('The minimum deposit with ' . $method['name'] . ' is ' . money_base($min) . '.');
        }
        if (Money::cmp($amount, $max) > 0) {
            throw new ValidationException('The maximum deposit with ' . $method['name'] . ' is ' . money_base($max) . '.');
        }
        $fee = Money::percent($amount, (string) $method['fee_percent'], 2);
        return [$amount, $fee];
    }

    /**
     * Gateway deposit bonus (Admin → Payment gateways): percent of the deposit
     * plus an optional fixed amount, for deposits of at least the minimum.
     * Calculated on the server only, from terms snapshotted on the payment
     * when it was created (a later change of the gateway's terms never alters
     * a payment already in progress).
     */
    public static function gatewayBonus(string $amount, ?string $percent, ?string $fixed, ?string $min): string
    {
        $percent = Money::isNumeric((string) $percent) ? Money::max(Money::of((string) $percent, 2), '0') : '0';
        $fixed = Money::isNumeric((string) $fixed) ? Money::max(Money::of((string) $fixed, 4), '0') : '0';
        if (!Money::isPositive($amount) || (!Money::isPositive($percent) && !Money::isPositive($fixed))) {
            return '0.0000';
        }
        if ($min !== null && $min !== '' && Money::isNumeric($min) && Money::cmp($amount, Money::of($min, 4)) < 0) {
            return '0.0000';
        }
        return Money::of(Money::add(Money::percent($amount, $percent, 4), $fixed), 4);
    }

    /** Bonus terms of a payment method, to snapshot on a payment / manual request. */
    public static function bonusTerms(array $method): array
    {
        $pct = Money::of((string) ($method['bonus_percent'] ?? '0'), 2);
        $fixed = Money::of((string) ($method['bonus_fixed'] ?? '0'), 4);
        if (!Money::isPositive($pct) && !Money::isPositive($fixed)) {
            return ['gw_bonus_percent' => null, 'gw_bonus_fixed' => null, 'gw_bonus_min' => null];
        }
        $min = Money::of((string) ($method['bonus_min_amount'] ?? '0'), 4);
        return ['gw_bonus_percent' => $pct, 'gw_bonus_fixed' => $fixed, 'gw_bonus_min' => Money::isPositive($min) ? $min : null];
    }

    /** Short human description of a method's bonus ("5% bonus + $1.00 on deposits of $10.00 or more"), or ''. */
    public static function bonusLabel(array $method): string
    {
        $t = self::bonusTerms($method);
        if ($t['gw_bonus_percent'] === null) {
            return '';
        }
        $parts = [];
        if (Money::isPositive($t['gw_bonus_percent'])) {
            $parts[] = rtrim(rtrim($t['gw_bonus_percent'], '0'), '.') . '%';
        }
        if (Money::isPositive($t['gw_bonus_fixed'])) {
            $parts[] = money_base($t['gw_bonus_fixed']);
        }
        return implode(' + ', $parts) . ' bonus' . ($t['gw_bonus_min'] !== null ? ' on deposits of ' . money_base($t['gw_bonus_min']) . ' or more' : '');
    }

    /**
     * Create a pending payment and an invoice at the gateway.
     * @param array $fields customer inputs required by the gateway (paymentFields())
     * @return array payment row (with pay_url)
     */
    public static function createGatewayPayment(array $user, int $methodId, string $amountInput, string $couponCode, string $ip, array $fields = []): array
    {
        $db = Database::instance();
        $method = $db->fetch("SELECT * FROM payment_methods WHERE id = ? AND status = 'active'", [$methodId]);
        if (!$method || !GatewayRegistry::isAutomatic($method['gateway'])) {
            throw new ValidationException('Select a valid payment method.');
        }
        $gateway = GatewayRegistry::make($method);
        $currency = strtoupper((string) setting('currency_code', 'USD'));
        if (!$gateway->isImplemented() || !$gateway->isConfigured() || !$gateway->supportsCurrency($currency)) {
            throw new ValidationException('This payment method is currently unavailable.');
        }
        [$amount, $fee] = self::validateAmount($method, $amountInput);
        $extra = $gateway->validatePaymentFields($fields);

        $couponId = null;
        if (trim($couponCode) !== '') {
            $couponId = (int) CouponService::validate($couponCode, (int) $user['id'], $amount, $method)['coupon']['id'];
        }

        // Anti-abuse: cap simultaneous open invoices per user.
        $open = (int) $db->fetchColumn("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'pending' AND created_at > ?", [$user['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ($open >= 5) {
            throw new ValidationException('You have too many unpaid invoices. Please complete or wait for them to expire.');
        }

        $expiryMin = $gateway->orderTimeoutMinutes() ?? max(10, (int) setting('payment_expiry_minutes', '60'));
        $paymentId = $db->insert('payments', [
            'user_id' => $user['id'],
            'payment_method_id' => $method['id'],
            'gateway' => $method['gateway'],
            'amount' => $amount,
            'fee' => $fee,
            'currency' => $currency,
            'status' => 'pending',
            'coupon_id' => $couponId,
            'meta' => $extra ? json_encode($extra) : null,
            'ip' => $ip,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiryMin * 60),
            'created_at' => now(),
            'updated_at' => now(),
        ] + self::bonusTerms($method)); // bonus terms snapshotted: credited on completion
        // Unique merchant reference (DB-enforced). LOCAL PAYMENT ID → MERCHANT ORDER ID → GATEWAY ORDER ID.
        $db->update('payments', ['merchant_order_id' => $gateway->merchantOrderId(['id' => $paymentId])], ['id' => $paymentId]);
        $payment = $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);

        $failWith = static function (string $reason, string $userMessage, bool $keepPending) use ($db, $payment, $paymentId): never {
            $meta = array_merge(json_decode((string) $payment['meta'], true) ?: [], ['error' => mb_substr($reason, 0, 300)]);
            if ($keepPending) {
                // The order may exist at the gateway: keep it pending so cron/webhook verification
                // resolves it; it can only ever be credited through verified status checks.
                $db->update('payments', ['meta' => json_encode($meta + ['ambiguous_create' => true]), 'updated_at' => now()], ['id' => $paymentId]);
            } else {
                // Nothing of ours exists at the gateway. Detach the reference so no later
                // callback/status check can ever be matched to this payment.
                $meta['attempted_order_id'] = $payment['merchant_order_id'];
                $db->update('payments', ['status' => 'failed', 'merchant_order_id' => null, 'meta' => json_encode($meta), 'updated_at' => now()], ['id' => $paymentId]);
            }
            Logger::error('Payment creation failed: ' . $reason, ['payment' => $paymentId, 'kept_pending' => $keepPending], 'payment');
            throw new ValidationException($userMessage);
        };

        try {
            $init = $gateway->createPayment(
                $payment,
                $user,
                url('/funds/return/' . $paymentId),
                url('/webhooks/' . $method['gateway'])
            );
        } catch (PaymentException $e) {
            $failWith($e->getMessage(), $e->userMessage, $e->ambiguous);
        }

        if (!preg_match('#^https://[^\s"<>]+$#i', $init->redirectUrl)) {
            $failWith('Gateway returned a non-HTTPS payment URL', 'The payment gateway returned an invalid response. Please try another method.', true);
        }
        $db->update('payments', [
            'gateway_ref' => $init->gatewayRef,
            'pay_url' => $init->redirectUrl,
            'expires_at' => $init->expiresAt ?? $payment['expires_at'],
            'meta' => json_encode(array_merge(json_decode((string) $payment['meta'], true) ?: [], $init->meta)) ?: null,
            'updated_at' => now(),
        ], ['id' => $paymentId]);
        return $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    }

    /**
     * Credit a payment exactly once. Safe to call from webhooks, cron and admin
     * concurrently. Returns true if this call performed the credit.
     */
    public static function complete(int $paymentId, string $source, array $meta = [], array $verified = []): bool
    {
        $db = Database::instance();
        $credited = $db->transaction(static function (Database $db) use ($paymentId, $source, $meta, $verified): bool {
            $p = $db->fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p) {
                throw new \RuntimeException('Payment not found');
            }
            if ($p['status'] === 'completed') {
                return false; // idempotent: already credited
            }
            if (!str_starts_with($source, 'admin') && ((int) $p['needs_review'] === 1 || PaymentReviewService::wasRejected($p))) {
                return false; // held for review (or rejected) by an admin decision: only an admin may credit it
            }
            $res = WalletService::apply((int) $p['user_id'], (string) $p['amount'], 'deposit', 'payment:' . $p['id'], 'Deposit via ' . self::gatewayLabel($p['gateway']) . ' #' . $p['id'], ['payment_id' => (int) $p['id']]);
            $bonus = '0';
            if ($p['coupon_id']) {
                $bonus = CouponService::redeem((int) $p['coupon_id'], (int) $p['user_id'], $p);
            }
            // Gateway bonus: separate ledger entry with its own unique reference (credited once).
            $gwBonus = self::gatewayBonus((string) $p['amount'], $p['gw_bonus_percent'] ?? null, $p['gw_bonus_fixed'] ?? null, $p['gw_bonus_min'] ?? null);
            if (Money::isPositive($gwBonus)) {
                WalletService::apply((int) $p['user_id'], $gwBonus, 'bonus', 'payment:' . $p['id'] . ':gwbonus', self::gatewayLabel($p['gateway']) . ' deposit bonus (payment #' . $p['id'] . ')', ['payment_id' => (int) $p['id']]);
            }
            ReferralService::commission($p);
            $existingMeta = json_decode((string) $p['meta'], true) ?: [];
            $db->update('payments', [
                'status' => 'completed',
                'completed_at' => now(),
                'bonus_amount' => $bonus,
                'gw_bonus_amount' => $gwBonus,
                'gateway_status' => isset($meta['gateway_status']) ? mb_substr((string) $meta['gateway_status'], 0, 40) : $p['gateway_status'],
                'meta' => json_encode(array_merge($existingMeta, ['completed_by' => $source], $meta)),
                'verified_amount' => isset($verified['amount']) && Money::isNumeric((string) $verified['amount']) ? Money::of((string) $verified['amount'], 4) : $p['verified_amount'],
                'utr' => isset($verified['utr']) ? mb_substr((string) $verified['utr'], 0, 100) : $p['utr'],
                'updated_at' => now(),
            ], ['id' => $paymentId]);
            return !$res['duplicate'];
        });
        if ($credited) {
            $p = $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            Logger::info("Payment #{$paymentId} completed via {$source}", ['amount' => $p['amount'], 'user' => $p['user_id']], 'payment');
            NotificationService::notify((int) $p['user_id'], 'payment', 'Funds added: ' . money($p['amount']), 'Your deposit #' . $paymentId . ' was confirmed and added to your balance.' . (Money::isPositive((string) $p['bonus_amount']) ? ' Promo bonus: ' . money($p['bonus_amount']) . '.' : '') . (Money::isPositive((string) $p['gw_bonus_amount']) ? ' Deposit bonus: ' . money($p['gw_bonus_amount']) . '.' : ''), '/transactions');
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
            'payload' => mb_substr(self::maskSecrets($request->rawBody() !== '' ? $request->rawBody() : http_build_query($request->all())), 0, 65000),
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
        $signed = $gateway->callbacksAreSigned();
        if (!$cb->valid) {
            Logger::warning("Rejected {$gatewayKey} webhook: {$cb->error}", ['ip' => $request->ip()], 'webhook');
            return $finish('Rejected: ' . $cb->error, $signed ? 401 : 400);
        }

        $payment = self::findPayment($gatewayKey, $cb->gatewayRef, $cb->orderId);
        if (!$payment) {
            return $finish('Payment not found for ref ' . ($cb->orderId ?? $cb->gatewayRef), 200, null, $signed);
        }
        if ($payment['status'] === 'completed') {
            return $finish('Duplicate: already completed', 200, (int) $payment['id'], $signed);
        }

        if (!$signed) {
            // Unauthenticated notification: nothing in it is trusted. Settle strictly from
            // the gateway's status API.
            $verify = $gateway->verifyPayment($payment);
            if (!$verify->valid) {
                return $finish('Unsigned callback; verification failed: ' . $verify->error, 503, (int) $payment['id']);
            }
            return $finish('Unsigned callback; API-verified: ' . self::settle($payment, $gateway, $verify, 'webhook'), 200, (int) $payment['id']);
        }

        if ($cb->orderId !== null && !self::referenceMatches($payment, $cb->orderId)) {
            return $finish('order_id mismatch', 200, (int) $payment['id'], true);
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
            return $finish(self::settle($payment, $gateway, $verify, 'webhook'), 200, (int) $payment['id'], true);
        }
        if (in_array($cb->status, ['failed', 'expired', 'cancelled'], true)) {
            self::markFinal((int) $payment['id'], $cb->status, $cb->gatewayStatus);
            return $finish('Marked ' . $cb->status, 200, (int) $payment['id'], true);
        }
        return $finish('Status ' . $cb->gatewayStatus . ' noted', 200, (int) $payment['id'], true);
    }

    /** Never store tokens/keys that a gateway might echo back in a callback. */
    public static function maskSecrets(string $raw): string
    {
        return (string) preg_replace('/("?(?:user_token|api_key|merchant_api_key|token|secret|sign)"?\s*[:=]\s*"?)[^",&\s}]+/i', '$1[redacted]', $raw);
    }

    /** Locate a payment of this gateway by gateway reference or merchant order id. */
    private static function findPayment(string $gatewayKey, ?string $gatewayRef, ?string $orderId): ?array
    {
        $db = Database::instance();
        foreach (array_unique(array_filter([$gatewayRef, $orderId])) as $ref) {
            $p = $db->fetch('SELECT * FROM payments WHERE gateway = ? AND (gateway_ref = ? OR merchant_order_id = ?) LIMIT 1', [$gatewayKey, $ref, $ref]);
            if ($p) {
                return $p;
            }
        }
        if ($orderId !== null && preg_match('/^PAY-(\d+)$/', $orderId, $m)) {
            // Legacy/default references only — never lets a guessed ID reach payments that use other order ids.
            $p = $db->fetch('SELECT * FROM payments WHERE id = ? AND gateway = ?', [(int) $m[1], $gatewayKey]);
            if ($p && ($p['merchant_order_id'] === null || $p['merchant_order_id'] === $orderId)) {
                return $p;
            }
        }
        return null;
    }

    private static function referenceMatches(array $payment, string $ref): bool
    {
        return in_array($ref, array_filter([(string) $payment['merchant_order_id'], (string) $payment['gateway_ref'], 'PAY-' . $payment['id']]), true);
    }

    /**
     * Apply a SERVER-SIDE verification result to a payment. The only place that
     * decides to credit: ownership → status → amount → idempotent complete().
     * @return string short outcome for logs
     */
    public static function settle(array $payment, \App\Payment\PaymentGatewayInterface $gateway, CallbackResult $verify, string $source): string
    {
        $db = Database::instance();
        $pid = (int) $payment['id'];
        if (PaymentReviewService::wasRejected($payment)) {
            return 'Rejected by admin — ignored';
        }
        if ($verify->orderId !== null && $verify->orderId !== '' && !self::referenceMatches($payment, $verify->orderId)) {
            self::flagReview($payment, 'Verification returned a different order reference (' . $verify->orderId . ')');
            return 'Reference mismatch — held for review';
        }
        if ($verify->gatewayStatus !== null) {
            $db->update('payments', ['gateway_status' => mb_substr($verify->gatewayStatus, 0, 40), 'updated_at' => now()], ['id' => $pid]);
        }
        if ($verify->status === 'completed') {
            if ((int) $payment['needs_review'] === 1) {
                return 'Held for admin review';
            }
            $mismatch = self::amountMismatch($payment, $verify, $gateway->requiresExactAmount());
            if ($mismatch) {
                self::flagReview($payment, $mismatch, $verify);
                return 'Amount mismatch — held for review';
            }
            $did = self::complete($pid, $source, ['gateway_status' => $verify->gatewayStatus], ['amount' => $verify->amount, 'utr' => $verify->utr]);
            return $did ? 'Credited' : 'Already credited';
        }
        if (in_array($verify->status, ['failed', 'expired', 'cancelled'], true)) {
            self::markFinal($pid, $verify->status, $verify->gatewayStatus);
            return 'Marked ' . $verify->status;
        }
        return 'Still pending (' . ($verify->gatewayStatus ?? '?') . ')';
    }

    /** Hold a payment for manual review (never credited automatically); admin alerted once. */
    private static function flagReview(array $payment, string $reason, ?CallbackResult $verify = null): void
    {
        $db = Database::instance();
        $meta = array_merge(json_decode((string) $payment['meta'], true) ?: [], ['review' => mb_substr($reason, 0, 250)]);
        $n = $db->query(
            'UPDATE payments SET needs_review = 1, meta = ?, verified_amount = COALESCE(?, verified_amount), utr = COALESCE(?, utr), updated_at = ? WHERE id = ? AND needs_review = 0',
            [json_encode($meta), $verify?->amount !== null && Money::isNumeric((string) $verify->amount) ? Money::of((string) $verify->amount, 4) : null, $verify?->utr, now(), $payment['id']]
        )->rowCount();
        Logger::error("Payment #{$payment['id']} held for review: {$reason}", [], 'payment');
        if ($n === 1) {
            NotificationService::notifyAdmin('Payment needs review #' . $payment['id'], '<p>' . e($reason) . '</p>');
        }
    }

    /** Verify one pending payment with its gateway right now (return page, admin button). */
    public static function verifyOne(int $paymentId, string $source): ?string
    {
        $db = Database::instance();
        $p = $db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'pending' || !GatewayRegistry::isAutomatic($p['gateway']) || (!$p['gateway_ref'] && !$p['merchant_order_id'])) {
            return null;
        }
        $method = $db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$p['payment_method_id']]);
        if (!$method) {
            return null;
        }
        $gw = GatewayRegistry::make($method);
        if (!$gw->isImplemented()) {
            return null;
        }
        $r = $gw->verifyPayment($p);
        if (!$r->valid) {
            return 'Verification failed: ' . $r->error;
        }
        return self::settle($p, $gw, $r, $source);
    }

    /** Compare what the gateway says was paid with what we asked for. */
    public static function amountMismatch(array $payment, CallbackResult $r, bool $exact = false): ?string
    {
        $expected = Money::of(Money::add((string) $payment['amount'], (string) $payment['fee']), 2);
        if ($r->currency !== null && strtoupper($r->currency) !== strtoupper((string) $payment['currency'])) {
            return "currency {$r->currency} != {$payment['currency']}";
        }
        if ($exact) {
            if ($r->amount === null || !Money::isNumeric((string) $r->amount)) {
                return 'verified amount missing from gateway response';
            }
            if (Money::cmp(Money::round((string) $r->amount, 2), $expected) !== 0) {
                return "amount {$r->amount} != {$expected}";
            }
            return null;
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
             WHERE p.status = 'pending' AND p.gateway <> 'manual' AND p.needs_review = 0
               AND (p.gateway_ref IS NOT NULL OR p.merchant_order_id IS NOT NULL) AND p.created_at < ?
             ORDER BY p.updated_at ASC LIMIT " . max(1, $limit),
            [gmdate('Y-m-d H:i:s', time() - 120)]
        );
        $out = ['checked' => 0, 'completed' => 0, 'expired' => 0, 'failed' => 0, 'held' => 0];
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
            $res = self::settle($p, $gw, $r, 'cron');
            match (true) {
                $res === 'Credited' => $out['completed']++,
                str_contains($res, 'review') => $out['held']++,
                $res === 'Marked failed' => $out['failed']++,
                str_starts_with($res, 'Marked') => $out['expired']++,
                default => null,
            };
        }
        // Pending invoices well past expiry with no gateway confirmation → expired.
        $grace = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
        $out['expired'] += $db->query("UPDATE payments SET status = 'expired', updated_at = ? WHERE status = 'pending' AND needs_review = 0 AND gateway <> 'manual' AND expires_at IS NOT NULL AND expires_at < ?", [now(), $grace])->rowCount();
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
