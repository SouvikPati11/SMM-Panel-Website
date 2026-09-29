<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Payment\GatewayRegistry;
use App\Services\AuditService;
use App\Services\ManualPaymentService;
use App\Services\PaymentService;
use App\Services\UploadService;
use App\Services\WalletService;

final class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        $status = $request->str('status');
        if (in_array($status, ['pending', 'completed', 'failed', 'expired', 'cancelled'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        $gw = $request->str('gateway');
        if ($gw !== '' && preg_match('/^[a-z0-9_]+$/', $gw)) {
            $where .= ' AND p.gateway = ?';
            $params[] = $gw;
        }
        if ($request->str('review') === '1') {
            $where .= ' AND p.needs_review = 1';
        }
        $q = mb_substr($request->str('q'), 0, 128);
        if ($q !== '') {
            $where .= ' AND (p.id = ? OR p.gateway_ref = ? OR p.merchant_order_id = ? OR p.utr = ? OR u.username LIKE ?)';
            array_push($params, ctype_digit($q) ? (int) $q : 0, $q, $q, $q, Database::like($q));
        }
        $payments = Paginator::query('p.*, u.username, pm.name AS method', "FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id {$where}", $params, 'p.id DESC', $this->pageNum($request), 40);
        $sum = Database::instance()->fetch("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM payments WHERE status = 'completed' AND completed_at >= ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $review = (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM payments WHERE needs_review = 1 AND status = ?', ['pending']);
        return $this->view('admin/payments/index', ['title' => 'Payments', 'payments' => $payments, 'status' => $status, 'gateway' => $gw, 'q' => $q, 'sum' => $sum, 'reviewCount' => $review, 'reviewOnly' => $request->str('review') === '1']);
    }

    /** Re-query the gateway for a pending payment (never trusts anything but the gateway API). */
    public function verify(Request $request, int $id): Response
    {
        $p = Database::instance()->fetch('SELECT * FROM payments WHERE id = ?', [$id]);
        if (!$p || !GatewayRegistry::isAutomatic($p['gateway'])) {
            throw new ValidationException('Only automatic gateway payments can be verified.');
        }
        if ($p['status'] !== 'pending') {
            throw new ValidationException('Only pending payments can be verified (this one is ' . $p['status'] . ').');
        }
        $res = PaymentService::verifyOne($id, 'admin-verify:' . $this->admin()['id']);
        AuditService::log('payment.verify', 'payment', $id, ['result' => $res]);
        $this->success('Gateway check: ' . ($res ?? 'nothing to verify') . '.');
        return Response::redirect(admin_url('payments'));
    }

    public function manual(Request $request): Response
    {
        $status = in_array($request->str('status'), ['pending', 'approved', 'rejected'], true) ? $request->str('status') : 'pending';
        $items = Paginator::query(
            'r.*, u.username, u.email, pm.name AS method, pm.account, c.code AS coupon_code',
            'FROM manual_payment_requests r JOIN users u ON u.id = r.user_id JOIN payment_methods pm ON pm.id = r.payment_method_id LEFT JOIN coupons c ON c.id = r.coupon_id WHERE r.status = ?',
            [$status],
            $status === 'pending' ? 'r.id ASC' : 'r.id DESC',
            $this->pageNum($request),
            30
        );
        $counts = Database::instance()->fetchPairs('SELECT status, COUNT(*) FROM manual_payment_requests GROUP BY status');
        return $this->view('admin/payments/manual', ['title' => 'Manual payments', 'items' => $items, 'status' => $status, 'counts' => $counts]);
    }

    public function approve(Request $request, int $id): Response
    {
        ManualPaymentService::approve($id, (int) $this->admin()['id'], $request->str('amount') ?: null, $request->str('note'));
        $this->success('Payment approved and balance credited.');
        return $this->back($request, admin_url('manual-payments'));
    }

    public function reject(Request $request, int $id): Response
    {
        ManualPaymentService::reject($id, (int) $this->admin()['id'], $request->str('reason'));
        $this->success('Request rejected.');
        return $this->back($request, admin_url('manual-payments'));
    }

    public function proof(Request $request, int $id): Response
    {
        $r = Database::instance()->fetch('SELECT proof_path FROM manual_payment_requests WHERE id = ?', [$id]);
        if (!$r || !$r['proof_path']) {
            $this->notFound();
        }
        return UploadService::serve($r['proof_path']);
    }

    public function transactions(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        $type = $request->str('type');
        if (in_array($type, WalletService::TYPES, true)) {
            $where .= ' AND t.type = ?';
            $params[] = $type;
        }
        if ($uid = $request->int('user')) {
            $where .= ' AND t.user_id = ?';
            $params[] = $uid;
        }
        $q = mb_substr($request->str('q'), 0, 100);
        if ($q !== '') {
            $where .= ' AND (u.username LIKE ? OR t.reference = ? OR t.id = ?)';
            array_push($params, Database::like($q), $q, ctype_digit($q) ? (int) $q : 0);
        }
        $from = $request->str('from');
        $to = $request->str('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND t.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where .= ' AND t.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $tx = Paginator::query('t.*, u.username', "FROM transactions t JOIN users u ON u.id = t.user_id {$where}", $params, 't.id DESC', $this->pageNum($request), 50);
        $totals = Database::instance()->fetchPairs("SELECT t.type, SUM(t.amount) FROM transactions t JOIN users u ON u.id = t.user_id {$where} GROUP BY t.type", $params);
        return $this->view('admin/payments/transactions', ['title' => 'Transactions', 'tx' => $tx, 'type' => $type, 'q' => $q, 'from' => $from, 'to' => $to, 'totals' => $totals]);
    }
}
