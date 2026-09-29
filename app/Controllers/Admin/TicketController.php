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
use App\Services\TicketService;
use App\Services\UploadService;

final class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'WHERE 1=1';
        $params = [];
        $status = $request->str('status', 'active');
        if ($status === 'active') {
            $where .= " AND t.status <> 'closed'";
        } elseif (in_array($status, TicketService::STATUSES, true)) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        if ($request->str('mine') === '1') {
            $where .= ' AND t.assigned_to = ?';
            $params[] = (int) $this->admin()['id'];
        }
        $q = mb_substr($request->str('q'), 0, 100);
        if ($q !== '') {
            $where .= ' AND (t.subject LIKE ? OR u.username LIKE ? OR t.id = ? OR t.order_ref LIKE ?)';
            array_push($params, Database::like($q), Database::like($q), ctype_digit($q) ? (int) $q : 0, Database::like($q));
        }
        $tickets = Paginator::query(
            't.*, u.username, a.username AS assignee',
            "FROM tickets t JOIN users u ON u.id = t.user_id LEFT JOIN admins a ON a.id = t.assigned_to {$where}",
            $params,
            "t.admin_unread DESC, FIELD(t.priority,'urgent','high','normal','low'), t.last_reply_at DESC",
            $this->pageNum($request),
            40
        );
        return $this->view('admin/tickets/index', ['title' => 'Tickets', 'tickets' => $tickets, 'status' => $status, 'q' => $q]);
    }

    public function show(Request $request, int $id): Response
    {
        $db = Database::instance();
        $ticket = $db->fetch('SELECT t.*, u.username, u.email FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
        if (!$ticket) {
            $this->notFound();
        }
        if ((int) $ticket['admin_unread'] === 1) {
            $db->update('tickets', ['admin_unread' => 0], ['id' => $id]);
        }
        $orderIds = array_filter(array_map('intval', preg_split('/[,\s]+/', (string) $ticket['order_ref'])));
        $orders = [];
        if ($orderIds) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $orders = $db->fetchAll("SELECT o.id, o.status, o.charge, s.name AS service FROM orders o JOIN services s ON s.id = o.service_id WHERE o.user_id = ? AND o.id IN ({$ph})", array_merge([$ticket['user_id']], array_slice($orderIds, 0, 20)));
        }
        return $this->view('admin/tickets/show', [
            'title' => 'Ticket #' . $id,
            'ticket' => $ticket,
            'messages' => TicketService::thread($id),
            'admins' => $db->fetchPairs("SELECT id, username FROM admins WHERE status = 'active' ORDER BY username"),
            'orders' => $orders,
        ]);
    }

    public function reply(Request $request, int $id): Response
    {
        TicketService::adminReply((int) $this->admin()['id'], $id, (string) ($request->post()['message'] ?? ''), $request->file('attachment'), $request->str('status') ?: 'answered');
        $this->success('Reply sent.');
        return Response::redirect(admin_url('tickets/' . $id));
    }

    public function update(Request $request, int $id): Response
    {
        $status = $request->str('status');
        $priority = $request->str('priority');
        if (!in_array($status, TicketService::STATUSES, true) || !in_array($priority, TicketService::PRIORITIES, true)) {
            throw new ValidationException('Invalid status or priority.');
        }
        $assignee = $request->int('assigned_to') ?: null;
        Database::instance()->update('tickets', ['status' => $status, 'priority' => $priority, 'assigned_to' => $assignee, 'updated_at' => now()], ['id' => $id]);
        AuditService::log('ticket.update', 'ticket', $id, compact('status', 'priority', 'assignee'));
        $this->success('Ticket updated.');
        return Response::redirect(admin_url('tickets/' . $id));
    }

    /** Hide an inappropriate message (kept in DB for audit, shown as removed). */
    public function deleteMessage(Request $request, int $id): Response
    {
        $db = Database::instance();
        $m = $db->fetch('SELECT ticket_id FROM ticket_messages WHERE id = ?', [$id]);
        if (!$m) {
            $this->notFound();
        }
        $db->update('ticket_messages', ['is_deleted' => 1], ['id' => $id]);
        AuditService::log('ticket.message_removed', 'ticket_message', $id);
        $this->success('Message removed.');
        return Response::redirect(admin_url('tickets/' . $m['ticket_id']));
    }

    public function attachment(Request $request, int $id): Response
    {
        $a = Database::instance()->fetch('SELECT * FROM ticket_attachments WHERE id = ?', [$id]);
        if (!$a) {
            $this->notFound();
        }
        return UploadService::serve($a['path'], !str_starts_with($a['mime'], 'image/'));
    }
}
