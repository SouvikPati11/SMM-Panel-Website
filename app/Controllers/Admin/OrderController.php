<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;
use App\Services\AuditService;
use App\Services\OrderService;

final class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        $status = $request->str('status');
        if (in_array($status, ['pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled', 'refunded', 'failed'], true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        if ($request->bool('attention')) {
            $where .= ' AND o.needs_attention = 1';
        }
        if ($pid = $request->int('provider')) {
            $where .= ' AND o.provider_id = ?';
            $params[] = $pid;
        }
        if ($sid = $request->int('service')) {
            $where .= ' AND o.service_id = ?';
            $params[] = $sid;
        }
        if ($uid = $request->int('user')) {
            $where .= ' AND o.user_id = ?';
            $params[] = $uid;
        }
        $q = mb_substr($request->str('q'), 0, 200);
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where .= ' AND (o.id = ? OR o.provider_order_id = ?)';
                array_push($params, (int) $q, $q);
            } else {
                $where .= ' AND (o.link LIKE ? OR u.username LIKE ? OR o.provider_order_id = ?)';
                array_push($params, Database::like($q), Database::like($q), $q);
            }
        }
        $from = $request->str('from');
        $to = $request->str('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND o.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where .= ' AND o.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $orders = Paginator::query(
            'o.*, u.username, s.name AS service, p.name AS provider',
            "FROM orders o JOIN users u ON u.id = o.user_id JOIN services s ON s.id = o.service_id LEFT JOIN providers p ON p.id = o.provider_id {$where}",
            $params,
            'o.id DESC',
            $this->pageNum($request),
            40
        );
        $db = Database::instance();
        return $this->view('admin/orders/index', [
            'title' => 'Orders',
            'orders' => $orders,
            'counts' => $db->fetchPairs('SELECT status, COUNT(*) FROM orders GROUP BY status'),
            'attention' => (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE needs_attention = 1'),
            'providers' => $db->fetchPairs('SELECT id, name FROM providers ORDER BY name'),
            'f' => ['status' => $status, 'q' => $q, 'provider' => $request->int('provider'), 'from' => $from, 'to' => $to, 'attention' => $request->bool('attention')],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $db = Database::instance();
        $order = $db->fetch(
            'SELECT o.*, u.username, u.email, s.name AS service, s.type AS service_type, s.provider_service_id, p.name AS provider
             FROM orders o JOIN users u ON u.id = o.user_id JOIN services s ON s.id = o.service_id LEFT JOIN providers p ON p.id = o.provider_id WHERE o.id = ?',
            [$id]
        );
        if (!$order) {
            $this->notFound();
        }
        return $this->view('admin/orders/show', [
            'title' => 'Order #' . $id,
            'order' => $order,
            'logs' => $db->fetchAll('SELECT * FROM order_logs WHERE order_id = ? ORDER BY id', [$id]),
            'tx' => $db->fetchAll('SELECT * FROM transactions WHERE order_id = ? ORDER BY id', [$id]),
            'refills' => $db->fetchAll('SELECT * FROM refills WHERE order_id = ? ORDER BY id DESC', [$id]),
        ]);
    }

    public function status(Request $request, int $id): Response
    {
        $start = $request->str('start_count');
        $remains = $request->str('remains');
        OrderService::adminSetStatus($id, $request->str('status'), $start !== '' ? (int) $start : null, $remains !== '' ? (int) $remains : null, (int) $this->admin()['id']);
        $this->success('Order updated.');
        return Response::redirect(admin_url('orders/' . $id));
    }

    public function refund(Request $request, int $id): Response
    {
        $reason = trim($request->str('reason'));
        if ($reason === '') {
            throw new ValidationException('Enter a reason for the refund.');
        }
        $amount = OrderService::adminRefund($id, (int) $this->admin()['id'], $reason);
        $this->success('Refunded ' . money($amount) . '.');
        return Response::redirect(admin_url('orders/' . $id));
    }

    public function resolve(Request $request, int $id): Response
    {
        OrderService::adminResolveUnknown($id, $request->str('action'), $request->str('provider_order_id'), (int) $this->admin()['id']);
        $this->success('Order resolved.');
        return Response::redirect(admin_url('orders/' . $id));
    }

    /** Pull the current status from the provider right now. */
    public function sync(Request $request, int $id): Response
    {
        $db = Database::instance();
        $order = OrderService::find($id);
        if (!$order || !$order['provider_order_id'] || !$order['provider_id']) {
            throw new ValidationException('This order has no provider order ID to sync.');
        }
        $provider = $db->fetch('SELECT * FROM providers WHERE id = ?', [$order['provider_id']]);
        try {
            $status = ProviderFactory::make($provider)->status((string) $order['provider_order_id']);
        } catch (ProviderException $e) {
            throw new ValidationException('Provider: ' . $e->getMessage());
        }
        OrderService::applyStatus($id, $status, 'admin:' . $this->admin()['id']);
        AuditService::log('order.sync', 'order', $id, ['status' => $status->status]);
        $this->success('Provider status: ' . str_replace('_', ' ', $status->status) . '.');
        return Response::redirect(admin_url('orders/' . $id));
    }

    public function refills(Request $request): Response
    {
        $status = $request->str('status');
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($status, ['pending', 'processing', 'completed', 'rejected', 'failed'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        $refills = Paginator::query(
            'r.*, u.username, o.link, o.provider_order_id, s.name AS service',
            "FROM refills r JOIN users u ON u.id = r.user_id JOIN orders o ON o.id = r.order_id JOIN services s ON s.id = o.service_id {$where}",
            $params,
            'r.id DESC',
            $this->pageNum($request),
            40
        );
        return $this->view('admin/orders/refills', ['title' => 'Refills', 'refills' => $refills, 'status' => $status]);
    }

    public function refillUpdate(Request $request, int $id): Response
    {
        $status = $request->str('status');
        if (!in_array($status, ['pending', 'processing', 'completed', 'rejected'], true)) {
            throw new ValidationException('Invalid status.');
        }
        Database::instance()->update('refills', ['status' => $status, 'message' => mb_substr($request->str('message'), 0, 500) ?: null, 'updated_at' => now()], ['id' => $id]);
        AuditService::log('refill.update', 'refill', $id, ['status' => $status]);
        $this->success('Refill updated.');
        return $this->back($request, admin_url('refills'));
    }
}
