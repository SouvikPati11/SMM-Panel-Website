<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Money;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiKeyService;
use App\Services\OrderService;

/**
 * Reseller API in the standard SMM panel v2 format:
 *   POST /api/v2  key=...&action=services|add|status|refill|refill_status|cancel|balance
 * Stateless (no session/CSRF), authenticated by API key, rate limited per key
 * and per IP, and every call is written to api_logs (key redacted).
 */
final class ApiV2Controller extends Controller
{
    private const STATUS_LABELS = [
        'pending' => 'Pending', 'processing' => 'Processing', 'in_progress' => 'In progress', 'completed' => 'Completed',
        'partial' => 'Partial', 'cancelled' => 'Canceled', 'refunded' => 'Canceled', 'failed' => 'Canceled',
    ];

    private float $start;
    private ?int $userId = null;
    private string $action = '';

    public function handle(Request $request): Response
    {
        $this->start = microtime(true);
        $this->action = mb_substr($request->str('action'), 0, 30);

        if (setting('api_enabled', '1') !== '1') {
            return $this->reply($request, ['error' => 'API is disabled'], 403);
        }
        // Throttle failed-auth attempts per IP to stop key guessing.
        if (RateLimiter::tooMany('apiauth:' . $request->ip(), 30, 300)) {
            return $this->reply($request, ['error' => 'Too many invalid requests. Try again later.'], 429);
        }
        $user = ApiKeyService::authenticate($request->str('key'), $request->ip());
        if (!$user) {
            RateLimiter::hit('apiauth:' . $request->ip(), 30, 300);
            return $this->reply($request, ['error' => 'Invalid API key'], 401);
        }
        $this->userId = (int) $user['id'];
        if (!RateLimiter::hit('api:' . $user['key_id'], max(1, (int) setting('api_rate_limit', 60)), max(1, (int) setting('api_rate_window', 60)))) {
            return $this->reply($request, ['error' => 'Rate limit exceeded'], 429)->withHeader('Retry-After', (string) setting('api_rate_window', 60));
        }

        try {
            [$data, $status] = match ($this->action) {
                'services' => [$this->services($user), 200],
                'add' => [$this->add($request, $user), 200],
                'status' => [$this->status($request, $user), 200],
                'refill' => [$this->refill($request, $user), 200],
                'refill_status' => [$this->refillStatus($request, $user), 200],
                'cancel' => [$this->cancel($request, $user), 200],
                'balance' => [$this->balance($user), 200],
                default => [['error' => 'Incorrect request (unknown action)'], 400],
            };
        } catch (ValidationException $e) {
            [$data, $status] = [['error' => $e->getMessage()], 422];
        } catch (\Throwable $e) {
            Logger::error('API error: ' . $e->getMessage(), ['action' => $this->action, 'user' => $this->userId, 'file' => $e->getFile(), 'line' => $e->getLine()], 'api');
            [$data, $status] = [['error' => 'Internal error, please try again'], 500];
        }
        return $this->reply($request, $data, $status);
    }

    private function services(array $user): array
    {
        $rows = Database::instance()->fetchAll(
            "SELECT s.*, c.name AS category FROM services s JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND s.is_hidden = 0 AND c.status = 'active' ORDER BY c.sort_order, s.sort_order, s.id"
        );
        $out = [];
        foreach ($rows as $s) {
            $out[] = [
                'service' => (int) $s['id'],
                'name' => $s['name'],
                'type' => OrderService::TYPES[$s['type']]['label'] ?? 'Default',
                'category' => $s['category'],
                'rate' => Money::formatRate(OrderService::userRate($s, $user)),
                'min' => (string) $s['min_quantity'],
                'max' => (string) $s['max_quantity'],
                'refill' => (int) $s['refill'] === 1,
                'cancel' => (int) $s['cancel'] === 1,
                'dripfeed' => (int) $s['dripfeed'] === 1,
            ];
        }
        return $out;
    }

