<?php

declare(strict_types=1);

namespace App\Payment\Gateways;

use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Request;
use App\Payment\AbstractGateway;
use App\Payment\CallbackResult;
use App\Payment\PaymentException;
use App\Payment\PaymentInit;

/**
 * Cryptomus Merchant API (v1).
 *
 * Verified against Cryptomus' official PHP SDK (github.com/CryptomusCom/api-php-sdk)
 * and the official docs (doc.cryptomus.com):
 *   base URL        https://api.cryptomus.com/
 *   headers         merchant: <merchant uuid>
 *                   sign: md5(base64_encode(<json body>) . <PAYMENT API KEY>)
 *   create invoice  POST v1/payment       → result.uuid, result.url, result.expired_at
 *   payment info    POST v1/payment/info  {uuid} → result.payment_status / status
 *   webhook         JSON body with "sign"; verify by removing sign and computing
 *                   md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . API_KEY)
 *   success         status "paid" or "paid_over" (is_final = true)
 */
final class CryptomusGateway extends AbstractGateway
{
    private const BASE_URL = 'https://api.cryptomus.com/';

    public function key(): string
    {
        return 'cryptomus';
    }

    public function label(): string
    {
        return 'Cryptomus (Crypto)';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'merchant_uuid', 'label' => 'Merchant UUID', 'secret' => false, 'help' => 'Cryptomus → Business → Merchant → Settings.'],
            ['name' => 'payment_key', 'label' => 'Payment API Key', 'secret' => true, 'help' => 'The "Payment" API key (not the payout key). Used to sign requests and verify webhooks.'],
        ];
    }

    public function optionFields(): array
    {
        return [
            ['name' => 'lifetime', 'label' => 'Invoice lifetime (seconds)', 'type' => 'number', 'default' => '3600', 'help' => 'Cryptomus accepts 300–43200.'],
            ['name' => 'to_currency', 'label' => 'Convert to (optional)', 'type' => 'text', 'default' => '', 'help' => 'Crypto currency code for conversion, e.g. USDT. Empty lets the payer choose.'],
            ['name' => 'allowed_ips', 'label' => 'Webhook IP allow-list (optional)', 'type' => 'text', 'default' => '', 'help' => 'Comma-separated. Cryptomus documents the webhook source IP in its docs; empty = signature only.'],
        ];
    }

    public function createPayment(array $payment, array $user, string $returnUrl, string $callbackUrl): PaymentInit
    {
        $body = [
            'amount' => $this->chargeAmount($payment), // Cryptomus expects a string amount
            'currency' => strtoupper((string) $payment['currency']),
            'order_id' => 'PAY-' . $payment['id'],
            'url_return' => $returnUrl,
            'url_callback' => $callbackUrl,
            'lifetime' => max(300, min(43200, (int) $this->opt('lifetime', 3600))),
            'is_payment_multiple' => false,
        ];
        if (($to = trim((string) $this->opt('to_currency', ''))) !== '') {
            $body['to_currency'] = strtoupper($to);
        }
        $data = $this->request('v1/payment', $body);
        $result = $data['result'] ?? null;
        if (!is_array($result) || empty($result['uuid']) || empty($result['url'])) {
            throw new PaymentException('Cryptomus invoice failed: ' . mb_substr(json_encode($data), 0, 300));
        }
        $expires = isset($result['expired_at']) && is_numeric($result['expired_at']) ? gmdate('Y-m-d H:i:s', (int) $result['expired_at']) : null;
        return new PaymentInit((string) $result['url'], (string) $result['uuid'], $expires);
    }

    public function handleCallback(Request $request): CallbackResult
    {
        $data = json_decode($request->rawBody(), true);
        if (!is_array($data)) {
            return CallbackResult::invalid('Body is not JSON');
        }
        $sign = (string) ($data['sign'] ?? '');
        unset($data['sign']);
        $key = $this->cred('payment_key');
        if ($sign === '' || $key === '') {
            return CallbackResult::invalid('Missing sign or API key', $data);
        }
        $expected = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $key);
        if (!hash_equals($expected, $sign)) {
            return CallbackResult::invalid('Invalid signature', $data);
        }
        if (!$this->ipAllowed($request->ip())) {
            return CallbackResult::invalid('Webhook IP not in allow-list', $data);
        }
        if (($data['type'] ?? 'payment') !== 'payment') {
            return CallbackResult::invalid('Unsupported webhook type: ' . ($data['type'] ?? ''), $data);
        }
        return $this->toResult($data);
    }

    public function verifyPayment(array $payment): CallbackResult
    {
        if (empty($payment['gateway_ref'])) {
            return CallbackResult::invalid('No uuid stored');
        }
        try {
            $data = $this->request('v1/payment/info', ['uuid' => (string) $payment['gateway_ref']]);
        } catch (PaymentException $e) {
            return CallbackResult::invalid($e->getMessage());
        }
        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            return CallbackResult::invalid('Unexpected info response');
        }
        return $this->toResult($result);
    }

    private function toResult(array $d): CallbackResult
    {
        $status = (string) ($d['payment_status'] ?? $d['status'] ?? '');
        return new CallbackResult(
            true,
            isset($d['uuid']) ? (string) $d['uuid'] : null,
            self::mapStatus($status),
            isset($d['amount']) ? (string) $d['amount'] : null,
            isset($d['currency']) ? strtoupper((string) $d['currency']) : null,
            $status,
            isset($d['order_id']) ? (string) $d['order_id'] : null,
            raw: $d,
        );
    }

    /** paid / paid_over credit the wallet. wrong_amount (underpaid, final) does not — admin reviews. */
    public static function mapStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            'paid', 'paid_over' => 'completed',
            'fail', 'system_fail', 'wrong_amount', 'refund_paid', 'refund_process', 'refund_fail', 'locked' => 'failed',
            'cancel' => 'expired',
            default => 'pending', // process, check, confirm_check, wrong_amount_waiting
        };
    }

    private function request(string $uri, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $resp = HttpClient::post(self::BASE_URL . $uri, [
            'headers' => [
                'merchant' => $this->cred('merchant_uuid'),
                'sign' => md5(base64_encode($json) . $this->cred('payment_key')),
                'Content-Type' => 'application/json;charset=UTF-8',
            ],
            'json' => $json,
            'timeout' => 30,
        ]);
        $data = $resp->json();
        if ($resp->isNetworkError() || !is_array($data) || $resp->status !== 200 || (isset($data['state']) && (int) $data['state'] !== 0)) {
            Logger::error('Cryptomus request failed', ['uri' => $uri, 'http' => $resp->status, 'error' => $resp->error, 'body' => mb_substr($resp->body, 0, 1000)], 'payment');
            throw new PaymentException('Cryptomus ' . $uri . ' failed: HTTP ' . $resp->status . ' ' . ($data['message'] ?? $resp->error ?? ''));
        }
        return $data;
    }

    private function ipAllowed(string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) $this->opt('allowed_ips', ''))));
        return !$list || in_array($ip, $list, true);
    }
}
