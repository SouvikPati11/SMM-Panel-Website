<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\HttpResponse;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Payment\GatewayRegistry;
use App\Services\PaymentService;

const P2G_TOKEN = 'p2g-SECRET-token-9f8e7d6c5b4a';
const P2G_CREATE = 'https://p2gateway.in/api/create-order';
const P2G_STATUS = 'https://p2gateway.in/api/check-order-status';

// Test site currency is USD; the gateway is told its account currency matches.
$p2gMethod = Fx::gatewayMethod('p2gateway', ['user_token' => P2G_TOKEN], ['account_currency' => 'USD']);

/**
 * Fake P2Gateway. $s holds handlers + call log:
 *   $s['create'] = fn(array $form): HttpResponse, $s['status'] = fn(array $form): HttpResponse
 */
final class P2G
{
    public static array $s = [];

    public static function install(): void
    {
        self::$s = ['calls' => [], 'create' => null, 'status' => null];
        Fx::http([
            P2G_CREATE => static function ($m, $url, $o) {
                self::$s['calls'][] = ['create', $m, $o['form'] ?? null];
                return (self::$s['create'])($o['form'] ?? []);
            },
            P2G_STATUS => static function ($m, $url, $o) {
                self::$s['calls'][] = ['status', $m, $o['form'] ?? null];
                return (self::$s['status'])($o['form'] ?? []);
            },
        ]);
    }

    public static function createOk(): void
    {
        self::$s['create'] = static fn (array $f) => Fx::json(['status' => true, 'message' => 'Order Created Successfully', 'result' => ['orderId' => 'P2G' . crc32($f['order_id']), 'payment_url' => 'https://p2gateway.in/pay/' . $f['order_id']]]);
    }

    /** Status handler reporting $txn/$status and $amount for whatever order_id is asked. */
    public static function status(string $txn, ?string $status = 'SUCCESS', ?string $amount = null, ?string $orderId = null, ?string $utr = 'UTR123456789'): void
    {
        self::$s['status'] = static function (array $f) use ($txn, $status, $amount, $orderId, $utr) {
            $d = ['txnStatus' => $txn, 'resultInfo' => 'Transaction ' . $txn, 'orderId' => $orderId ?? $f['order_id'], 'amount' => $amount ?? self::$s['amount'] ?? '0', 'date' => '2026-10-01 10:00:00', 'utr' => $utr];
            if ($status !== null) {
                $d['status'] = $status;
            }
            return Fx::json($d);
        };
    }

    public static function count(string $type): int
    {
        return count(array_filter(self::$s['calls'], static fn ($c) => $c[0] === $type));
    }
}

$newPayment = static function (string $amount = '100', array $fields = ['customer_mobile' => '9876543210']) use ($p2gMethod): array {
    $u = Fx::user();
    P2G::$s['amount'] = $amount;
    $p = PaymentService::createGatewayPayment($u, $p2gMethod, $amount, '', '1.2.3.4', $fields);
    return [$u, $p];
};

$webhook = static fn (array $payload) => PaymentService::handleWebhook('p2gateway', Request::create('POST', '/webhooks/p2gateway', [], ['REMOTE_ADDR' => '203.0.113.77', 'CONTENT_TYPE' => 'application/json'], json_encode($payload)));