    private function add(Request $request, array $user): array
    {
        $serviceId = $request->int('service');
        if ($serviceId <= 0) {
            throw new ValidationException('Incorrect service ID');
        }
        $input = [];
        foreach (['link', 'quantity', 'username', 'answer_number', 'keywords', 'runs', 'interval'] as $k) {
            $input[$k] = $request->str($k);
        }
        foreach (['comments', 'usernames'] as $k) {
            $v = $request->all()[$k] ?? '';
            $input[$k] = is_string($v) ? str_replace('\n', "\n", $v) : '';
        }
        if ($input['runs'] !== '' || $input['interval'] !== '') {
            $input['dripfeed'] = '1';
        }
        try {
            $order = OrderService::place((int) $user['id'], $serviceId, $input, 'api');
        } catch (ValidationException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'Insufficient balance')) {
                $msg = 'Not enough funds on balance';
            } elseif (str_contains($msg, 'service is not available')) {
                $msg = 'Incorrect service ID';
            }
            throw new ValidationException($msg);
        }
        return ['order' => (int) $order['id']];
    }

    private function orderStatus(array $o): array
    {
        return [
            'charge' => Money::formatRate(Money::sub((string) $o['charge'], (string) $o['refunded_amount'])),
            'start_count' => $o['start_count'] !== null ? (string) $o['start_count'] : '0',
            'status' => self::STATUS_LABELS[$o['status']] ?? 'Pending',
            'remains' => $o['remains'] !== null ? (string) $o['remains'] : (string) $o['quantity'],
            'currency' => (string) setting('currency_code', 'USD'),
        ];
    }

    /** @return list<int> */
    private function idList(string $csv): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)), static fn ($i) => $i > 0)));
        if (count($ids) > 100) {
            throw new ValidationException('Maximum 100 IDs per request');
        }
        return $ids;
    }

    private function status(Request $request, array $user): array
    {
        $db = Database::instance();
        if ($request->str('orders') !== '') {
            $ids = $this->idList($request->str('orders'));
            $found = [];
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                foreach ($db->fetchAll("SELECT * FROM orders WHERE user_id = ? AND id IN ({$ph})", array_merge([(int) $user['id']], $ids)) as $o) {
                    $found[(int) $o['id']] = $this->orderStatus($o);
                }
            }
            $out = [];
            foreach ($ids as $id) {
                $out[(string) $id] = $found[$id] ?? ['error' => 'Incorrect order ID'];
            }
            return $out;
        }
        $o = $db->fetch('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$request->int('order'), (int) $user['id']]);
        if (!$o) {
            throw new ValidationException('Incorrect order ID');
        }
        return $this->orderStatus($o);
    }

    private function refill(Request $request, array $user): array
    {
        if ($request->str('orders') !== '') {
            $out = [];
            foreach ($this->idList($request->str('orders')) as $id) {
                try {
                    $r = OrderService::requestRefill((int) $user['id'], $id);
                    $out[] = ['order' => $id, 'refill' => (int) $r['id']];
                } catch (ValidationException $e) {
                    $out[] = ['order' => $id, 'refill' => ['error' => $e->getMessage()]];
                }
            }
            return $out;
        }
        $r = OrderService::requestRefill((int) $user['id'], $request->int('order'));
        return ['refill' => (string) $r['id']];
    }

    private function refillStatus(Request $request, array $user): array
    {
        $db = Database::instance();
        $label = static fn (string $s) => ['pending' => 'Pending', 'processing' => 'In progress', 'completed' => 'Completed', 'rejected' => 'Rejected', 'failed' => 'Rejected'][$s] ?? 'Pending';
        if ($request->str('refills') !== '') {
            $out = [];
            foreach ($this->idList($request->str('refills')) as $id) {
                $r = $db->fetch('SELECT status FROM refills WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
                $out[] = ['refill' => $id, 'status' => $r ? $label($r['status']) : ['error' => 'Refill not found']];
            }
            return $out;
        }
        $r = $db->fetch('SELECT status FROM refills WHERE id = ? AND user_id = ?', [$request->int('refill'), (int) $user['id']]);
        if (!$r) {
            throw new ValidationException('Refill not found');
        }
        return ['status' => $label($r['status'])];
    }

    private function cancel(Request $request, array $user): array
    {
        $csv = $request->str('orders') !== '' ? $request->str('orders') : $request->str('order');
        $out = [];
        foreach ($this->idList($csv) as $id) {
            try {
                OrderService::requestCancel((int) $user['id'], $id);
                $out[] = ['order' => $id, 'cancel' => 1];
            } catch (ValidationException $e) {
                $out[] = ['order' => $id, 'cancel' => ['error' => $e->getMessage()]];
            }
        }
        if (!$out) {
            throw new ValidationException('Incorrect order ID');
        }
        return $out;
    }

    private function balance(array $user): array
    {
        $bal = (string) Database::instance()->fetchColumn('SELECT balance FROM wallets WHERE user_id = ?', [(int) $user['id']]);
        return ['balance' => Money::of($bal, 5), 'currency' => (string) setting('currency_code', 'USD')];
    }

    private function reply(Request $request, array $data, int $status): Response
    {
        $req = $request->all();
        if (isset($req['key'])) {
            $req['key'] = substr((string) $req['key'], 0, 6) . '…';
        }
        try {
            Database::instance()->insert('api_logs', [
                'user_id' => $this->userId,
                'action' => $this->action ?: null,
                'ip' => $request->ip(),
                'http_status' => $status,
                'request' => mb_substr(json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 4000),
                'response' => mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $this->action === 'services' ? 500 : 4000),
                'duration_ms' => (int) round((microtime(true) - $this->start) * 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('api log failed: ' . $e->getMessage(), [], 'api');
        }
        return Response::json($data, $status);
    }
}
