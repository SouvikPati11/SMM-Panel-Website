<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Database;
use App\Core\HttpClient;
use App\Core\HttpResponse;
use App\Core\Logger;
use App\Core\Money;

/**
 * Adapter for the de-facto standard "SMM panel API v2":
 *   single endpoint, form-encoded POST, parameters `key` and `action`
 *   (services | add | status | refill | refill_status | cancel | balance),
 *   JSON responses, errors as {"error": "..."}.
 *
 * Many panels deviate slightly (different parameter or action names, no
 * multi-status support, GET instead of POST). Those differences are handled
 * through the provider's JSON `config` (editable in Admin → Providers):
 *
 *   {
 *     "method": "POST",                   // or "GET"
 *     "key_param": "key",
 *     "action_param": "action",
 *     "actions": {"add":"add","status":"status","services":"services","balance":"balance",
 *                 "refill":"refill","refill_status":"refill_status","cancel":"cancel"},
 *     "params": {"service":"service","link":"link","quantity":"quantity","runs":"runs",
 *                "interval":"interval","comments":"comments","usernames":"usernames",
 *                "username":"username","answer_number":"answer_number","keywords":"keywords"},
 *     "multi_status": true,               // supports action=status&orders=1,2,3
 *     "multi_status_param": "orders",
 *     "multi_status_max": 100,
 *     "cancel_param": "orders",           // "orders" (multi) or "order"
 *     "extra": {}                          // static params appended to every request
 *   }
 *
 * No endpoints are invented: the adapter only calls the URL the admin enters.
 */
final class StandardApiV2Adapter implements ProviderAdapterInterface
{
    private array $cfg;

    public const DEFAULT_CONFIG = [
        'method' => 'POST',
        'key_param' => 'key',
        'action_param' => 'action',
        'actions' => [
            'add' => 'add', 'status' => 'status', 'services' => 'services', 'balance' => 'balance',
            'refill' => 'refill', 'refill_status' => 'refill_status', 'cancel' => 'cancel',
        ],
        'params' => [
            'service' => 'service', 'link' => 'link', 'quantity' => 'quantity', 'runs' => 'runs',
            'interval' => 'interval', 'comments' => 'comments', 'usernames' => 'usernames',
            'username' => 'username', 'answer_number' => 'answer_number', 'keywords' => 'keywords',
        ],
        'multi_status' => true,
        'multi_status_param' => 'orders',
        'multi_status_max' => 100,
        'cancel_param' => 'orders',
        'extra' => [],
    ];

    public function __construct(
        private int $providerId,
        private string $apiUrl,
        private string $apiKey,
        array $config = [],
        private int $timeout = 30,
    ) {
        $this->cfg = array_replace_recursive(self::DEFAULT_CONFIG, $config);
    }