$credits = static fn (int $uid) => (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'deposit'", [$uid]);

T::test('P2G 1: create-order success stores local id → merchant order_id → P2Gateway orderId', function () use ($newPayment) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100', ['customer_mobile' => '+91 98765 43210']);
    $form = P2G::$s['calls'][0][2];
    T::eq(['customer_mobile', 'user_token', 'amount', 'order_id', 'redirect_url', 'remark1'], array_keys($form));
    T::eq('9876543210', $form['customer_mobile']);
    T::eq(P2G_TOKEN, $form['user_token']);
    T::eq('100', $form['amount']);
    T::eq($p['merchant_order_id'], $form['order_id']);
    T::true((bool) preg_match('/^SMM' . $p['id'] . 'T[0-9A-F]{8}$/', $p['merchant_order_id']), 'unique alphanumeric order id');
    T::eq('https://panel.test/funds/return/' . $p['id'], $form['redirect_url']);
    T::eq('P2G' . crc32($p['merchant_order_id']), $p['gateway_ref']);
    T::eq('https://p2gateway.in/pay/' . $p['merchant_order_id'], $p['pay_url']);
    T::eq('pending', $p['status']);
    $ttl = strtotime($p['expires_at'] . ' UTC') - time();
    T::true($ttl > 29 * 60 && $ttl <= 30 * 60, '30 minute order timeout');
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('P2G: order ids are never reused', function () use ($newPayment) {
    P2G::install();
    P2G::createOk();
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = $newPayment('10')[1]['merchant_order_id'];
    }
    T::eq(5, count(array_unique($ids)));
});

T::test('P2G: invalid mobile rejected before any API call', function () use ($newPayment) {
    P2G::install();
    P2G::createOk();
    T::throws(ValidationException::class, fn () => $newPayment('100', ['customer_mobile' => '12345']), 'mobile');
    T::throws(ValidationException::class, fn () => $newPayment('100', []), 'mobile');
    T::eq(0, P2G::count('create'));
});

T::test('P2G 2: failed create-order → clean error, payment failed, reference detached', function () use ($newPayment) {
    P2G::install();
    P2G::$s['create'] = fn () => Fx::json(['status' => false, 'message' => 'Invalid amount']);
    $e = null;
    try {
        $newPayment('100');
    } catch (ValidationException $ex) {
        $e = $ex;
    }
    T::true($e !== null && !str_contains($e->getMessage(), 'Invalid amount') && !str_contains($e->getMessage(), P2G_TOKEN), 'user sees a generic message');
    $p = Database::instance()->fetch("SELECT * FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1");
    T::eq('failed', $p['status']);
    T::eq(null, $p['merchant_order_id']);
    T::eq(1, P2G::count('create'));
});

T::test('P2G 3: duplicate order_id response → failed safely, no retry, no credit possible later', function () use ($newPayment, $webhook) {
    P2G::install();
    P2G::$s['create'] = fn () => Fx::json(['status' => false, 'message' => 'order_id already exists']);
    T::throws(ValidationException::class, fn () => $newPayment('100'), 'could not be started');
    $p = Database::instance()->fetch("SELECT * FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1");
    T::eq('failed', $p['status']);
    T::eq(1, P2G::count('create'));
    $attempted = json_decode($p['meta'], true)['attempted_order_id'];
    // Someone else's order with that id is later paid: it must never credit this payment.
    P2G::status('COMPLETED', 'SUCCESS', '100');
    $r = $webhook(['order_id' => $attempted, 'status' => 'SUCCESS']);
    T::eq(200, $r->status());
    T::eq(0, Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE payment_id = ?", [$p['id']]) + 0);
    T::eq(0, P2G::count('status'), 'unknown reference must not trigger lookups');
});

T::test('P2G 4: success without payment_url → reconciled via status API, kept pending, no second order', function () use ($newPayment) {
    P2G::install();
    P2G::$s['create'] = fn () => Fx::json(['status' => true, 'message' => 'Order Created Successfully', 'result' => ['orderId' => 'X1']]);
    P2G::status('PENDING', 'PENDING', '100');
    T::throws(ValidationException::class, fn () => $newPayment('100'), 'did not respond');
    $p = Database::instance()->fetch("SELECT * FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1");
    T::eq('pending', $p['status']);
    T::true(!empty(json_decode($p['meta'], true)['ambiguous_create']));
    T::eq(1, P2G::count('create'));
    T::eq(1, P2G::count('status'));
});

T::test('P2G 5: invalid JSON from create-order → status check says unknown order → failed', function () use ($newPayment) {
    P2G::install();
    P2G::$s['create'] = fn () => new HttpResponse(200, '<html>Oops</html>', 5);
    P2G::$s['status'] = fn () => Fx::json(['status' => false, 'message' => 'Order not found']);
    T::throws(ValidationException::class, fn () => $newPayment('100'));
    T::eq('failed', Database::instance()->fetchColumn("SELECT status FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1"));
    T::eq(1, P2G::count('create'));
});

T::test('P2G 6: timeout after sending → status check also times out → kept pending, never re-sent', function () use ($newPayment) {
    P2G::install();
    P2G::$s['create'] = fn () => new HttpResponse(0, '', 30000, 'Operation timed out', false);
    P2G::$s['status'] = fn () => new HttpResponse(0, '', 20000, 'Operation timed out', false);
    T::throws(ValidationException::class, fn () => $newPayment('100'));
    $p = Database::instance()->fetch("SELECT * FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1");
    T::eq('pending', $p['status']);
    T::true($p['merchant_order_id'] !== null, 'reference kept for later verification');
    T::eq(1, P2G::count('create'));
    // Later the gateway answers: order was created and paid → cron credits exactly once.
    P2G::status('COMPLETED', 'SUCCESS', '100');
    Database::instance()->update('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $p['id']]);
    PaymentService::verifyPending();
    PaymentService::verifyPending();
    T::eq('completed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE payment_id = ? AND type = 'deposit'", [$p['id']]));
});

T::test('P2G 7: HTTP 500 from create-order handled as ambiguous; connection refused as plain failure', function () use ($newPayment) {
    P2G::install();
    P2G::$s['create'] = fn () => new HttpResponse(500, 'Internal Server Error', 5);
    P2G::$s['status'] = fn () => Fx::json(['status' => false, 'message' => 'Order not found']);
    T::throws(ValidationException::class, fn () => $newPayment('100'));
    T::eq('failed', Database::instance()->fetchColumn("SELECT status FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1"));
    P2G::install();
    P2G::$s['create'] = fn () => new HttpResponse(0, '', 1, 'Connection refused', true);
    T::throws(ValidationException::class, fn () => $newPayment('100'));
    T::eq('failed', Database::instance()->fetchColumn("SELECT status FROM payments WHERE gateway = 'p2gateway' ORDER BY id DESC LIMIT 1"));
    T::eq(0, P2G::count('status'), 'no status check needed when the request never left');
});

T::test('P2G 8: webhook + successful status → ONE credit, ONE ledger row, UTR and verified amount stored', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('250.50');
    P2G::status('COMPLETED', 'SUCCESS', '250.50', null, 'UTR998877');
    $r = $webhook(['order_id' => $p['merchant_order_id']]);
    T::eq(200, $r->status());
    $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    T::eq('completed', $row['status']);
    T::eq('UTR998877', $row['utr']);
    T::eq('250.5000', $row['verified_amount']);
    T::eq('250.500000', Fx::balance((int) $u['id']));
    T::eq(1, $credits((int) $u['id']));
    $form = end(P2G::$s['calls'])[2];
    T::eq(['user_token' => P2G_TOKEN, 'order_id' => $p['merchant_order_id']], $form);
});

T::test('P2G 9: failed status → payment failed, no credit', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('FAILED', 'FAILED', '100');
    $webhook(['order_id' => $p['merchant_order_id']]);
    T::eq('failed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    T::eq(0, $credits((int) $u['id']));
    P2G::status('ERROR', null, '100');
    [$u2, $p2] = $newPayment('100');
    $webhook(['order_id' => $p2['merchant_order_id']]);
    T::eq('failed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p2['id']]));
});

T::test('P2G 10: pending status → stays pending, no credit', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('PENDING', 'PENDING', '100');
    $webhook(['order_id' => $p['merchant_order_id']]);
    T::eq('pending', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    T::eq(0, $credits((int) $u['id']));
});

T::test('P2G 11: amount mismatch (lower OR higher OR missing) → held for review, never credited', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    foreach (['99.99', '1000', null] as $paid) {
        [$u, $p] = $newPayment('100');
        P2G::$s['status'] = static fn (array $f) => Fx::json(array_filter(['txnStatus' => 'COMPLETED', 'status' => 'SUCCESS', 'orderId' => $f['order_id'], 'amount' => $paid, 'utr' => 'U1'], static fn ($v) => $v !== null));
        $webhook(['order_id' => $p['merchant_order_id']]);
        $webhook(['order_id' => $p['merchant_order_id']]);
        $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
        T::eq('pending', $row['status'], 'paid ' . var_export($paid, true));
        T::eq(1, (int) $row['needs_review']);
        T::eq(0, $credits((int) $u['id']));
    }
    // Cron skips payments under review
    $before = P2G::count('status');
    Database::instance()->query("UPDATE payments SET created_at = ? WHERE gateway = 'p2gateway' AND needs_review = 1", [gmdate('Y-m-d H:i:s', time() - 600)]);
    PaymentService::verifyPending();
    T::eq($before, P2G::count('status'));
});

T::test('P2G 11b: a payment held for review by a concurrent check is never credited from a stale snapshot', function () use ($newPayment, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    $stale = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    // Another process flags it after this one read the row.
    Database::instance()->query('UPDATE payments SET needs_review = 1 WHERE id = ?', [$p['id']]);
    P2G::status('COMPLETED', 'SUCCESS', '100');
    $gw = GatewayRegistry::make(Database::instance()->fetch('SELECT * FROM payment_methods WHERE id = ?', [$p['payment_method_id']]));
    PaymentService::settle($stale, $gw, $gw->verifyPayment($stale), 'webhook');
    T::eq('pending', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    T::eq(0, $credits((int) $u['id']));
});

T::test('P2G 12+13: duplicate and repeated webhooks (with false claims) → exactly one credit', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('COMPLETED', 'SUCCESS', '100');
    // Webhook claims are ignored — only the status API counts.
    $webhook(['order_id' => $p['merchant_order_id'], 'status' => 'FAILED', 'amount' => '1']);
    for ($i = 0; $i < 6; $i++) {
        $webhook(['order_id' => $p['merchant_order_id'], 'txnStatus' => 'COMPLETED', 'amount' => '999999']);
    }
    T::eq(1, $credits((int) $u['id']));
    T::eq('100.000000', Fx::balance((int) $u['id']));
    // A "paid" claim while the API says pending never credits.
    [$u2, $p2] = $newPayment('100');
    P2G::status('PENDING', 'PENDING', '100');
    $webhook(['order_id' => $p2['merchant_order_id'], 'txnStatus' => 'COMPLETED', 'status' => 'SUCCESS', 'amount' => '100']);
    T::eq(0, $credits((int) $u2['id']));
});

T::test('P2G 14: already-credited payment → acknowledged without another API call or credit', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('COMPLETED', 'SUCCESS', '100');
    $webhook(['order_id' => $p['merchant_order_id']]);
    $calls = P2G::count('status');
    $r = $webhook(['order_id' => $p['merchant_order_id']]);
    T::eq(200, $r->status());
    T::eq($calls, P2G::count('status'));
    T::eq('Duplicate: already completed', Database::instance()->fetchColumn('SELECT result FROM webhook_logs ORDER BY id DESC LIMIT 1'));
    T::eq(1, $credits((int) $u['id']));
    T::eq(null, PaymentService::verifyOne((int) $p['id'], 'test'), 'completed payments are never re-verified');
});

T::test('P2G 15: unknown / missing / foreign references are ignored safely', function () use ($newPayment, $webhook) {
    P2G::install();
    P2G::createOk();
    P2G::status('COMPLETED', 'SUCCESS', '100');
    T::eq(200, $webhook(['order_id' => 'SMM999999TDEADBEEF'])->status());
    T::eq(400, $webhook(['foo' => 'bar'])->status());
    T::eq(400, PaymentService::handleWebhook('p2gateway', Request::create('POST', '/webhooks/p2gateway', [], [], 'garbage{'))->status());
    // An OxaPay-style reference cannot reach a P2Gateway payment
    [$u, $p] = $newPayment('100');
    T::eq(200, $webhook(['order_id' => 'PAY-' . $p['id']])->status());
    T::eq(0, P2G::count('status'));
    // Status API answering for a different order id → review, no credit
    P2G::status('COMPLETED', 'SUCCESS', '100', 'SOMEONE-ELSE');
    $webhook(['order_id' => $p['merchant_order_id']]);
    T::eq(1, (int) Database::instance()->fetchColumn('SELECT needs_review FROM payments WHERE id = ?', [$p['id']]));
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('P2G 16+17: concurrent webhooks, cron and return page racing → one credit', function () use ($newPayment, $credits) {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped: pcntl not available)\n";
        return;
    }
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('COMPLETED', 'SUCCESS', '100');
    Database::instance()->update('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $p['id']]);
    $pids = [];
    for ($i = 0; $i < 6; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            Database::setInstance(null);
            try {
                match ($i % 3) {
                    0 => PaymentService::handleWebhook('p2gateway', Request::create('POST', '/webhooks/p2gateway', [], [], json_encode(['order_id' => $p['merchant_order_id']]))),
                    1 => PaymentService::verifyPending(),
                    2 => PaymentService::verifyOne((int) $p['id'], 'race'),
                };
            } catch (\Throwable) {
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $st);
    }
    Database::setInstance(null);
    T::eq(1, $credits((int) $u['id']));
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE payment_id = ?", [$p['id']]));
    T::eq('100.000000', Fx::balance((int) $u['id']));
});

T::test('P2G 18: status API unavailable → 503 (retry), no credit; recovered later by cron', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::$s['status'] = fn () => new HttpResponse(0, '', 20000, 'Operation timed out', false);
    T::eq(503, $webhook(['order_id' => $p['merchant_order_id']])->status());
    P2G::$s['status'] = fn () => new HttpResponse(502, 'Bad gateway', 5);
    T::eq(503, $webhook(['order_id' => $p['merchant_order_id']])->status());
    T::eq(0, $credits((int) $u['id']));
    P2G::status('COMPLETED', 'SUCCESS', '100');
    Database::instance()->update('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $p['id']]);
    PaymentService::verifyPending();
    T::eq(1, $credits((int) $u['id']));
});

T::test('P2G 19: unexpected status responses are never treated as paid', function () use ($newPayment, $webhook, $credits) {
    P2G::install();
    P2G::createOk();
    $cases = [
        fn (array $f) => Fx::json(['foo' => 'bar']),
        fn (array $f) => Fx::json(['status' => 'SUCCESS', 'orderId' => $f['order_id'], 'amount' => '100']), // no txnStatus
        fn (array $f) => Fx::json(['txnStatus' => 'COMPLETED', 'status' => 'PENDING', 'orderId' => $f['order_id'], 'amount' => '100']), // contradictory
        fn (array $f) => Fx::json(['txnStatus' => 'WEIRD', 'status' => 'SUCCESS', 'orderId' => $f['order_id'], 'amount' => '100']),
        fn (array $f) => Fx::json(['status' => true, 'message' => 'ok']),
        fn (array $f) => Fx::json([['txnStatus' => 'COMPLETED']]),
    ];
    foreach ($cases as $i => $case) {
        [$u, $p] = $newPayment('100');
        P2G::$s['status'] = $case;
        $webhook(['order_id' => $p['merchant_order_id']]);
        T::eq('pending', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]), "case {$i}");
        T::eq(0, $credits((int) $u['id']), "case {$i}");
    }
    T::eq('completed', App\Payment\Gateways\P2GatewayGateway::mapStatus('COMPLETED', 'SUCCESS'));
    T::eq('completed', App\Payment\Gateways\P2GatewayGateway::mapStatus('SUCCESS', null));
    T::eq('failed', App\Payment\Gateways\P2GatewayGateway::mapStatus('COMPLETED', 'FAILED'));
    T::eq('pending', App\Payment\Gateways\P2GatewayGateway::mapStatus(null, 'SUCCESS'));
});

T::test('P2G 20: refreshing the return page repeatedly credits once; the browser can\'t force a credit', function () use ($newPayment, $credits) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    login_as_user($u);
    P2G::status('PENDING', 'PENDING', '100');
    for ($i = 0; $i < 5; $i++) {
        T::eq(200, http('GET', '/funds/return/' . $p['id'], ['status' => 'SUCCESS', 'txnStatus' => 'COMPLETED', 'amount' => '100'])->status());
    }
    T::eq(1, P2G::count('status'), 'verification is throttled per payment');
    T::eq(0, $credits((int) $u['id']));
    P2G::status('COMPLETED', 'SUCCESS', '100');
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::clear('payverify:' . $p['id']);
        $html = http('GET', '/funds/return/' . $p['id'])->body();
    }
    T::true(str_contains($html, 'Payment confirmed') && str_contains($html, 'UTR123456789'));
    T::eq(1, $credits((int) $u['id']));
    T::eq('100.000000', Fx::balance((int) $u['id']));
    // Another user cannot view or trigger verification of this payment
    login_as_user(Fx::user());
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('GET', '/funds/return/' . $p['id']));
});

