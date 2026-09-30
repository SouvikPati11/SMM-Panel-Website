<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Services\CouponService;
use App\Services\ManualPaymentService;
use App\Services\PaymentService;
use App\Services\ReferralService;

const OXA_KEY = 'OXA-TEST-MERCHANT-KEY';
const CM_KEY = 'cryptomus-payment-key-test';
const CM_MERCHANT = '8b03432e-385b-4670-8d06-064591096795';

$oxaMethod = Fx::gatewayMethod('oxapay', ['merchant_api_key' => OXA_KEY], ['lifetime' => '60']);
$cmMethod = Fx::gatewayMethod('cryptomus', ['merchant_uuid' => CM_MERCHANT, 'payment_key' => CM_KEY]);

/** Fake OxaPay API; $status controls what GET /payment/{id} reports. */
$oxaFake = static function (string &$status, ?string $amount = null) {
    Fx::http(['https://api.oxapay.com/v1/' => static function ($m, $url, $o) use (&$status, &$amount) {
        if ($m === 'POST' && str_ends_with($url, '/payment/invoice')) {
            T::eq(OXA_KEY, $o['headers']['merchant_api_key']);
            return Fx::json(['data' => ['track_id' => 'TRK' . substr((string) $o['json']['order_id'], 4), 'payment_url' => 'https://pay.oxapay.com/x/' . $o['json']['order_id'], 'expired_at' => time() + 3600, 'date' => time()], 'message' => 'ok', 'status' => 200]);
        }
        if ($m === 'GET' && preg_match('#/payment/(TRK\d+)$#', $url, $mm)) {
            return Fx::json(['data' => ['track_id' => $mm[1], 'status' => $status, 'amount' => $amount ?? '25', 'currency' => 'USD', 'order_id' => 'PAY-' . substr($mm[1], 3)], 'status' => 200]);
        }
        return Fx::json(['message' => 'not found'], 404);
    }]);
};

$oxaWebhook = static function (array $payload, ?string $key = OXA_KEY): App\Core\Response {
    $raw = json_encode($payload);
    $server = ['REMOTE_ADDR' => '203.0.113.9', 'CONTENT_TYPE' => 'application/json'];
    if ($key !== null) {
        $server['HTTP_HMAC'] = hash_hmac('sha512', $raw, $key);
    }
    return PaymentService::handleWebhook('oxapay', Request::create('POST', '/webhooks/oxapay', [], $server, $raw));
};

