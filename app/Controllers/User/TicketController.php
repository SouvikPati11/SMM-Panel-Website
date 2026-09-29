<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\TicketService;
use App\Services\UploadService;

final class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $tickets = Paginator::query('*', 'FROM tickets WHERE user_id = ?', [(int) $user['id']], 'last_reply_at DESC', $this->pageNum($request), 20);
        return $this->view('user/tickets', ['title' => 'Support tickets', 'tickets' => $tickets]);
    }

    public function create(Request $request): Response
    {
        return $this->view('user/ticket-new', ['title' => 'New ticket', 'orderRef' => preg_replace('/[^0-9,]/', '', $request->str('order')), 'category' => $request->str('category')]);
    }

    public function store(Request $request): Response
    {
        $id = TicketService::create((int) $this->user()['id'], $request->str('category'), $request->str('subject'), (string) ($request->post()['message'] ?? ''), $request->str('order_ref'), $request->file('attachment'));
        $this->success('Ticket #' . $id . ' created. Our team will reply soon.');
        return $this->redirect('/tickets/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user();
        $db = Database::instance();
        $ticket = $db->fetch('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$ticket) {
            $this->notFound();
        }
        if ((int) $ticket['user_unread'] === 1) {
            $db->update('tickets', ['user_unread' => 0], ['id' => $id]);
        }
        return $this->view('user/ticket-show', ['title' => 'Ticket #' . $id, 'ticket' => $ticket, 'messages' => TicketService::thread($id)]);
    }

    public function reply(Request $request, int $id): Response
    {
        TicketService::userReply((int) $this->user()['id'], $id, (string) ($request->post()['message'] ?? ''), $request->file('attachment'));
        $this->success('Reply sent.');
        return $this->redirect('/tickets/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $n = Database::instance()->query("UPDATE tickets SET status = 'closed', updated_at = ? WHERE id = ? AND user_id = ?", [now(), $id, (int) $this->user()['id']])->rowCount();
        if ($n) {
            $this->success('Ticket closed.');
        }
        return $this->redirect('/tickets/' . $id);
    }

    /** Attachments are private: only the ticket owner (or staff via admin route) can download. */
    public function attachment(Request $request, int $id): Response
    {
        $a = Database::instance()->fetch(
            'SELECT a.* FROM ticket_attachments a JOIN ticket_messages m ON m.id = a.message_id JOIN tickets t ON t.id = m.ticket_id WHERE a.id = ? AND t.user_id = ? AND m.is_deleted = 0',
            [$id, (int) $this->user()['id']]
        );
        if (!$a) {
            $this->notFound();
        }
        return UploadService::serve($a['path'], !str_starts_with($a['mime'], 'image/'));
    }
}