T::test('P2G: webhook route works over HTTP (POST JSON, POST form, GET query)', function () use ($newPayment, $credits) {
    P2G::install();
    P2G::createOk();
    P2G::status('COMPLETED', 'SUCCESS', '100');
    [$u1, $p1] = $newPayment('100');
    [$u2, $p2] = $newPayment('100');
    [$u3, $p3] = $newPayment('100');
    T::eq(200, http('POST', '/webhooks/p2gateway', [], ['CONTENT_TYPE' => 'application/json'], json_encode(['order_id' => $p1['merchant_order_id']]))->status());
    T::eq(200, http('POST', '/webhooks/p2gateway', [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query(['order_id' => $p2['merchant_order_id']]))->status());
    T::eq(200, http('GET', '/webhooks/p2gateway', ['orderId' => $p3['gateway_ref']])->status());
    T::eq(1, $credits((int) $u1['id']));
    T::eq(1, $credits((int) $u2['id']));
    T::eq(1, $credits((int) $u3['id']));
});

T::test('P2G: token never leaks to logs, webhook logs, HTML or error messages', function () use ($newPayment, $webhook, $p2gMethod) {
    P2G::install();
    P2G::createOk();
    [$u, $p] = $newPayment('100');
    P2G::status('PENDING');
    $webhook(['order_id' => $p['merchant_order_id'], 'user_token' => P2G_TOKEN]);
    $db = Database::instance();
    T::true(!str_contains((string) $db->fetchColumn('SELECT payload FROM webhook_logs ORDER BY id DESC LIMIT 1'), P2G_TOKEN), 'webhook log');
    foreach (glob(STORAGE_PATH . '/logs/*.log') ?: [] as $f) {
        T::true(!str_contains((string) file_get_contents($f), P2G_TOKEN), basename($f));
    }
    T::true(!str_contains((string) $db->fetchColumn('SELECT credentials_enc FROM payment_methods WHERE id = ?', [$p2gMethod]), P2G_TOKEN), 'encrypted at rest');
    T::true(!str_contains(json_encode($db->fetchAll('SELECT * FROM payments')), P2G_TOKEN), 'payments table');
    $adminId = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'p2gadmin', 'email' => 'p2gadmin@example.com', 'password_hash' => password_hash('AdminPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($adminId);
    $html = http('GET', '/' . admin_path() . '/gateways/' . $p2gMethod . '/edit')->body();
    T::true(!str_contains($html, P2G_TOKEN), 'admin form shows only a mask');
    T::true(str_contains($html, '/webhooks/p2gateway'), 'webhook URL displayed');
    login_as_user($u);
    T::true(!str_contains(http('GET', '/funds')->body(), P2G_TOKEN), 'funds page');
});

T::test('P2G: not offered unless configured and the site currency matches', function () use ($p2gMethod) {
    $db = Database::instance();
    $ids = fn () => array_column(PaymentService::availableMethods(), 'gateway');
    T::true(in_array('p2gateway', $ids(), true));
    $db->update('payment_methods', ['config' => json_encode(['account_currency' => 'INR'])], ['id' => $p2gMethod]);
    T::true(!in_array('p2gateway', $ids(), true), 'USD site, INR gateway');
    $db->update('payment_methods', ['config' => json_encode(['account_currency' => 'USD', 'status_url' => 'http://p2gateway.in/api/check-order-status'])], ['id' => $p2gMethod]);
    T::true(!in_array('p2gateway', $ids(), true), 'non-HTTPS endpoint = not configured');
    $db->update('payment_methods', ['config' => json_encode(['account_currency' => 'USD'])], ['id' => $p2gMethod]);
    T::true(GatewayRegistry::make($db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$p2gMethod]))->isImplemented());
});
