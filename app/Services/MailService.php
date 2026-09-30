<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;

/**
 * Emails are queued in `email_queue` and delivered by cron/notifications.php,
 * so a slow SMTP server never blocks a web request. Critical emails (password
 * reset, verification) can be sent immediately with sendNow().
 */
final class MailService
{
    public static function queue(string $to, string $subject, string $bodyHtml): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        Database::instance()->insert('email_queue', [
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 255),
            'body_html' => self::wrap($subject, $bodyHtml),
            'status' => 'queued',
            'created_at' => now(),
        ]);
    }

    /** Whether real email delivery is set up (SMTP host, or PHP mail()). */
    public static function isConfigured(): bool
    {
        $c = SettingsService::mailConfig();
        return ($c['driver'] === 'smtp' && trim((string) $c['host']) !== '') || $c['driver'] === 'mail';
    }

    /** Try to send immediately; on failure fall back to the queue. */
    public static function sendNow(string $to, string $subject, string $bodyHtml): bool
    {
        try {
            (new Mailer(SettingsService::mailConfig()))->send($to, $subject, self::wrap($subject, $bodyHtml));
            return true;
        } catch (\Throwable $e) {
            Logger::error('Mail send failed, queued instead: ' . $e->getMessage(), [], 'mail');
            self::queue($to, $subject, $bodyHtml);
            return false;
        }
    }

    public static function processQueue(int $limit = 30): array
    {
        $db = Database::instance();
        $rows = $db->fetchAll("SELECT * FROM email_queue WHERE status = 'queued' AND attempts < 5 ORDER BY id LIMIT " . (int) $limit);
        $mailer = new Mailer(SettingsService::mailConfig());
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $mailer->send($row['to_email'], $row['subject'], $row['body_html']);
                $db->update('email_queue', ['status' => 'sent', 'sent_at' => now(), 'attempts' => (int) $row['attempts'] + 1], ['id' => $row['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $db->update('email_queue', ['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $attempts >= 5 ? 'failed' : 'queued'], ['id' => $row['id']]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function wrap(string $title, string $body): string
    {
        if (str_contains($body, '<!--wrapped-->')) {
            return $body;
        }
        $site = e(site_name());
        $t = e($title);
        $url = e(url('/'));
        return <<<HTML
<!--wrapped--><!doctype html><html><body style="margin:0;background:#f4f5fa;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c2033">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
<tr><td style="padding:22px 28px;border-bottom:1px solid #eceef5;font-weight:700;font-size:18px;color:#5b4dff">{$site}</td></tr>
<tr><td style="padding:28px"><h1 style="font-size:20px;margin:0 0 14px">{$t}</h1><div style="font-size:15px;line-height:1.65;color:#3b4058">{$body}</div></td></tr>
<tr><td style="padding:18px 28px;background:#fafbfe;font-size:12px;color:#8a8fa8">You received this email from <a href="{$url}" style="color:#5b4dff">{$site}</a>.</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    public static function button(string $url, string $label): string
    {
        return '<p style="margin:22px 0"><a href="' . e($url) . '" style="background:#5b4dff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;display:inline-block">' . e($label) . '</a></p>'
            . '<p style="font-size:12px;color:#8a8fa8;word-break:break-all">If the button does not work, open: ' . e($url) . '</p>';
    }
}
