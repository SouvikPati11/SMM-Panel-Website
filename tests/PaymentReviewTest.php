<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Services\PaymentReviewService;
use App\Services\PaymentService;

const RV_TOKEN = 'review-test-token-0a1b2c';
const RV_STATUS = 'https://p2gateway.in/api/check-order-status';

$db = Database::instance();
$rvMethod = Fx::gatewayMethod('p2gateway', ['user_token' => RV_TOKEN], ['account_currency' => 'USD']);
$rvAdmin = $db->insert('admins', ['username' => 'reviewer', 'email' => 'reviewer@example.com', 'password_hash' => password_hash('ReviewPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$rvSupport = $db->insert('admins', ['username' => 'rvsupport', 'email' => 'rvsupport@example.com', 'password_hash' => password_hash('ReviewPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 0, 'created_at' => now(), 'updated_at' => now()]);
$db->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Support'", [$rvSupport]);

/** Gateway status API reporting a COMPLETED payment of $amount (counts calls). */
final class RV
{
    public static int $calls = 0;

    public static function gatewaySays(?string $amount, string $txn = 'COMPLETED'): void
    {
        self::$calls = 0;
        Fx::http([RV_STATUS => static function ($m, $url, $o) use ($amount, $txn) {
            self::$calls++;
            return Fx::json(array_filter(['txnStatus' => $txn, 'status' => 'SUCCESS', 'orderId' => $o['form']['order_id'] ?? '', 'amount' => $amount, 'utr' => 'UTR777'], static fn ($v) => $v !== null));
        }]);
    }
}

/** A P2Gateway payment of 100 that the gateway confirmed as $verified → held for review. */
$held = static function (string $verified = '90', string $status = 'pending') use ($rvMethod): array {
    $db = Database::instance(); // not the file-level handle: the fork test replaces the connection
    $u = Fx::user();
    $id = $db->insert('payments', [
        'user_id' => $u['id'], 'payment_method_id' => $rvMethod, 'gateway' => 'p2gateway', 'amount' => '100', 'fee' => '0',
        'currency' => 'USD', 'status' => $status, 'gateway_ref' => 'GW' . bin2hex(random_bytes(4)),
        'meta' => json_encode(['customer_mobile' => '9876543210']), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
        'created_at' => gmdate('Y-m-d H:i:s', time() - 600), 'updated_at' => now(),
    ]);
    $db->update('payments', ['merchant_order_id' => 'SMM' . $id . 'T' . strtoupper(bin2hex(random_bytes(4)))], ['id' => $id]);
    RV::gatewaySays($verified);
    $r = PaymentService::verifyOne($id, 'test');
    if ($status !== 'pending') { // verifyOne only handles pending payments: settle directly
        $p = $db->fetch('SELECT * FROM payments WHERE id = ?', [$id]);
        $gw = App\Payment\GatewayRegistry::make($db->fetch('SELECT * FROM payment_methods WHERE id = ?', [$rvMethod]));
        $r = PaymentService::settle($p, $gw, $gw->verifyPayment($p), 'test');
    }
    T::true(str_contains((string) $r, 'review'), 'fixture is held: ' . $r);
    return [$u, $db->fetch('SELECT * FROM payments WHERE id = ?', [$id])];
};
$deposits = static fn (int $pid) => (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE reference = ?", ['payment:' . $pid]);
$audits = static fn (string $action, int $pid) => (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND target_id = ?', [$action, $pid]);

T::test('Review: approve credits the chosen amount exactly once, clears the hold, is audited', function () use ($held, $rvAdmin, $deposits, $audits) {
    [$u, $p] = $held('90');
    T::eq('90.00', PaymentReviewService::suggestedAmount($p));
    T::eq('100.00', PaymentReviewService::maxApprovable($p));
    PaymentReviewService::approve((int) $p['id'], $rvAdmin, '90', 'Checked P2G dashboard: user paid 90');
    $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    T::eq('completed', $row['status']);
    T::eq(0, (int) $row['needs_review']);
    T::eq('90.0000', $row['amount']);
    T::eq('90.000000', Fx::balance((int) $u['id']));
    T::eq(1, $deposits((int) $p['id']));
    $meta = json_decode($row['meta'], true);
    T::eq('approved', $meta['review_resolution']);
    T::eq('100.00', $meta['review_history'][0]['requested']);
    T::true(!isset($meta['review']), 'hold reason moved into history');
    T::true(str_contains((string) $meta['review_history'][0]['reason'], 'amount'), 'original reason kept');
    T::eq(1, $audits('payment.review.approve', (int) $p['id']));
    // Second approval, reject and release are all refused; still one credit.
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, '90', 'again'));
    T::throws(ValidationException::class, fn () => PaymentReviewService::reject((int) $p['id'], $rvAdmin, 'too late'));
    T::throws(ValidationException::class, fn () => PaymentReviewService::release((int) $p['id'], $rvAdmin, 'too late'));
    T::eq(1, $deposits((int) $p['id']));
    T::eq('90.000000', Fx::balance((int) $u['id']));
});

T::test('Review: approve validates amount, cap and note; nothing changes on error', function () use ($held, $rvAdmin, $deposits) {
    [$u, $p] = $held('90');
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, '100.01', 'typo'), 'cannot exceed');
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, '0', 'zero'));
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, 'abc', 'nan'));
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, '-5', 'negative'));
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve((int) $p['id'], $rvAdmin, '90', '  '), 'note');
    $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    T::eq(['pending', 1, '100.0000'], [$row['status'], (int) $row['needs_review'], $row['amount']]);
    T::eq(0, $deposits((int) $p['id']));
    // Overpayment: the cap is the gateway-confirmed amount.
    [, $p2] = $held('150');
    T::eq('150.00', PaymentReviewService::maxApprovable($p2));
    PaymentReviewService::approve((int) $p2['id'], $rvAdmin, '150', 'User paid 150, confirmed');
    T::eq(1, $deposits((int) $p2['id']));
});

