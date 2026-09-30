<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\CouponService;
use App\Services\ManualPaymentService;
use App\Services\PaymentService;

final class FundsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $db = Database::instance();
        return $this->view('user/funds', [
            'title' => 'Add funds',
            'user' => $user,
            'methods' => PaymentService::availableMethods(),
            'lastMobile' => (string) (json_decode((string) $db->fetchColumn("SELECT meta FROM payments WHERE user_id = ? AND gateway = 'p2gateway' ORDER BY id DESC LIMIT 1", [$user['id']]), true)['customer_mobile'] ?? ''),
            'payments' => $db->fetchAll('SELECT p.*, pm.name AS method FROM payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 10', [$user['id']]),
            'manualRequests' => $db->fetchAll('SELECT r.*, pm.name AS method FROM manual_payment_requests r JOIN payment_methods pm ON pm.id = r.payment_method_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 10', [$user['id']]),
        ]);
    }

    public function pay(Request $request): Response
    {
        $user = $this->user();
        $fields = ['customer_mobile' => $request->str('customer_mobile')];
        $payment = PaymentService::createGatewayPayment($user, $request->int('method_id'), $request->str('amount'), $request->str('coupon'), $request->ip(), $fields);
        // Only the gateway URL we received server-side is used for the redirect.
        return Response::redirect((string) $payment['pay_url']);
    }

    public function manual(Request $request): Response
    {
        $user = $this->user();
        $id = ManualPaymentService::submit($user, $request->int('method_id'), $request->str('amount'), $request->str('reference'), $request->file('proof'), $request->str('note'), $request->str('coupon'));
        $this->success('Payment request #' . $id . ' submitted. We will credit your balance after verification.');
        return $this->redirect('/funds');
    }

    public function checkCoupon(Request $request): Response
    {
        $user = $this->user();
        $amount = $request->str('amount');
        if (!Money::isNumeric($amount) || !Money::isPositive($amount)) {
            return $this->json(['ok' => false, 'error' => 'Enter the amount first.']);
        }
        $method = Database::instance()->fetch("SELECT * FROM payment_methods WHERE id = ? AND status = 'active'", [$request->int('method_id')]);
        if (!$method) {
            return $this->json(['ok' => false, 'error' => 'Choose a payment method first.']);
        }
        try {
            $r = CouponService::validate($request->str('code'), (int) $user['id'], Money::of($amount, 2), $method);
            return $this->json(['ok' => true, 'message' => 'Code applied: you will receive a ' . money($r['bonus']) . ' bonus after payment.']);
        } catch (ValidationException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Where the gateway sends the browser back. This page NEVER credits funds;
     * it only shows the current server-side status (webhook/cron do the crediting).
     */
    public function returned(Request $request, int $id): Response
    {
        $user = $this->user();
        $payment = Database::instance()->fetch('SELECT * FROM payments WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$payment) {
            $this->notFound();
        }
        // Ask the GATEWAY (server-to-server) for the status — nothing from the browser or
        // the redirect query string is used. Throttled so refreshing cannot hammer the API.
        if ($payment['status'] === 'pending' && \App\Core\RateLimiter::hit('payverify:' . $id, 1, 15)) {
            try {
                PaymentService::verifyOne($id, 'return-page');
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Return-page verification error: ' . $e->getMessage(), ['payment' => $id], 'payment');
            }
            $payment = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$id]);
        }
        return $this->view('user/funds-return', ['title' => 'Payment status', 'payment' => $payment]);
    }

    public function transactions(Request $request): Response
    {
        $user = $this->user();
        $type = $request->str('type');
        $where = 'WHERE user_id = ?';
        $params = [(int) $user['id']];
        if (in_array($type, \App\Services\WalletService::TYPES, true)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        $tx = Paginator::query('*', "FROM transactions {$where}", $params, 'id DESC', $this->pageNum($request), 30);
        return $this->view('user/transactions', ['title' => 'Transactions', 'tx' => $tx, 'type' => $type, 'user' => $user]);
    }
}
