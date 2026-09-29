<?php

declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Request;
use App\Payment\AbstractGateway;
use App\Payment\CallbackResult;
use App\Payment\PaymentException;
use App\Payment\PaymentInit;

/**
 * OxaPay Merchant API v1.
 *
 * Verified against OxaPay's official PHP SDK (github.com/OxaPay/oxapay-php):
 *   base URL        https://api.oxapay.com/v1
 *   auth header     merchant_api_key: <MERCHANT_API_KEY>
 *   create invoice  POST /payment/invoice   → data.track_id, data.payment_url, data.expired_at
 *   payment info    GET  /payment/{track_id} → data.status, data.amount, data.currency, data.order_id
 *   webhook         JSON body; header HMAC = hash_hmac('sha512', raw_body, MERCHANT_API_KEY)
 *                   The merchant must reply HTTP 200 with body "ok".
 * Docs: https://docs.oxapay.com/api-reference/payment/generate-invoice , https://docs.oxapay.com/webhook
 */
final class OxaPayGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.oxapay.com/v1';

    public function key(): string
    {
        return 'oxapay';
    }

    public function label(): string
    {
        return 'OxaPay (Crypto)';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'merchant_api_key', 'label' => 'Merchant API Key', 'secret' => true, 'help' => 'OxaPay dashboard → Merchant Service → API key. Also used to verify webhook HMAC signatures.'],
        ];
    }

    public function optionFields(): array
    {
        return [
            ['name' => 'lifetime', 'label' => 'Invoice lifetime (minutes)', 'type' => 'number', 'default' => '60', 'help' => 'How long the invoice stays payable (OxaPay accepts 15–2880).'],
            ['name' => 'fee_paid_by_payer', 'label' => 'Payer pays OxaPay fee', 'type' => 'bool', 'default' => '0'],
            ['name' => 'under_paid_coverage', 'label' => 'Underpaid coverage (%)', 'type' => 'number', 'default' => '0', 'help' => 'Percentage of underpayment OxaPay still treats as paid (0–60).'],
            ['name' => 'to_currency', 'label' => 'Auto-convert to (optional)', 'type' => 'text', 'default' => '', 'help' => 'e.g. USDT to auto-convert received crypto. Leave empty to keep as received.'],
            ['name' => 'sandbox', 'label' => 'Sandbox mode', 'type' => 'bool', 'default' => '0', 'help' => 'Creates test invoices. Never enable on a live site.'],
            ['name' => 'allowed_ips', 'label' => 'Webhook IP allow-list (optional)', 'type' => 'text', 'default' => '', 'help' => 'Comma-separated IPs. Ask OxaPay support for their current webhook IPs. Empty = rely on HMAC only.'],
        ];
    }

    public function createPayment(array $payment, array $user, string $returnUrl, string $callbackUrl): PaymentInit
    {
        $payload = [
            'amount' => (float) $this->chargeAmount($payment), // API expects a JSON number
            'currency' => strtoupper((string) $payment['currency']),
            'lifetime' => max(15, min(2880, (int) $this->opt('lifetime', 60))),
            'fee_paid_by_payer' => (int) ((string) $this->opt('fee_paid_by_payer', '0') === '1'),
            'under_paid_coverage' => max(0, min(60, (float) $this->opt('under_paid_coverage', 0))),
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'order_id' => 'PAY-' . $payment['id'],
            'description' => 'Balance top-up #' . $payment['id'],
            'sandbox' => (string) $this->opt('sandbox', '0') === '1',
        ];
        if (filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $payload['email'] = $user['email'];
        }
        if (($to = trim((string) $this->opt('to_currency', ''))) !== '') {
            $payload['to_currency'] = strtoupper($to);
        }

        $resp = HttpClient::post(self::BASE_URL . '/payment/invoice', [
            'headers' => ['merchant_api_key' => $this->cred('merchant_api_key')],
            'json' => $payload,
            'timeout' => 30,
        ]);
        $json = $resp->json() ?? [];
        $data = $json['data'] ?? [];
        if (!$resp->ok() || empty($data['track_id']) || empty($data['payment_url'])) {
            Logger::error('OxaPay invoice creation failed', ['payment' => $payment['id'], 'http' => $resp->status, 'error' => $resp->error, 'body' => mb_substr($resp->body, 0, 1000)], 'payment');
            throw new PaymentException('OxaPay invoice failed: HTTP ' . $resp->status . ' ' . ($json['message'] ?? $resp->error ?? ''));
        }
        $expires = isset($data['expired_at']) && is_numeric($data['expired_at']) ? gmdate('Y-m-d H:i:s', (int) $data['expired_at']) : null;
        return new PaymentInit((string) $data['payment_url'], (string) $data['track_id'], $expires, ['oxapay_date' => $data['date'] ?? null]);
    }

    public function handleCallback(Request $request): CallbackResult
    {
        $raw = $request->rawBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return CallbackResult::invalid('Body is not JSON');
        }
        $secret = $this->cred('merchant_api_key');
        $hmac = (string) ($request->header('HMAC') ?? '');
        if ($secret === '' || $hmac === '') {
            return CallbackResult::invalid('Missing HMAC header or API key', $data);
        }
        if (!hash_equals(hash_hmac('sha512', $raw, $secret), strtolower(trim($hmac)))) {
            return CallbackResult::invalid('Invalid HMAC signature', $data);
        }
        if (!$this->ipAllowed($request->ip())) {
            return CallbackResult::invalid('Webhook IP not in allow-list', $data);
        }
        if (($data['type'] ?? 'invoice') !== 'invoice') {
            return CallbackResult::invalid('Unsupported webhook type: ' . ($data['type'] ?? ''), $data);
        }
        return new CallbackResult(
            true,
            isset($data['track_id']) ? (string) $data['track_id'] : null,
            self::mapStatus((string) ($data['status'] ?? '')),
            isset($data['amount']) ? (string) $data['amount'] : null,
            isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            (string) ($data['status'] ?? ''),
            isset($data['order_id']) ? (string) $data['order_id'] : null,
            raw: $data,
        );
    }

    public function verifyPayment(array $payment): CallbackResult
    {
        if (empty($payment['gateway_ref'])) {
            return CallbackResult::invalid('No track_id stored');
        }
        $resp = HttpClient::get(self::BASE_URL . '/payment/' . rawurlencode((string) $payment['gateway_ref']), [
            'headers' => ['merchant_api_key' => $this->cred('merchant_api_key')],
            'timeout' => 20,
        ]);
        $json = $resp->json() ?? [];
        $data = $json['data'] ?? null;
        if (!$resp->ok() || !is_array($data)) {
            return CallbackResult::invalid('OxaPay status query failed: HTTP ' . $resp->status . ' ' . ($resp->error ?? ''));
        }
        return new CallbackResult(
            true,
            (string) ($data['track_id'] ?? $payment['gateway_ref']),
            self::mapStatus((string) ($data['status'] ?? '')),
            isset($data['amount']) ? (string) $data['amount'] : null,
            isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            (string) ($data['status'] ?? ''),
            isset($data['order_id']) ? (string) $data['order_id'] : null,
            raw: $data,
        );
    }

    /** Only "Paid" credits. Underpaid/paying/waiting stay pending; expired/failed are terminal. */
    public static function mapStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            'paid', 'complete', 'completed' => 'completed',
            'expired' => 'expired',
            'failed', 'rejected', 'refunded', 'refunding' => 'failed',
            'canceled', 'cancelled' => 'cancelled',
            default => 'pending', // new, waiting, paying, confirming, underpaid, manual_accept...
        };
    }

    private function ipAllowed(string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) $this->opt('allowed_ips', ''))));
        return !$list || in_array($ip, $list, true);
    }

    /** Amount comparison helper used by PaymentService (2-decimal tolerance on fiat). */
    public static function amountMatches(string $expected, ?string $got): bool
    {
        return $got !== null && Money::isNumeric($got) && Money::cmp(Money::round($got, 2), Money::round($expected, 2)) >= 0;
    }
}