T::test('Review: payments not held for review cannot be approved/rejected/released', function () use ($rvAdmin, $rvMethod) {
    $db = Database::instance();
    $u = Fx::user();
    $id = $db->insert('payments', ['user_id' => $u['id'], 'payment_method_id' => $rvMethod, 'gateway' => 'p2gateway', 'amount' => '50', 'fee' => '0', 'currency' => 'USD', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve($id, $rvAdmin, '50', 'no hold'), 'not held');
    T::throws(ValidationException::class, fn () => PaymentReviewService::reject($id, $rvAdmin, 'no hold'), 'not held');
    T::throws(ValidationException::class, fn () => PaymentReviewService::release($id, $rvAdmin, 'no hold'), 'not held');
    T::throws(ValidationException::class, fn () => PaymentReviewService::approve(99999999, $rvAdmin, '50', 'missing'), 'not found');
    T::eq('0.000000', Fx::balance((int) $u['id']));
});

T::test('Review: reject never credits, notifies the user, and blocks later webhook/cron/return-page credits', function () use ($held, $rvAdmin, $deposits, $audits) {
    [$u, $p] = $held('90');
    T::throws(ValidationException::class, fn () => PaymentReviewService::reject((int) $p['id'], $rvAdmin, ''), 'reason');
    PaymentReviewService::reject((int) $p['id'], $rvAdmin, 'Amount does not match; contact support with your UTR.');
    $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    T::eq(['failed', 0], [$row['status'], (int) $row['needs_review']]);
    T::eq(1, $audits('payment.review.reject', (int) $p['id']));
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE ?", [$u['id'], '%was not credited%']));
    // Gateway later reports the full, correct amount: still never credited.
    RV::gatewaySays('100');
    $r = PaymentService::handleWebhook('p2gateway', Request::create('POST', '/webhooks/p2gateway', [], [], json_encode(['order_id' => $row['merchant_order_id']])));
    T::eq(200, $r->status());
    T::true(str_contains((string) Database::instance()->fetchColumn('SELECT result FROM webhook_logs WHERE payment_id = ? ORDER BY id DESC LIMIT 1', [$p['id']]), 'Rejected by admin'));
    PaymentService::verifyPending();
    T::eq(null, PaymentService::verifyOne((int) $p['id'], 'return-page'));
    T::eq(false, PaymentService::complete((int) $p['id'], 'webhook'));
    T::eq(0, $deposits((int) $p['id']));
    T::eq('0.000000', Fx::balance((int) $u['id']));
    T::eq('failed', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p['id']]));
});