T::test('OxaPay: invoice creation stores track_id and pay URL (never credits)', function () use ($oxaMethod, $oxaFake) {
    $u = Fx::user();
    $st = 'Waiting';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    T::eq('TRK' . $p['id'], $p['gateway_ref']);
    T::true(str_starts_with($p['pay_url'], 'https://pay.oxapay.com/'));
    T::eq('pending', $p['status']);
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('OxaPay: amount below minimum / above maximum rejected', function () use ($oxaMethod) {
    $u = Fx::user();
    T::throws(ValidationException::class, fn () => PaymentService::createGatewayPayment($u, $oxaMethod, '0.5', '', '1.2.3.4'), 'minimum');
    T::throws(ValidationException::class, fn () => PaymentService::createGatewayPayment($u, $oxaMethod, '999999', '', '1.2.3.4'), 'maximum');
    T::throws(ValidationException::class, fn () => PaymentService::createGatewayPayment($u, $oxaMethod, '-5', '', '1.2.3.4'));
});

T::test('OxaPay: valid "Paid" webhook credits once; duplicate callback ignored', function () use ($oxaMethod, $oxaFake, $oxaWebhook) {
    $u = Fx::user();
    $st = 'Paid';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $payload = ['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']];
    $r1 = $oxaWebhook($payload);
    $r2 = $oxaWebhook($payload);
    T::eq(200, $r1->status());
    T::eq('ok', $r1->body());
    T::eq(200, $r2->status());
    T::eq('25.000000', Fx::balance((int) $u['id']));
    T::eq('completed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE payment_id = ? AND type = 'deposit'", [$p['id']]));
    T::eq('Duplicate: already completed', Database::instance()->fetchColumn('SELECT result FROM webhook_logs ORDER BY id DESC LIMIT 1'));
});

T::test('OxaPay: invalid HMAC rejected with 401 and nothing credited', function () use ($oxaMethod, $oxaFake, $oxaWebhook) {
    $u = Fx::user();
    $st = 'Paid';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $payload = ['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']];
    T::eq(401, $oxaWebhook($payload, 'wrong-key')->status());
    T::eq(401, $oxaWebhook($payload, null)->status());
    T::eq('0.000000', Fx::balance((int) $u['id']));
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT signature_valid FROM webhook_logs ORDER BY id DESC LIMIT 1'));
});

T::test('OxaPay: forged "Paid" webhook is not trusted if API says unpaid', function () use ($oxaMethod, $oxaFake, $oxaWebhook) {
    $u = Fx::user();
    $st = 'Waiting';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $oxaWebhook(['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']]);
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('OxaPay: underpaid amount held for review, not credited', function () use ($oxaMethod, $oxaFake, $oxaWebhook) {
    $u = Fx::user();
    $st = 'Paid';
    $amt = '10';
    $oxaFake($st, $amt);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $oxaWebhook(['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 10, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']]);
    T::eq('0.000000', Fx::balance((int) $u['id']));
    T::eq('pending', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
});

T::test('OxaPay: failed / expired webhook marks payment, never credits', function () use ($oxaMethod, $oxaFake, $oxaWebhook) {
    $u = Fx::user();
    $st = 'Expired';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $oxaWebhook(['track_id' => $p['gateway_ref'], 'status' => 'Expired', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']]);
    T::eq('expired', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    // A late "Paid" for an expired invoice is still verified via API before crediting
    $st = 'Paid';
    $oxaWebhook(['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']]);
    T::eq('25.000000', Fx::balance((int) $u['id']));
});

T::test('OxaPay: missed webhook recovered by cron verification', function () use ($oxaMethod, $oxaFake) {
    $u = Fx::user();
    $st = 'Paid';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    Database::instance()->update('payments', ['created_at' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $p['id']]);
    PaymentService::verifyPending();
    T::eq('25.000000', Fx::balance((int) $u['id']));
});

// ------------------------------------------------------------------ Cryptomus

$cmFake = static function (string &$status) {
    Fx::http(['https://api.cryptomus.com/' => static function ($m, $url, $o) use (&$status) {
        $body = $o['json'];
        T::eq(md5(base64_encode($body) . CM_KEY), $o['headers']['sign'], 'request signature');
        T::eq(CM_MERCHANT, $o['headers']['merchant']);
        $d = json_decode($body, true);
        if (str_ends_with($url, 'v1/payment')) {
            return Fx::json(['state' => 0, 'result' => ['uuid' => 'uuid-' . $d['order_id'], 'url' => 'https://pay.cryptomus.com/pay/uuid-' . $d['order_id'], 'expired_at' => time() + 3600, 'status' => 'check']]);
        }
        if (str_ends_with($url, 'v1/payment/info')) {
            return Fx::json(['state' => 0, 'result' => ['uuid' => $d['uuid'], 'order_id' => substr($d['uuid'], 5), 'amount' => '40.00', 'currency' => 'USD', 'payment_status' => $status, 'status' => $status]]);
        }
        return Fx::json(['state' => 1, 'message' => 'bad'], 422);
    }]);
};
$cmWebhook = static function (array $data, string $key = CM_KEY): App\Core\Response {
    $data['sign'] = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $key);
    return PaymentService::handleWebhook('cryptomus', Request::create('POST', '/webhooks/cryptomus', [], ['REMOTE_ADDR' => '91.227.144.54'], json_encode($data, JSON_UNESCAPED_UNICODE)));
};

T::test('Cryptomus: signed "paid" webhook credits once', function () use ($cmMethod, $cmFake, $cmWebhook) {
    $u = Fx::user();
    $st = 'paid';
    $cmFake($st);
    $p = PaymentService::createGatewayPayment($u, $cmMethod, '40', '', '1.2.3.4');
    T::eq('uuid-PAY-' . $p['id'], $p['gateway_ref']);
    $wh = ['type' => 'payment', 'uuid' => $p['gateway_ref'], 'order_id' => 'PAY-' . $p['id'], 'amount' => '40.00', 'payment_amount' => '40.00', 'currency' => 'USD', 'status' => 'paid', 'is_final' => true, 'url' => 'https://x/y'];
    T::eq(200, $cmWebhook($wh)->status());
    T::eq(200, $cmWebhook($wh)->status());
    T::eq('40.000000', Fx::balance((int) $u['id']));
});

T::test('Cryptomus: invalid signature rejected', function () use ($cmMethod, $cmFake, $cmWebhook) {
    $u = Fx::user();
    $st = 'paid';
    $cmFake($st);
    $p = PaymentService::createGatewayPayment($u, $cmMethod, '40', '', '1.2.3.4');
    $wh = ['type' => 'payment', 'uuid' => $p['gateway_ref'], 'order_id' => 'PAY-' . $p['id'], 'amount' => '40.00', 'currency' => 'USD', 'status' => 'paid', 'is_final' => true];
    T::eq(401, $cmWebhook($wh, 'attacker-key')->status());
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('Cryptomus: failed / cancelled statuses never credit', function () use ($cmMethod, $cmFake, $cmWebhook) {
    $u = Fx::user();
    $st = 'fail';
    $cmFake($st);
    $p = PaymentService::createGatewayPayment($u, $cmMethod, '40', '', '1.2.3.4');
    $cmWebhook(['type' => 'payment', 'uuid' => $p['gateway_ref'], 'order_id' => 'PAY-' . $p['id'], 'amount' => '40.00', 'currency' => 'USD', 'status' => 'fail', 'is_final' => true]);
    T::eq('failed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    $p2 = PaymentService::createGatewayPayment($u, $cmMethod, '40', '', '1.2.3.4');
    $st = 'cancel';
    $cmWebhook(['type' => 'payment', 'uuid' => $p2['gateway_ref'], 'order_id' => 'PAY-' . $p2['id'], 'amount' => '40.00', 'currency' => 'USD', 'status' => 'cancel', 'is_final' => true]);
    T::eq('expired', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p2['id']]));
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

// ------------------------------------------------------------------ Manual payments

$manualId = (int) Database::instance()->fetchColumn("SELECT id FROM payment_methods WHERE gateway = 'manual'");
Database::instance()->update('payment_methods', ['status' => 'active', 'require_proof' => 0], ['id' => $manualId]);

T::test('Manual: approve credits once; second approval refused', function () use ($manualId) {
    $u = Fx::user();
    $rid = ManualPaymentService::submit($u, $manualId, '15', 'UTR123456789', null, 'paid from GPay');
    T::eq('0.000000', Fx::balance((int) $u['id']));
    ManualPaymentService::approve($rid, 1, null, 'ok');
    T::eq('15.000000', Fx::balance((int) $u['id']));
    T::throws(ValidationException::class, fn () => ManualPaymentService::approve($rid, 1, null, 'again'), 'already');
    T::eq('15.000000', Fx::balance((int) $u['id']));
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'deposit'", [$u['id']]));
});

T::test('Manual: reject never credits; duplicate reference refused', function () use ($manualId) {
    $u = Fx::user();
    $rid = ManualPaymentService::submit($u, $manualId, '15', 'UTR-REJ-001', null, '');
    T::throws(ValidationException::class, fn () => ManualPaymentService::submit($u, $manualId, '15', 'UTR-REJ-001', null, ''), 'already been submitted');
    T::throws(ValidationException::class, fn () => ManualPaymentService::reject($rid, 1, ''), 'reason');
    ManualPaymentService::reject($rid, 1, 'Reference not found in bank statement');
    T::throws(ValidationException::class, fn () => ManualPaymentService::approve($rid, 1, null, ''));
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('Manual: approve with corrected amount', function () use ($manualId) {
    $u = Fx::user();
    $rid = ManualPaymentService::submit($u, $manualId, '50', 'UTR-AMT-77', null, '');
    ManualPaymentService::approve($rid, 1, '45.50', 'user sent less');
    T::eq('45.500000', Fx::balance((int) $u['id']));
});

// ------------------------------------------------------------------ Coupons & referrals

T::test('Coupon: percent bonus capped, per-user limit enforced', function () use ($manualId) {
    $db = Database::instance();
    $db->insert('coupons', ['code' => 'WELCOME10', 'type' => 'percent', 'value' => '10', 'min_deposit' => '20', 'max_discount' => '3', 'usage_limit' => 100, 'per_user_limit' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $u = Fx::user();
    T::throws(ValidationException::class, fn () => CouponService::validate('WELCOME10', (int) $u['id'], '10'), 'minimum');
    T::eq('2.5000', CouponService::validate('welcome10', (int) $u['id'], '25')['bonus']);
    $rid = ManualPaymentService::submit($u, $manualId, '50', 'UTR-CPN-1', null, '', 'WELCOME10');
    ManualPaymentService::approve($rid, 1, null, '');
    T::eq('53.000000', Fx::balance((int) $u['id'])); // 10% of 50 = 5, capped at 3
    T::throws(ValidationException::class, fn () => CouponService::validate('WELCOME10', (int) $u['id'], '50'), 'already used');
});

T::test('Coupon: expired and global usage limit', function () {
    $db = Database::instance();
    $db->insert('coupons', ['code' => 'OLD', 'type' => 'fixed', 'value' => '5', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 60), 'per_user_limit' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $db->insert('coupons', ['code' => 'ONE', 'type' => 'fixed', 'value' => '5', 'usage_limit' => 1, 'used_count' => 1, 'per_user_limit' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $u = Fx::user();
    T::throws(ValidationException::class, fn () => CouponService::validate('OLD', (int) $u['id'], '50'), 'expired');
    T::throws(ValidationException::class, fn () => CouponService::validate('ONE', (int) $u['id'], '50'), 'limit');
});

T::test('Referral: commission on deposit, not on bonus; transfer to balance', function () use ($manualId) {
    App\Services\SettingsService::set('referral_percent', '10');
    App\Services\SettingsService::set('referral_min_withdrawal', '1');
    $ref = Fx::user('0', ['register_ip' => '10.0.0.1']);
    $new = Fx::user();
    ReferralService::attach((int) $new['id'], $ref['referral_code'], '10.0.0.2');
    $rid = ManualPaymentService::submit($new, $manualId, '30', 'UTR-REF-1', null, '');
    ManualPaymentService::approve($rid, 1, null, '');
    $stats = ReferralService::stats((int) $ref['id']);
    T::eq(1, $stats['referrals']);
    T::eq('3.000000', $stats['available']);
    T::eq('0.000000', Fx::balance((int) $ref['id']));
    ReferralService::withdraw((int) $ref['id']);
    T::eq('3.000000', Fx::balance((int) $ref['id']));
    T::eq('0.000000', ReferralService::stats((int) $ref['id'])['available']);
    T::throws(ValidationException::class, fn () => ReferralService::withdraw((int) $ref['id']));
});

T::test('Referral: self-referral / same-IP referral blocked', function () use ($manualId) {
    $ref = Fx::user('0', ['register_ip' => '10.9.9.9']);
    ReferralService::attach((int) $ref['id'], $ref['referral_code'], '1.1.1.1'); // self
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM referrals WHERE referred_id = ?', [$ref['id']]));
    $alt = Fx::user();
    ReferralService::attach((int) $alt['id'], $ref['referral_code'], '10.9.9.9'); // same IP
    T::eq('blocked', Database::instance()->fetchColumn('SELECT status FROM referrals WHERE referred_id = ?', [$alt['id']]));
    $rid = ManualPaymentService::submit($alt, $manualId, '30', 'UTR-REF-2', null, '');
    ManualPaymentService::approve($rid, 1, null, '');
    T::eq('0.000000', ReferralService::stats((int) $ref['id'])['available']);
});

// ------------------------------------------------------------------ Gateway deposit bonus

$setBonus = static function (int $methodId, ?string $pct, ?string $fixed = '0', ?string $min = null): void {
    Database::instance()->update('payment_methods', ['bonus_percent' => $pct ?? '0', 'bonus_fixed' => $fixed ?? '0', 'bonus_min_amount' => $min ?? '0'], ['id' => $methodId]);
};
$bonusRows = static fn (int $paymentId) => Database::instance()->fetchAll("SELECT amount, reference FROM transactions WHERE payment_id = ? AND type = 'bonus' ORDER BY id", [$paymentId]);

T::test('Gateway bonus: calculation (percent, fixed, minimum) is exact and server-side', function () {
    T::eq('1.2500', PaymentService::gatewayBonus('25.00', '5', '0', null));
    T::eq('3.2500', PaymentService::gatewayBonus('25.00', '5', '2', null));
    T::eq('2.0000', PaymentService::gatewayBonus('25.00', '0', '2', null));
    T::eq('0.0000', PaymentService::gatewayBonus('9.99', '5', '2', '10'), 'below the minimum');
    T::eq('0.5000', PaymentService::gatewayBonus('10.00', '5', '0', '10'), 'exactly the minimum qualifies');
    T::eq('0.0000', PaymentService::gatewayBonus('25.00', null, null, null));
    T::eq('0.0000', PaymentService::gatewayBonus('25.00', '-5', '-1', null), 'negative terms never debit');
    T::eq('0.3333', PaymentService::gatewayBonus('3.33', '10.01', '0', null), '3.33 × 10.01% = 0.333333 → 0.3333');
});

T::test('Gateway bonus (OxaPay): credited once on the verified webhook, as its own ledger entry; duplicate callbacks never credit twice', function () use ($oxaMethod, $oxaFake, $oxaWebhook, $setBonus, $bonusRows) {
    $setBonus($oxaMethod, '10', '1.50', '20');
    $u = Fx::user();
    $st = 'Paid';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    T::eq(['10.00', '1.5000', '20.0000'], [$p['gw_bonus_percent'], $p['gw_bonus_fixed'], $p['gw_bonus_min']], 'terms snapshotted');
    // Admin changes the terms after the invoice was created: the payment keeps its own terms.
    $setBonus($oxaMethod, '50', '0', null);
    $payload = ['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']];
    $oxaWebhook($payload);
    $oxaWebhook($payload);
    PaymentService::complete((int) $p['id'], 'cron');
    T::eq('29.000000', Fx::balance((int) $u['id']), '25 + 2.50 + 1.50');
    T::eq([['amount' => '4.000000', 'reference' => 'payment:' . $p['id'] . ':gwbonus']], $bonusRows((int) $p['id']));
    T::eq('4.0000', Database::instance()->fetchColumn('SELECT gw_bonus_amount FROM payments WHERE id = ?', [$p['id']]));
    // Below the minimum of the terms in force: no bonus.
    $setBonus($oxaMethod, '10', '1.50', '20');
    $u2 = Fx::user();
    $p2 = PaymentService::createGatewayPayment($u2, $oxaMethod, '15', '', '1.2.3.4');
    $oxaFake($st, '15');
    $oxaWebhook(['track_id' => $p2['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 15, 'currency' => 'USD', 'order_id' => 'PAY-' . $p2['id']]);
    T::eq('15.000000', Fx::balance((int) $u2['id']));
    T::eq([], $bonusRows((int) $p2['id']));
    $setBonus($oxaMethod, '0', '0', null);
});

T::test('Gateway bonus (OxaPay): unpaid, underpaid, forged or failed payments never receive a bonus', function () use ($oxaMethod, $oxaFake, $oxaWebhook, $setBonus, $bonusRows) {
    $setBonus($oxaMethod, '10', '5', null);
    $u = Fx::user();
    $st = 'Waiting';
    $oxaFake($st);
    $p = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $oxaWebhook(['track_id' => $p['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 25, 'currency' => 'USD', 'order_id' => 'PAY-' . $p['id']]);
    $st = 'Paid';
    $oxaFake($st, '10');
    $p2 = PaymentService::createGatewayPayment($u, $oxaMethod, '25', '', '1.2.3.4');
    $oxaWebhook(['track_id' => $p2['gateway_ref'], 'status' => 'Paid', 'type' => 'invoice', 'amount' => 10, 'currency' => 'USD', 'order_id' => 'PAY-' . $p2['id']]);
    T::eq('0.000000', Fx::balance((int) $u['id']));
    T::eq([], array_merge($bonusRows((int) $p['id']), $bonusRows((int) $p2['id'])));
    $setBonus($oxaMethod, '0', '0', null);
});

T::test('Gateway bonus (Cryptomus): independent terms per gateway', function () use ($cmMethod, $oxaMethod, $cmFake, $cmWebhook, $setBonus, $bonusRows) {
    $setBonus($cmMethod, '2.5', '0', null);
    $setBonus($oxaMethod, '20', '10', null); // another gateway's terms never leak
    $u = Fx::user();
    $st = 'paid';
    $cmFake($st);
    $p = PaymentService::createGatewayPayment($u, $cmMethod, '40', '', '1.2.3.4');
    $wh = ['type' => 'payment', 'uuid' => $p['gateway_ref'], 'order_id' => 'PAY-' . $p['id'], 'amount' => '40.00', 'payment_amount' => '40.00', 'currency' => 'USD', 'status' => 'paid', 'is_final' => true, 'url' => 'https://x/y'];
    $cmWebhook($wh);
    $cmWebhook($wh);
    T::eq('41.000000', Fx::balance((int) $u['id']), '40 + 2.5%');
    T::eq(1, count($bonusRows((int) $p['id'])));
    $setBonus($cmMethod, '0', '0', null);
    $setBonus($oxaMethod, '0', '0', null);
});

T::test('Gateway bonus (manual): terms at submission apply on approval, based on the approved amount; approved once', function () use ($manualId, $setBonus, $bonusRows) {
    $setBonus($manualId, '5', '0', '10');
    $u = Fx::user();
    $rid = ManualPaymentService::submit($u, $manualId, '100', 'UTR-BONUS-1', null, '');
    $setBonus($manualId, '0', '0', null);
    ManualPaymentService::approve($rid, 1, '80', 'received 80');
    T::throws(ValidationException::class, fn () => ManualPaymentService::approve($rid, 1, null, ''), 'already');
    $pid = (int) Database::instance()->fetchColumn('SELECT payment_id FROM manual_payment_requests WHERE id = ?', [$rid]);
    T::eq('84.000000', Fx::balance((int) $u['id']), '80 approved + 5%');
    T::eq(1, count($bonusRows($pid)));
    // A rejected request never gets a bonus.
    $setBonus($manualId, '5', '0', null);
    $u2 = Fx::user();
    $rid2 = ManualPaymentService::submit($u2, $manualId, '50', 'UTR-BONUS-2', null, '');
    ManualPaymentService::reject($rid2, 1, 'Not received');
    T::eq('0.000000', Fx::balance((int) $u2['id']));
    $setBonus($manualId, '0', '0', null);
});

T::test('Gateway bonus (HTTP): shown before paying; tampered POST fields cannot change it; admin validates terms', function () use ($manualId, $oxaMethod, $setBonus, $bonusRows) {
    $setBonus($manualId, '7.5', '1', '20');
    $u = Fx::user();
    login_as_user($u);
    $html = http('GET', '/funds')->body();
    T::true(str_contains($html, 'data-bonus-pct="7.50"') && str_contains($html, 'data-bonus-fixed="1.0000"') && str_contains($html, 'data-bonus-min="20.0000"'));
    T::true(str_contains($html, '7.5% + ') && str_contains($html, 'bonus on deposits of'), 'bonus label on the method card');
    T::true(str_contains($html, 'class="bonus-preview"'));
    // Extra fields in the POST are ignored: the bonus comes only from the gateway's stored terms.
    http('POST', '/funds/manual', ['_token' => csrf(), 'method_id' => $manualId, 'amount' => '40', 'reference' => 'UTR-BONUS-HTTP', 'bonus_percent' => '100', 'gw_bonus_fixed' => '999', 'gw_bonus_percent' => '100']);
    $req = Database::instance()->fetch("SELECT * FROM manual_payment_requests WHERE reference = 'UTR-BONUS-HTTP'");
    T::eq(['7.50', '1.0000', '20.0000'], [$req['gw_bonus_percent'], $req['gw_bonus_fixed'], $req['gw_bonus_min']]);
    ManualPaymentService::approve((int) $req['id'], 1, null, '');
    T::eq('44.000000', Fx::balance((int) $u['id']), '40 + 3.00 + 1.00');
    App\Services\Auth::logoutUser();
    // Admin form: invalid terms refused, valid terms saved and audited.
    $db = Database::instance();
    $admin = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'bonusadmin', 'email' => 'bonusadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $m = $db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$oxaMethod]);
    $form = ['_token' => csrf(), 'id' => $oxaMethod, 'name' => $m['name'], 'min_amount' => '1', 'max_amount' => '10000', 'fee_percent' => '0', 'sort_order' => '0', 'status' => 'active'];
    http('POST', '/' . admin_path() . '/gateways/save', $form + ['bonus_percent' => '150', 'bonus_fixed' => '0']);
    T::eq('0.00', $db->fetchColumn('SELECT bonus_percent FROM payment_methods WHERE id = ?', [$oxaMethod]), '>100% refused');
    http('POST', '/' . admin_path() . '/gateways/save', $form + ['bonus_percent' => '-1', 'bonus_fixed' => '0']);
    T::eq('0.00', $db->fetchColumn('SELECT bonus_percent FROM payment_methods WHERE id = ?', [$oxaMethod]), 'negative refused');
    http('POST', '/' . admin_path() . '/gateways/save', $form + ['bonus_percent' => '3', 'bonus_fixed' => '0.5', 'bonus_min_amount' => '']);
    T::eq(['3.00', '0.5000', '0.0000'], array_values($db->fetch('SELECT bonus_percent, bonus_fixed, bonus_min_amount FROM payment_methods WHERE id = ?', [$oxaMethod])));
    T::true(str_contains(http('GET', '/' . admin_path() . '/gateways/' . $oxaMethod . '/edit')->body(), 'Deposit bonus'));
    T::true(str_contains(http('GET', '/' . admin_path() . '/gateways')->body(), '3% + '));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $setBonus($oxaMethod, '0', '0', null);
    $setBonus($manualId, '0', '0', null);
});
