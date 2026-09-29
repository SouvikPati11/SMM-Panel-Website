<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Payment\GatewayRegistry;
use App\Services\Auth;
use App\Services\CronService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Auth::adminCan('dashboard.view')) {
            return $this->view('admin/welcome', ['title' => 'Welcome']);
        }
        $db = Database::instance();
        $today = gmdate('Y-m-d 00:00:00');
        $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);

        $stats = [
            'users' => (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'users_new' => (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE created_at >= ? AND deleted_at IS NULL', [$d30]),
            'users_today' => (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
            'balance_total' => (string) $db->fetchColumn('SELECT COALESCE(SUM(balance),0) FROM wallets'),
        ];
        $orderCounts = $db->fetchPairs('SELECT status, COUNT(*) FROM orders GROUP BY status');
        $stats['orders'] = array_sum($orderCounts);
        $stats['orders_pending'] = (int) ($orderCounts['pending'] ?? 0);
        $stats['orders_active'] = (int) ($orderCounts['processing'] ?? 0) + (int) ($orderCounts['in_progress'] ?? 0);
        $stats['orders_completed'] = (int) ($orderCounts['completed'] ?? 0);
        $stats['orders_attention'] = (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE needs_attention = 1');

        $fin = $db->fetch(
            "SELECT
               COALESCE(SUM(CASE WHEN type='deposit' THEN amount END),0) AS deposits,
               COALESCE(-SUM(CASE WHEN type='order_charge' THEN amount END),0) AS revenue_gross,
               COALESCE(SUM(CASE WHEN type='refund' THEN amount END),0) AS refunds
             FROM transactions WHERE created_at >= ?",
            [$d30]
        );
        $stats['deposits_30'] = (string) $fin['deposits'];
        $stats['refunds_30'] = (string) $fin['refunds'];
        $stats['revenue_30'] = \App\Core\Money::sub((string) $fin['revenue_gross'], (string) $fin['refunds']);
        $stats['deposits_today'] = (string) $db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = 'deposit' AND created_at >= ?", [$today]);
        $stats['profit_30'] = (string) $db->fetchColumn("SELECT COALESCE(SUM(charge - refunded_amount - COALESCE(cost,0)),0) FROM orders WHERE created_at >= ? AND cost IS NOT NULL", [$d30]);

        // 14-day chart data (orders revenue & deposits per day)
        $chart = [];
        for ($i = 13; $i >= 0; $i--) {
            $chart[gmdate('Y-m-d', time() - $i * 86400)] = ['orders' => '0', 'deposits' => '0'];
        }
        foreach ($db->fetchAll(
            "SELECT DATE(created_at) d, COALESCE(-SUM(CASE WHEN type='order_charge' THEN amount END),0) o, COALESCE(SUM(CASE WHEN type='deposit' THEN amount END),0) dep
             FROM transactions WHERE created_at >= ? GROUP BY DATE(created_at)",
            [gmdate('Y-m-d 00:00:00', time() - 13 * 86400)]
        ) as $r) {
            if (isset($chart[$r['d']])) {
                $chart[$r['d']] = ['orders' => (string) $r['o'], 'deposits' => (string) $r['dep']];
            }
        }

        $gateways = [];
        foreach ($db->fetchAll("SELECT * FROM payment_methods WHERE gateway <> 'manual' ORDER BY sort_order") as $m) {
            try {
                $gw = GatewayRegistry::make($m);
                $gateways[] = ['name' => $m['name'], 'status' => $m['status'], 'configured' => $gw->isConfigured(), 'implemented' => $gw->isImplemented()];
            } catch (\Throwable) {
            }
        }

        return $this->view('admin/dashboard', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'chart' => $chart,
            'providers' => $db->fetchAll('SELECT id, name, status, connection_status, balance, currency, last_checked_at, last_error FROM providers ORDER BY id'),
            'gateways' => $gateways,
            'recentTx' => $db->fetchAll('SELECT t.*, u.username FROM transactions t JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 8'),
            'recentTickets' => $db->fetchAll('SELECT t.id, t.subject, t.status, t.last_reply_at, t.admin_unread, u.username FROM tickets t JOIN users u ON u.id = t.user_id ORDER BY t.last_reply_at DESC LIMIT 6'),
            'pendingManual' => (int) $db->fetchColumn("SELECT COUNT(*) FROM manual_payment_requests WHERE status = 'pending'"),
            'cron' => CronService::status(),
        ]);
    }
}
