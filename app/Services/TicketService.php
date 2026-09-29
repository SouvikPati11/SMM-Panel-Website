<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;

final class TicketService
{
    public const CATEGORIES = ['order' => 'Order', 'payment' => 'Payment', 'refill' => 'Refill', 'api' => 'API', 'other' => 'Other'];
    public const STATUSES = ['open', 'pending', 'answered', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public static function create(int $userId, string $category, string $subject, string $message, string $orderRef, ?array $attachment): int
    {
        if (!isset(self::CATEGORIES[$category])) {
            throw new ValidationException('Choose a ticket category.');
        }
        $subject = trim($subject);
        $message = trim($message);
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
            throw new ValidationException('Subject must be 3–200 characters.');
        }
        self::assertMessage($message);
        $db = Database::instance();
        $open = (int) $db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status <> 'closed'", [$userId]);
        if ($open >= max(1, (int) setting('ticket_max_open', '5'))) {
            throw new ValidationException('You have too many open tickets. Please wait for a reply or close an existing ticket.');
        }
        $orderRef = mb_substr(preg_replace('/[^0-9, ]/', '', $orderRef), 0, 200);

        $id = $db->transaction(static function (Database $db) use ($userId, $category, $subject, $message, $orderRef, $attachment): int {
            $id = $db->insert('tickets', [
                'user_id' => $userId, 'subject' => $subject, 'category' => $category, 'order_ref' => $orderRef ?: null,
                'status' => 'open', 'priority' => 'normal', 'user_unread' => 0, 'admin_unread' => 1,
                'last_reply_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::addMessage($id, $userId, null, $message, $attachment);
            return $id;
        });
        NotificationService::notifyAdmin('New ticket #' . $id . ': ' . $subject, '<p>' . nl2br(e(mb_substr($message, 0, 1000))) . '</p>');
        return $id;
    }

    public static function userReply(int $userId, int $ticketId, string $message, ?array $attachment): void
    {
        $db = Database::instance();
        $t = $db->fetch('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$ticketId, $userId]);
        if (!$t) {
            throw new ValidationException('Ticket not found.');
        }
        if ($t['status'] === 'closed') {
            throw new ValidationException('This ticket is closed. Please open a new ticket.');
        }
        self::assertMessage(trim($message));
        $db->transaction(static function (Database $db) use ($ticketId, $userId, $message, $attachment): void {
            self::addMessage($ticketId, $userId, null, trim($message), $attachment);
            $db->update('tickets', ['status' => 'pending', 'admin_unread' => 1, 'last_reply_at' => now(), 'updated_at' => now()], ['id' => $ticketId]);
        });
    }

    public static function adminReply(int $adminId, int $ticketId, string $message, ?array $attachment, ?string $status = 'answered'): void
    {
        $db = Database::instance();
        $t = $db->fetch('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
        if (!$t) {
            throw new ValidationException('Ticket not found.');
        }
        self::assertMessage(trim($message));
        $status = in_array($status, self::STATUSES, true) ? $status : 'answered';
        $db->transaction(static function (Database $db) use ($ticketId, $adminId, $message, $attachment, $status): void {
            self::addMessage($ticketId, null, $adminId, trim($message), $attachment);
            $db->update('tickets', ['status' => $status, 'user_unread' => 1, 'admin_unread' => 0, 'last_reply_at' => now(), 'updated_at' => now()], ['id' => $ticketId]);
        });
        NotificationService::notify((int) $t['user_id'], 'ticket', 'New reply on ticket #' . $ticketId, mb_substr($t['subject'], 0, 150), '/tickets/' . $ticketId);
    }

    private static function addMessage(int $ticketId, ?int $userId, ?int $adminId, string $message, ?array $attachment): void
    {
        $db = Database::instance();
        $msgId = $db->insert('ticket_messages', ['ticket_id' => $ticketId, 'user_id' => $userId, 'admin_id' => $adminId, 'message' => $message, 'created_at' => now()]);
        if ($attachment) {
            if (setting('ticket_attachments', '1') !== '1') {
                throw new ValidationException('Attachments are disabled.');
            }
            $a = UploadService::storeAttachment($attachment);
            $db->insert('ticket_attachments', ['message_id' => $msgId, 'path' => $a['path'], 'original' => $a['original'], 'mime' => $a['mime'], 'size' => $a['size'], 'created_at' => now()]);
        }
    }

    private static function assertMessage(string $message): void
    {
        if (mb_strlen($message) < 2 || mb_strlen($message) > 5000) {
            throw new ValidationException('Message must be 2–5000 characters.');
        }
    }

    /** Messages with attachments in 2 queries (no N+1). */
    public static function thread(int $ticketId): array
    {
        $db = Database::instance();
        $messages = $db->fetchAll(
            'SELECT m.*, u.username, a.name AS admin_name, a.username AS admin_username FROM ticket_messages m
             LEFT JOIN users u ON u.id = m.user_id LEFT JOIN admins a ON a.id = m.admin_id
             WHERE m.ticket_id = ? ORDER BY m.id ASC',
            [$ticketId]
        );
        if (!$messages) {
            return [];
        }
        $ids = array_column($messages, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $atts = [];
        foreach ($db->fetchAll("SELECT * FROM ticket_attachments WHERE message_id IN ({$ph})", $ids) as $a) {
            $atts[$a['message_id']][] = $a;
        }
        foreach ($messages as &$m) {
            $m['attachments'] = $atts[$m['id']] ?? [];
        }
        return $messages;
    }
}