T::test('Review: release re-verifies — credits only on an exact gateway match, else held again', function () use ($held, $rvAdmin, $deposits, $audits) {
    [$u, $p] = $held('90');
    RV::gatewaySays('90'); // still wrong
    $out = PaymentReviewService::release((int) $p['id'], $rvAdmin, 'Gateway says it fixed the record');
    T::true(str_contains($out, 'review'), $out);
    $row = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$p['id']]);
    T::eq(['pending', 1], [$row['status'], (int) $row['needs_review']]);
    T::eq(0, $deposits((int) $p['id']));
    RV::gatewaySays('100'); // now correct
    T::eq('Credited', PaymentReviewService::release((int) $p['id'], $rvAdmin, 'Gateway corrected the amount'));
    T::eq(1, RV::$calls);
    T::eq(1, $deposits((int) $p['id']));
    T::eq('100.000000', Fx::balance((int) $u['id']));
    T::eq(2, $audits('payment.review.release', (int) $p['id']));
    T::eq(2, count(json_decode((string) Database::instance()->fetchColumn('SELECT meta FROM payments WHERE id = ?', [$p['id']]), true)['review_history']));
});

T::test('Review: held payment that already expired can still be approved or rejected', function () use ($held, $rvAdmin, $deposits) {
    [, $p] = $held('90', 'expired');
    T::eq(1, (int) $p['needs_review']);
    T::eq(1, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM payments WHERE id = ? AND needs_review = 1 AND status <> 'completed'", [$p['id']]));
    PaymentReviewService::approve((int) $p['id'], $rvAdmin, '90', 'Late payment confirmed in dashboard');
    T::eq(1, $deposits((int) $p['id']));
    [, $p2] = $held('90', 'expired');
    PaymentReviewService::reject((int) $p2['id'], $rvAdmin, 'Not received');
    T::eq('expired', Database::instance()->fetchColumn('SELECT status FROM payments WHERE id = ?', [$p2['id']]));
});

T::test('Review: concurrent approvals from two admins credit once', function () use ($held, $rvAdmin, $deposits) {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped: pcntl not available)\n";
        return;
    }
    [$u, $p] = $held('90');
    $pids = [];
    for ($i = 0; $i < 4; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            Database::setInstance(null);
            try {
                PaymentReviewService::approve((int) $p['id'], $rvAdmin, '90', 'race ' . $i);
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
    T::eq(1, $deposits((int) $p['id']));
    T::eq('90.000000', Fx::balance((int) $u['id']));
});

T::test('Review: admin HTTP flow — detail page, CSRF, permission, approve', function () use ($held, $rvAdmin, $rvSupport, $deposits) {
    [$u, $p] = $held('90');
    $base = '/' . admin_path() . '/payments/' . $p['id'];
    login_as_admin($rvSupport); // payments.view only
    $html = http('GET', $base)->body();
    T::true(str_contains($html, 'Held for review'), 'support can see the review');
    T::true(!str_contains($html, 'review/approve'), 'no action forms without payments.manage');
    $e = null;
    try {
        http('POST', $base . '/review/approve', ['_token' => csrf(), 'amount' => '90', 'note' => 'nope']);
    } catch (HttpException $ex) {
        $e = $ex;
    }
    T::eq(403, $e?->getStatus());
    login_as_admin($rvAdmin);
    $html = http('GET', $base)->body();
    T::true(str_contains($html, 'review/approve') && str_contains($html, 'review/reject') && str_contains($html, 'review/release'));
    T::true(!str_contains($html, RV_TOKEN), 'token not in page');
    T::true(str_contains(http('GET', '/' . admin_path() . '/payments', ['review' => '1'])->body(), 'payments/' . $p['id'] . '"'), 'listed with review link');
    T::throws(HttpException::class, fn () => http('POST', $base . '/review/approve', ['amount' => '90', 'note' => 'no csrf']));
    T::eq(0, $deposits((int) $p['id']));
    $r = http('POST', $base . '/review/approve', ['_token' => csrf(), 'amount' => '90', 'note' => 'Verified in dashboard']);
    T::eq(302, $r->status());
    T::eq(1, $deposits((int) $p['id']));
    $html = http('GET', $base)->body();
    T::true(str_contains($html, 'Verified in dashboard') && str_contains($html, 'reviewer'), 'history shows note and admin');
});
