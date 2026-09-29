<?php

declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Core\Exceptions\ValidationException;
use App\Core\HttpClient;
use App\Core\HttpResponse;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Request;
use App\Payment\AbstractGateway;
use App\Payment\CallbackResult;
use App\Payment\PaymentException;
use App\Payment\PaymentInit;

/**
 * P2Gateway.in Merchant API (UPI).
 *
 * Implemented ONLY from the merchant-dashboard API documentation supplied by
 * the site owner:
 *
 *   Auth            API token from the Merchant Dashboard, sent as form field `user_token`
 *   Create order    POST https://p2gateway.in/api/create-order   (application/x-www-form-urlencoded)
 *                   customer_mobile, user_token, amount, order_id, redirect_url, remark1
 *                   → {"status":true,"message":"Order Created Successfully","result":{"orderId":"…","payment_url":"…"}}
 *                   → {"status":false,"message":"order_id already exists"}
 *                   Orders fail automatically after 30 minutes.
 *   Check status    POST https://p2gateway.in/api/check-order-status   (form)  user_token, order_id
 *                   → {"txnStatus":"COMPLETED","resultInfo":"Transaction Success","orderId":"…",
 *                      "status":"SUCCESS","amount":"…","date":"…","utr":"…"}
 *   Webhook         a Webhook URL is configurable in the Merchant Dashboard. Its payload
 *                   format and any signature are NOT documented.
 *
 * Consequently:
 *   - callbacks are treated as untrusted notifications (callbacksAreSigned() = false):
 *     PaymentService ignores anything they claim and settles only from check-order-status;
 *   - the paid amount must match exactly; anything else is held for admin review;
 *   - no sandbox/test mode is implemented because none is documented;
 *   - the documentation does not state a currency, so the gateway is only offered when
 *     the site currency equals the configured "Account currency" (default INR, as this
 *     is a UPI gateway) — amounts are never converted.
 */
final class P2GatewayGateway extends AbstractGateway
{
    public const DEFAULT_CREATE_URL = 'https://p2gateway.in/api/create-order';
    public const DEFAULT_STATUS_URL = 'https://p2gateway.in/api/check-order-status';
    public const ORDER_TIMEOUT_MINUTES = 30;

    public function key(): string
    {
        return 'p2gateway';
    }

