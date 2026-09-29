<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\SubscriptionService;

/** Inspect and manage users' auto-subscriptions. */
final class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        $status = $request->str('status');
        if (in_array($status, SubscriptionService::STATUSES, true)) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        if ($request->bool('failing')) {
            $where .= " AND s.last_error IS NOT NULL AND s.status IN ('active','suspended')";
        }
        $q = mb_substr($request->str('q'), 0, 120);
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where .= ' AND s.id = ?';
                $params[] = (int) $q;
            } else {
                $where .= ' AND (u.username LIKE ? OR s.link LIKE ?)';
                array_push($params, Database::like($q), Database::like($q));
            }
        }
        $subs = Paginator::query('s.*, u.username, sv.name AS service', "FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN services sv ON sv.id = s.service_id {$where}", $params, 's.id DESC', $this->pageNum($request), 40);
        $db = Database::instance();
        return $this->view('admin/subscriptions/index', [
            'title' => 'Subscriptions',
            'subs' => $subs,
            'counts' => $db->fetchPairs('SELECT status, COUNT(*) FROM subscriptions GROUP BY status'),
            'failing' => (int) $db->fetchColumn("SELECT COUNT(*) FROM subscriptions WHERE last_error IS NOT NULL AND status IN ('active','suspended')"),
            'f' => ['status' => $status, 'q' => $q, 'failing' => $request->bool('failing')],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $db = Database::instance();
        $sub = $db->fetch('SELECT s.*, u.username, sv.name AS service, sv.status AS service_status FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN services sv ON sv.id = s.service_id WHERE s.id = ?', [$id]);
        if (!$sub) {
            $this->notFound();
        }
        $user = $db->fetch('SELECT u.*, w.balance FROM users u JOIN wallets w ON w.user_id = u.id WHERE u.id = ?', [$sub['user_id']]);
        return $this->view('admin/subscriptions/show', [
            'title' => 'Subscription #' . $id,
            'sub' => $sub,
            'user' => $user,
            'orders' => $db->fetchAll('SELECT id, subscription_cycle, charge, refunded_amount, status, provider_order_id, created_at FROM orders WHERE subscription_id = ? ORDER BY subscription_cycle DESC, id DESC', [$id]),
            'logs' => $db->fetchAll('SELECT * FROM subscription_logs WHERE subscription_id = ? ORDER BY id DESC LIMIT 100', [$id]),
            'perDelivery' => SubscriptionService::cycleCharge($sub, $user),
            'spent' => SubscriptionService::spent($id),
        ]);
    }

    public function action(Request $request, int $id): Response
    {
        $action = $request->str('action');
        $reason = trim($request->str('reason'));
        $adminId = (int) $this->admin()['id'];
        if ($action === 'run') {
            // Place the next delivery now (still claimed/idempotent, only for active subscriptions).
            Database::instance()->query("UPDATE subscriptions SET next_run_at = ? WHERE id = ? AND status = 'active'", [now(), $id]);
            $r = SubscriptionService::runCycle($id, 'admin:' . $adminId);
            AuditService::log('subscription.run', 'subscription', $id, ['result' => $r]);
            $r === 'skipped' ? $this->error('Nothing to run: the subscription is not active, finished, or already being processed.') : $this->success('Next delivery: ' . $r . '.');
            return Response::redirect(admin_url('subscriptions/' . $id));
        }
        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
            throw new ValidationException('Unknown action.');
        }
        if ($reason === '') {
            throw new ValidationException('Enter a reason (kept in the subscription history and audit log).');
        }
        $before = SubscriptionService::find($id);
        $sub = SubscriptionService::changeStatus($id, $action, 'admin:' . $adminId, null, 'Admin: ' . $reason);
        AuditService::log('subscription.' . $action, 'subscription', $id, ['from' => $before['status'] ?? null, 'to' => $sub['status'], 'reason' => $reason]);
        $this->success('Subscription #' . $id . ' is now ' . $sub['status'] . '.');
        return Response::redirect(admin_url('subscriptions/' . $id));
    }
}
