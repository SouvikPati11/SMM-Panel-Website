<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\SubscriptionService;

/** The signed-in user's auto-subscriptions (strictly scoped by user_id). */
final class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $status = $request->str('status');
        $where = 'WHERE s.user_id = ?';
        $params = [(int) $user['id']];
        if (in_array($status, SubscriptionService::STATUSES, true)) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        $subs = Paginator::query('s.*, sv.name AS service', "FROM subscriptions s JOIN services sv ON sv.id = s.service_id {$where}", $params, 's.id DESC', $this->pageNum($request), 25);
        $counts = Database::instance()->fetchPairs('SELECT status, COUNT(*) FROM subscriptions WHERE user_id = ? GROUP BY status', [(int) $user['id']]);
        return $this->view('user/subscriptions', ['title' => 'Subscriptions', 'subs' => $subs, 'status' => $status, 'counts' => $counts]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user();
        $db = Database::instance();
        $sub = $db->fetch('SELECT s.*, sv.name AS service, sv.type AS service_type FROM subscriptions s JOIN services sv ON sv.id = s.service_id WHERE s.id = ? AND s.user_id = ?', [$id, (int) $user['id']]);
        if (!$sub) {
            $this->notFound();
        }
        return $this->view('user/subscription-show', [
            'title' => 'Subscription #' . $id,
            'sub' => $sub,
            'orders' => $db->fetchAll('SELECT id, subscription_cycle, charge, refunded_amount, status, created_at FROM orders WHERE subscription_id = ? AND user_id = ? ORDER BY subscription_cycle DESC, id DESC', [$id, (int) $user['id']]),
            'logs' => $db->fetchAll("SELECT event, cycle, order_id, message, created_at FROM subscription_logs WHERE subscription_id = ? ORDER BY id DESC LIMIT 50", [$id]),
            'perDelivery' => SubscriptionService::cycleCharge($sub, $user),
            'spent' => SubscriptionService::spent($id),
        ]);
    }

    public function action(Request $request, int $id): Response
    {
        $action = $request->str('action');
        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
            throw new ValidationException('Unknown action.');
        }
        $sub = SubscriptionService::changeStatus($id, $action, 'user', (int) $this->user()['id']);
        $this->success('Subscription #' . $id . ' is now ' . $sub['status'] . '.');
        return $this->redirect('/subscriptions/' . $id);
    }
}