    public function label(): string
    {
        return 'P2Gateway (UPI)';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'user_token', 'label' => 'API token (user_token)', 'secret' => true, 'help' => 'P2Gateway Merchant Dashboard → API token. Stored encrypted; sent only server-to-server.'],
        ];
    }

    public function optionFields(): array
    {
        return [
            ['name' => 'create_order_url', 'label' => 'Create Order endpoint', 'type' => 'text', 'default' => self::DEFAULT_CREATE_URL, 'help' => 'From the P2Gateway API docs. Must be HTTPS.'],
            ['name' => 'status_url', 'label' => 'Check Order Status endpoint', 'type' => 'text', 'default' => self::DEFAULT_STATUS_URL, 'help' => 'From the P2Gateway API docs. Must be HTTPS.'],
            ['name' => 'account_currency', 'label' => 'Account currency', 'type' => 'text', 'default' => 'INR', 'help' => 'The gateway is only offered when the site currency is exactly this (amounts are not converted). The P2Gateway docs do not specify a currency; UPI settles in INR.'],
        ];
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && $this->endpoint('create_order_url', self::DEFAULT_CREATE_URL) !== null && $this->endpoint('status_url', self::DEFAULT_STATUS_URL) !== null;
    }

    public function callbacksAreSigned(): bool
    {
        return false;
    }

    public function requiresExactAmount(): bool
    {
        return true;
    }

    public function orderTimeoutMinutes(): ?int
    {
        return self::ORDER_TIMEOUT_MINUTES;
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper(trim((string) $this->opt('account_currency', 'INR'))) === strtoupper($currency);
    }

    /** Unique, never reused, alphanumeric: "SMM{paymentId}T{8 random hex}". */
    public function merchantOrderId(array $payment): string
    {
        return 'SMM' . (int) $payment['id'] . 'T' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function paymentFields(): array
    {
        return [
            ['name' => 'customer_mobile', 'label' => 'Mobile number', 'type' => 'tel', 'pattern' => '[0-9+ ]{10,14}', 'hint' => '10-digit mobile number linked to your UPI app.'],
        ];
    }

    public function validatePaymentFields(array $input): array
    {
        $raw = preg_replace('/[\s\-]/', '', (string) ($input['customer_mobile'] ?? ''));
        $mobile = preg_replace('/^(\+91|91|0)(?=\d{10}$)/', '', (string) $raw);
        if (!preg_match('/^[6-9]\d{9}$/', (string) $mobile)) {
            throw new ValidationException('Enter a valid 10-digit mobile number.');
        }
        return ['customer_mobile' => $mobile];
    }

    public function createPayment(array $payment, array $user, string $returnUrl, string $callbackUrl): PaymentInit
    {
        $meta = json_decode((string) ($payment['meta'] ?? ''), true) ?: [];
        $orderId = (string) ($payment['merchant_order_id'] ?? '');
        if ($orderId === '' || empty($meta['customer_mobile'])) {
            throw new PaymentException('P2Gateway: missing merchant order id or customer mobile');
        }
        $url = $this->endpoint('create_order_url', self::DEFAULT_CREATE_URL);
        $resp = HttpClient::post((string) $url, [
            'form' => [
                'customer_mobile' => (string) $meta['customer_mobile'],
                'user_token' => $this->cred('user_token'),
                'amount' => self::formatAmount($this->chargeAmount($payment)),
                'order_id' => $orderId,
                'redirect_url' => $returnUrl,
                'remark1' => 'Deposit #' . (int) $payment['id'],
            ],
            'timeout' => 30,
        ]);
        $this->logResponse('create-order', $orderId, $resp);

        // Request never left this server: safe to report as a plain failure.
        if ($resp->isNetworkError() && $resp->notSent) {
            throw new PaymentException('P2Gateway unreachable: ' . $resp->error);
        }
        $json = $resp->json();

        // Timeout / 5xx / non-JSON after sending: the order MAY exist. Never create another
        // one for this payment — ask check-order-status what happened first.
        if ($resp->isNetworkError() || $json === null || $resp->status >= 500) {
            $this->reconcileAmbiguousCreate($payment, 'create-order outcome unknown: ' . ($resp->error ?: 'HTTP ' . $resp->status));
        }

        if (($json['status'] ?? null) !== true) {
            $message = mb_substr(is_scalar($json['message'] ?? null) ? (string) $json['message'] : 'unknown error', 0, 200);
            if (stripos($message, 'already exists') !== false) {
                throw new PaymentException('P2Gateway rejected duplicate order_id ' . $orderId . ': ' . $message, 'This payment could not be started. Please try again.');
            }
            throw new PaymentException('P2Gateway create-order failed (HTTP ' . $resp->status . '): ' . $message);
        }

        $result = is_array($json['result'] ?? null) ? $json['result'] : [];
        $paymentUrl = is_string($result['payment_url'] ?? null) ? trim($result['payment_url']) : '';
        $gatewayOrderId = is_scalar($result['orderId'] ?? null) ? trim((string) $result['orderId']) : '';
        if ($paymentUrl === '' || $gatewayOrderId === '') {
            // The gateway says the order exists but gave us no way to send the customer there.
            $this->reconcileAmbiguousCreate($payment, 'create-order succeeded without payment_url/orderId');
        }
        return new PaymentInit(
            $paymentUrl,
            mb_substr($gatewayOrderId, 0, 128),
            gmdate('Y-m-d H:i:s', time() + self::ORDER_TIMEOUT_MINUTES * 60),
            ['p2gateway_order_id' => $gatewayOrderId]
        );
    }

    /**
     * After an ambiguous create, find out whether the order exists. Either way the
     * customer cannot be redirected (no payment_url), so we throw; the question is
     * only whether the local payment must stay pending (order may exist → cron
     * keeps verifying it until it resolves or times out) or can be failed safely.
     */
    private function reconcileAmbiguousCreate(array $payment, string $reason): never
    {
        $check = $this->verifyPayment($payment);
        $userMsg = 'The payment gateway did not respond properly. No money was taken from your balance — please try again in a minute.';
        if (!$check->valid && str_starts_with($check->error, 'Rejected:')) {
            // Gateway answered and does not know this order_id → nothing was created.
            throw new PaymentException('P2Gateway ' . $reason . '; status check: order not found', $userMsg);
        }
        throw new PaymentException('P2Gateway ' . $reason . '; status check: ' . ($check->valid ? $check->gatewayStatus : $check->error), $userMsg, true);
    }

    /**
     * The webhook format/signature is undocumented, so this only extracts an order
     * reference. Any status in the payload is recorded for logging but NEVER used.
     */
    public function handleCallback(Request $request): CallbackResult
    {
        $raw = $request->rawBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            parse_str($raw, $form);
            $data = $form ?: array_merge($request->post(), $request->all());
        }
        $flat = $data;
        if (is_array($data['result'] ?? null)) {
            $flat += $data['result'];
        }
        $pick = static function (array $keys) use ($flat): ?string {
            foreach ($keys as $k) {
                if (isset($flat[$k]) && is_scalar($flat[$k]) && trim((string) $flat[$k]) !== '') {
                    return mb_substr(trim((string) $flat[$k]), 0, 128);
                }
            }
            return null;
        };
        $merchantOrderId = $pick(['order_id']);
        $gatewayOrderId = $pick(['orderId']);
        if ($merchantOrderId === null && $gatewayOrderId === null) {
            return CallbackResult::invalid('No order reference in P2Gateway callback');
        }
        return new CallbackResult(
            true,
            $gatewayOrderId ?? $merchantOrderId,
            'pending', // claimed status deliberately ignored — settled via check-order-status
            null,
            null,
            $pick(['txnStatus', 'status']),
            $merchantOrderId ?? $gatewayOrderId,
            raw: [],
        );
    }

    public function verifyPayment(array $payment): CallbackResult
    {
        $orderId = (string) ($payment['merchant_order_id'] ?? '');
        if ($orderId === '') {
            return CallbackResult::invalid('No merchant order_id stored for this payment');
        }
        $url = $this->endpoint('status_url', self::DEFAULT_STATUS_URL);
        if ($url === null) {
            return CallbackResult::invalid('Status endpoint misconfigured');
        }
        $resp = HttpClient::post($url, [
            'form' => ['user_token' => $this->cred('user_token'), 'order_id' => $orderId],
            'timeout' => 20,
        ]);
        $this->logResponse('check-order-status', $orderId, $resp);
        if ($resp->isNetworkError()) {
            return CallbackResult::invalid('Status query failed: ' . $resp->error);
        }
        $json = $resp->json();
        if ($json === null) {
            return CallbackResult::invalid('Status query returned non-JSON (HTTP ' . $resp->status . ')');
        }
        if (($json['status'] ?? null) === false) {
            // Documented error shape: the gateway rejected the query (e.g. unknown order).
            return CallbackResult::invalid('Rejected: ' . mb_substr(is_scalar($json['message'] ?? null) ? (string) $json['message'] : 'status=false', 0, 200));
        }
        $d = $json;
        if (is_array($json['result'] ?? null)) {
            $d += $json['result'];
        }
        $txn = is_scalar($d['txnStatus'] ?? null) ? (string) $d['txnStatus'] : null;
        $st = is_string($d['status'] ?? null) ? $d['status'] : null;
        $amount = isset($d['amount']) && is_scalar($d['amount']) ? str_replace(',', '', trim((string) $d['amount'])) : null;
        return new CallbackResult(
            true,
            isset($d['orderId']) && is_scalar($d['orderId']) ? (string) $d['orderId'] : null,
            self::mapStatus($txn, $st),
            $amount !== null && Money::isNumeric($amount) ? $amount : null,
            null, // currency not part of the documented response
            mb_substr(trim(($txn ?? '?') . ($st !== null ? '/' . $st : '')), 0, 40),
            isset($d['orderId']) && is_scalar($d['orderId']) ? (string) $d['orderId'] : null,
            raw: ['resultInfo' => $d['resultInfo'] ?? null, 'date' => $d['date'] ?? null],
            utr: isset($d['utr']) && is_scalar($d['utr']) && trim((string) $d['utr']) !== '' ? mb_substr(trim((string) $d['utr']), 0, 100) : null,
        );
    }

    /**
     * Payment state from the documented fields. Only txnStatus COMPLETED/SUCCESS
     * (with `status`, when present, also SUCCESS/COMPLETED) counts as paid.
     * Anything unrecognised stays pending — it is never credited.
     */
    public static function mapStatus(?string $txnStatus, ?string $status): string
    {
        $t = strtoupper(trim((string) $txnStatus));
        $s = $status !== null ? strtoupper(trim($status)) : null;
        $failed = ['FAILED', 'FAILURE', 'ERROR', 'CANCELLED', 'CANCELED', 'EXPIRED', 'REJECTED', 'DECLINED'];
        if (in_array($t, $failed, true) || in_array($s, ['FAILED', 'FAILURE', 'ERROR'], true)) {
            return 'failed';
        }
        if (in_array($t, ['COMPLETED', 'SUCCESS'], true) && ($s === null || in_array($s, ['SUCCESS', 'COMPLETED'], true))) {
            return 'completed';
        }
        return 'pending'; // PENDING, missing or unknown
    }

    public static function formatAmount(string $amount): string
    {
        $a = Money::of($amount, 2);
        return str_ends_with($a, '.00') ? substr($a, 0, -3) : $a;
    }

    private function endpoint(string $opt, string $default): ?string
    {
        $url = trim((string) $this->opt($opt, $default)) ?: $default;
        return preg_match('#^https://[a-z0-9.-]+(/[^\s]*)?$#i', $url) ? $url : null;
    }

    /** Payment-log line WITHOUT the token (the token is only ever in the request body). */
    private function logResponse(string $call, string $orderId, HttpResponse $resp): void
    {
        Logger::info("P2Gateway {$call}", [
            'order_id' => $orderId,
            'http' => $resp->status,
            'error' => $resp->error,
            'body' => mb_substr(preg_replace('/("?user_token"?\s*[:=]\s*"?)[^",&\s}]+/i', '$1[redacted]', $resp->body), 0, 1000),
            'ms' => $resp->durationMs,
        ], 'payment');
    }
}
