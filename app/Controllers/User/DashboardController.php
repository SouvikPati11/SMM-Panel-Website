<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;
use App\Services\OrderService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $db = Database::instance();
        $uid = (int) $user['id'];
        return $this->view('user/dashboard', [
            'title' => 'Dashboard',
            'user' => $user,
            'stats' => OrderService::userStats($uid),
            'recentOrders' => $db->fetchAll(
                'SELECT o.id, o.link, o.quantity, o.charge, o.status, o.created_at, s.name AS service FROM orders o JOIN services s ON s.id = o.service_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 6',
                [$uid]
            ),
            'recentTx' => $db->fetchAll('SELECT * FROM transactions WHERE user_id = ? AND wallet = ? ORDER BY id DESC LIMIT 6', [$uid, 'main']),
            'announcements' => NotificationService::activeAnnouncements(3),
            'openTickets' => (int) $db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status <> 'closed'", [$uid]),
            'discount' => OrderService::userDiscount($user),
        ]);
    }
}