    public function addOrder(array $params): string
    {
        $map = $this->cfg['params'];
        $payload = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $payload[$map[$k] ?? $k] = $v;
        }
        // "add" is not idempotent on provider side: a timeout here must NOT be retried.
        $data = $this->call('add', $payload, retrySafe: false);
        $id = $data['order'] ?? $data['order_id'] ?? $data['id'] ?? null;
        if ($id === null || !is_scalar($id) || (string) $id === '' || (string) $id === '0') {
            throw new ProviderException('Provider response did not include an order id', ProviderException::KIND_UNKNOWN);
        }
        return (string) $id;
    }

    public function status(string $providerOrderId): ProviderOrderStatus
    {
        $data = $this->call('status', ['order' => $providerOrderId]);
        return $this->parseStatus($data);
    }

    public function multiStatus(array $providerOrderIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $providerOrderIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        if (!$this->cfg['multi_status']) {
            foreach ($ids as $id) {
                try {
                    $out[$id] = $this->status($id);
                } catch (ProviderException $e) {
                    $out[$id] = $e;
                }
            }
            return $out;
        }
        foreach (array_chunk($ids, max(1, (int) $this->cfg['multi_status_max'])) as $chunk) {
            $data = $this->call('status', [$this->cfg['multi_status_param'] => implode(',', $chunk)]);
            foreach ($chunk as $id) {
                $row = $data[$id] ?? null;
                if (!is_array($row)) {
                    $out[$id] = new ProviderException('Order missing from provider response', ProviderException::KIND_UNKNOWN);
                } elseif (isset($row['error'])) {
                    $out[$id] = new ProviderException((string) $row['error'], ProviderException::KIND_REJECTED);
                } else {
                    try {
                        $out[$id] = $this->parseStatus($row);
                    } catch (ProviderException $e) {
                        $out[$id] = $e;
                    }
                }
            }
        }
        return $out;
    }

    public function cancel(array $providerOrderIds): array
    {
        $ids = array_map('strval', $providerOrderIds);
        $param = $this->cfg['cancel_param'];
        $data = $this->call('cancel', [$param => implode(',', $ids)], retrySafe: false);
        $out = [];
        // Standard response: [{"order":9,"cancel":{"error":"..."}}, {"order":2,"cancel":1}]
        if (array_is_list($data)) {
            foreach ($data as $row) {
                $oid = (string) ($row['order'] ?? '');
                $c = $row['cancel'] ?? null;
                $out[$oid] = (is_array($c) && isset($c['error'])) ? (string) $c['error'] : ($c ? true : 'Cancel not accepted');
            }
        } elseif (count($ids) === 1) {
            // Some panels answer single cancels with {"cancel": 1} or {"success": ...}
            $ok = !empty($data['cancel']) || !empty($data['success']) || !empty($data['status']);
            $out[$ids[0]] = $ok ? true : ((string) ($data['error'] ?? 'Cancel not accepted'));
        }
        foreach ($ids as $id) {
            $out[$id] ??= 'No response for this order';
        }
        return $out;
    }

    public function refill(string $providerOrderId): string
    {
        $data = $this->call('refill', ['order' => $providerOrderId], retrySafe: false);
        $id = $data['refill'] ?? $data['refill_id'] ?? $data['id'] ?? null;
        if ($id === null || !is_scalar($id) || (string) $id === '') {
            throw new ProviderException('Provider response did not include a refill id', ProviderException::KIND_UNKNOWN);
        }
        return (string) $id;
    }

    public function refillStatus(string $refillId): string
    {
        $data = $this->call('refill_status', ['refill' => $refillId]);
        $s = strtolower(preg_replace('/[\s_\-]+/', '', (string) ($data['status'] ?? '')));
        return match ($s) {
            'completed', 'complete', 'success', 'done' => 'completed',
            'rejected', 'canceled', 'cancelled', 'error', 'fail', 'failed' => 'rejected',
            'inprogress', 'processing' => 'processing',
            default => 'pending',
        };
    }

    public function services(): array
    {
        $data = $this->call('services', [], timeout: max($this->timeout, 60));
        if (!array_is_list($data)) {
            throw new ProviderException('Unexpected services response format', ProviderException::KIND_UNKNOWN);
        }
        $out = [];
        foreach ($data as $s) {
            if (!is_array($s) || !isset($s['service'], $s['name'], $s['rate'])) {
                continue;
            }
            $rate = str_replace(',', '', (string) $s['rate']);
            if (!Money::isNumeric($rate)) {
                continue;
            }
            $out[] = [
                'service' => (string) $s['service'],
                'name' => mb_substr((string) $s['name'], 0, 255),
                'category' => mb_substr((string) ($s['category'] ?? 'Uncategorized'), 0, 255),
                'type' => mb_substr((string) ($s['type'] ?? 'Default'), 0, 60),
                'rate' => Money::of($rate),
                'min' => max(1, (int) ($s['min'] ?? 1)),
                'max' => max(1, (int) ($s['max'] ?? 1)),
                'refill' => filter_var($s['refill'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'cancel' => filter_var($s['cancel'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'dripfeed' => filter_var($s['dripfeed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'description' => (string) ($s['description'] ?? $s['desc'] ?? ''),
            ];
        }
        return $out;
    }

    public function balance(): array
    {
        $data = $this->call('balance', []);
        $bal = str_replace(',', '', (string) ($data['balance'] ?? ''));
        if (!Money::isNumeric($bal)) {
            throw new ProviderException('Unexpected balance response', ProviderException::KIND_UNKNOWN);
        }
        return ['balance' => Money::of($bal), 'currency' => strtoupper((string) ($data['currency'] ?? 'USD'))];
    }

    // ------------------------------------------------------------------

    private function parseStatus(array $data): ProviderOrderStatus
    {
        if (isset($data['error'])) {
            throw new ProviderException((string) $data['error'], ProviderException::KIND_REJECTED);
        }
        $status = ProviderOrderStatus::normalize((string) ($data['status'] ?? ''));
        if ($status === null) {
            throw new ProviderException('Unrecognised order status: ' . substr((string) ($data['status'] ?? ''), 0, 40), ProviderException::KIND_UNKNOWN);
        }
        $int = static fn ($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : max(0, (int) $v);
        $charge = isset($data['charge']) && Money::isNumeric((string) $data['charge']) ? Money::of((string) $data['charge']) : null;
        return new ProviderOrderStatus($status, $int($data['start_count'] ?? null), $int($data['remains'] ?? null), $charge, isset($data['currency']) ? (string) $data['currency'] : null, json_encode($data));
    }

    /**
     * Perform one API call. Idempotent reads may be retried once on a network
     * failure that happened before sending; state-changing calls never retry.
     */
    private function call(string $action, array $params, bool $retrySafe = true, ?int $timeout = null): array
    {
        $payload = array_merge($this->cfg['extra'] ?: [], $params, [
            $this->cfg['key_param'] => $this->apiKey,
            $this->cfg['action_param'] => $this->cfg['actions'][$action] ?? $action,
        ]);
        $attempts = $retrySafe ? 2 : 1;
        $resp = null;
        for ($i = 1; $i <= $attempts; $i++) {
            $opts = ['timeout' => $timeout ?? $this->timeout, 'connect_timeout' => 10];
            if (strtoupper($this->cfg['method']) === 'GET') {
                $opts['query'] = $payload;
                $resp = HttpClient::get($this->apiUrl, $opts);
            } else {
                $opts['form'] = $payload;
                $resp = HttpClient::post($this->apiUrl, $opts);
            }
            if (!$resp->isNetworkError() || !$resp->notSent) {
                break;
            }
        }
        return $this->interpret($action, $params, $resp);
    }

    private function interpret(string $action, array $params, HttpResponse $resp): array
    {
        $error = null;
        $kind = null;
        $data = null;

        if ($resp->isNetworkError()) {
            $error = 'Network error: ' . $resp->error;
            $kind = $resp->notSent ? ProviderException::KIND_UNREACHABLE : ProviderException::KIND_UNKNOWN;
        } else {
            $data = $resp->json();
            if ($resp->status === 429) {
                $error = 'Provider rate limit reached (HTTP 429)';
                $kind = ProviderException::KIND_UNREACHABLE; // 429 means the request was refused, not executed
            } elseif ($data === null) {
                $error = 'Invalid (non-JSON) response, HTTP ' . $resp->status;
                // 5xx/garbage after sending: we cannot know whether an "add" executed
                $kind = ProviderException::KIND_UNKNOWN;
            } elseif (!array_is_list($data) && isset($data['error'])) {
                $error = 'Provider error: ' . mb_substr(is_scalar($data['error']) ? (string) $data['error'] : json_encode($data['error']), 0, 300);
                $kind = ProviderException::KIND_REJECTED;
            } elseif ($resp->status >= 400) {
                $error = 'HTTP ' . $resp->status;
                $kind = $resp->status >= 500 ? ProviderException::KIND_UNKNOWN : ProviderException::KIND_REJECTED;
            }
        }

        $this->log($action, $params, $resp, $error === null, $error);

        if ($error !== null) {
            throw new ProviderException($error, $kind, $resp->status ?: null);
        }
        return $data;
    }

    private function log(string $action, array $params, HttpResponse $resp, bool $ok, ?string $error): void
    {
        try {
            Database::instance()->insert('provider_logs', [
                'provider_id' => $this->providerId ?: null,
                'action' => $action,
                'http_status' => $resp->status ?: null,
                'success' => $ok ? 1 : 0,
                'request' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'response' => mb_substr($resp->body, 0, $action === 'services' ? 2000 : 20000),
                'error' => $error ? mb_substr($error, 0, 500) : null,
                'duration_ms' => $resp->durationMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('provider log failed: ' . $e->getMessage());
        }
        if (!$ok) {
            Logger::warning("Provider #{$this->providerId} {$action} failed: {$error}", [], 'provider');
        }
    }
}
