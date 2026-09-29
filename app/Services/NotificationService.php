<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** In-panel notifications with optional email copies (per settings). */
final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        $db = Database::instance();
        $db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 200),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'url' => $url,
            'created_at' => now(),
        ]);

        $emailSetting = match ($type) {
            'order' => 'email_notify_orders',
            'payment' => 'email_notify_payments',
            'ticket' => 'email_notify_tickets',
            default => null,
        };
        if ($emailSetting && setting($emailSetting, '0') === '1') {
            $email = $db->fetchColumn('SELECT email FROM users WHERE id = ?', [$userId]);
            if ($email) {
                $html = '<p>' . e($body ?? $title) . '</p>' . ($url ? MailService::button(url($url), 'View details') : '');
                MailService::queue((string) $email, $title, $html);
            }
        }
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function latest(int $userId, int $limit = 8): array
    {
        return Database::instance()->fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::instance()->query('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $userId]);
    }

    public static function activeAnnouncements(int $limit = 3): array
    {
        return Database::instance()->fetchAll(
            "SELECT * FROM announcements WHERE status = 'active' AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY id DESC LIMIT " . (int) $limit,
            [now(), now()]
        );
    }

    /** Email the admin notification address (new ticket, manual payment, provider failures). */
    public static function notifyAdmin(string $subject, string $html): void
    {
        $to = (string) setting('admin_notify_email', '');
        if ($to !== '') {
            MailService::queue($to, $subject, $html);
        }
    }
}
