<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\HtmlSanitizer;
use App\Core\Money;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\NotificationService;

final class MarketingController extends Controller
{
    public function coupons(Request $request): Response
    {
        $coupons = Database::instance()->fetchAll('SELECT c.*, (SELECT COALESCE(SUM(amount),0) FROM coupon_usage u WHERE u.coupon_id = c.id) AS bonus_total FROM coupons c ORDER BY c.id DESC');
        return $this->view('admin/marketing/coupons', ['title' => 'Promo codes', 'coupons' => $coupons]);
    }

    public function saveCoupon(Request $request): Response
    {
        $db = Database::instance();
        $id = $request->int('id');
        $data = Validator::check($request->post(), [
            'code' => 'required|regex:/^[A-Za-z0-9_-]{2,40}$/',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|decimal|min:0.01',
            'min_deposit' => 'decimal|min:0',
            'max_discount' => 'decimal|min:0',
            'usage_limit' => 'integer|min:1',
            'per_user_limit' => 'integer|min:1',
            'starts_at' => 'date',
            'expires_at' => 'date',
        ]);
        if ($data['type'] === 'percent' && Money::cmp($data['value'], '100') > 0) {
            throw new ValidationException('Percentage bonus cannot exceed 100%.');
        }
        $code = strtoupper($data['code']);
        if ($db->fetchColumn('SELECT id FROM coupons WHERE code = ? AND id <> ?', [$code, $id])) {
            throw new ValidationException('That code already exists.');
        }
        $toUtc = static fn (?string $d) => $d ? (new \DateTimeImmutable($d, display_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
        $row = [
            'code' => $code,
            'type' => $data['type'],
            'value' => Money::of($data['value'], 4),
            'min_deposit' => Money::of($data['min_deposit'] ?: '0', 4),
            'max_discount' => $data['max_discount'] !== '' && $data['max_discount'] !== null ? Money::of($data['max_discount'], 4) : null,
            'usage_limit' => $data['usage_limit'] ? (int) $data['usage_limit'] : null,
            'per_user_limit' => (int) ($data['per_user_limit'] ?: 1),
            'starts_at' => $toUtc($data['starts_at'] ?: null),
            'expires_at' => $toUtc($data['expires_at'] ?: null),
            'status' => $request->str('status') === 'disabled' ? 'disabled' : 'active',
            'updated_at' => now(),
        ];
        if ($id) {
            $db->update('coupons', $row, ['id' => $id]);
        } else {
            $id = $db->insert('coupons', $row + ['created_at' => now()]);
        }
        AuditService::log('coupon.save', 'coupon', $id, $row);
        $this->success('Promo code saved.');
        return Response::redirect(admin_url('coupons'));
    }

    public function deleteCoupon(Request $request, int $id): Response
    {
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM coupon_usage WHERE coupon_id = ? LIMIT 1', [$id])) {
            $db->update('coupons', ['status' => 'disabled'], ['id' => $id]);
            $this->success('Code has been used, so it was disabled instead of deleted.');
        } else {
            $db->delete('coupons', ['id' => $id]);
            $this->success('Promo code deleted.');
        }
        AuditService::log('coupon.delete', 'coupon', $id);
        return Response::redirect(admin_url('coupons'));
    }

    public function affiliates(Request $request): Response
    {
        $db = Database::instance();
        $top = $db->fetchAll(
            "SELECT u.id, u.username, COUNT(DISTINCT r.id) AS referrals, COALESCE(SUM(rt.commission),0) AS earned, w.referral_balance
             FROM users u JOIN referrals r ON r.referrer_id = u.id AND r.status = 'active' JOIN wallets w ON w.user_id = u.id
             LEFT JOIN referral_transactions rt ON rt.referrer_id = u.id
             GROUP BY u.id ORDER BY earned DESC, referrals DESC LIMIT 20"
        );
        $refs = Paginator::query(
            'r.*, a.username AS referrer, b.username AS referred',
            'FROM referrals r JOIN users a ON a.id = r.referrer_id JOIN users b ON b.id = r.referred_id',
            [],
            'r.id DESC',
            $this->pageNum($request),
            30
        );
        $totals = $db->fetch('SELECT COALESCE(SUM(commission),0) paid, COUNT(*) n FROM referral_transactions');
        return $this->view('admin/marketing/affiliates', ['title' => 'Affiliates', 'top' => $top, 'refs' => $refs, 'totals' => $totals]);
    }

    public function blockReferral(Request $request, int $id): Response
    {
        $db = Database::instance();
        $r = $db->fetch('SELECT * FROM referrals WHERE id = ?', [$id]);
        if (!$r) {
            $this->notFound();
        }
        $new = $r['status'] === 'active' ? 'blocked' : 'active';
        $db->update('referrals', ['status' => $new], ['id' => $id]);
        $db->update('users', ['referred_by' => $new === 'active' ? $r['referrer_id'] : null], ['id' => $r['referred_id']]);
        AuditService::log('referral.' . $new, 'referral', $id);
        $this->success('Referral ' . $new . '. Future deposits ' . ($new === 'active' ? 'will' : 'will not') . ' earn commission.');
        return $this->back($request, admin_url('affiliates'));
    }

    public function notifications(Request $request): Response
    {
        $db = Database::instance();
        return $this->view('admin/marketing/notifications', [
            'title' => 'Notifications',
            'announcements' => $db->fetchAll('SELECT * FROM announcements ORDER BY id DESC'),
            'recent' => $db->fetchAll('SELECT n.*, u.username FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.type = ? ORDER BY n.id DESC LIMIT 15', ['admin']),
        ]);
    }

    public function saveAnnouncement(Request $request): Response
    {
        $db = Database::instance();
        $data = Validator::check($request->post(), ['title' => 'required|max:200', 'body' => 'required|max:5000', 'level' => 'required|in:info,success,warning,danger']);
        $toUtc = static fn (string $d) => $d !== '' ? (new \DateTimeImmutable($d, display_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
        $row = [
            'title' => $data['title'],
            'body' => HtmlSanitizer::clean($data['body']),
            'level' => $data['level'],
            'status' => $request->str('status') === 'hidden' ? 'hidden' : 'active',
            'starts_at' => $toUtc($request->str('starts_at')),
            'ends_at' => $toUtc($request->str('ends_at')),
        ];
        $id = $request->int('id');
        if ($id) {
            $db->update('announcements', $row, ['id' => $id]);
        } else {
            $id = $db->insert('announcements', $row + ['created_at' => now()]);
        }
        AuditService::log('announcement.save', 'announcement', $id);
        $this->success('Announcement saved.');
        return Response::redirect(admin_url('notifications'));
    }

    public function deleteAnnouncement(Request $request, int $id): Response
    {
        Database::instance()->delete('announcements', ['id' => $id]);
        $this->success('Announcement deleted.');
        return Response::redirect(admin_url('notifications'));
    }

    /** Send an in-panel notification (optionally emailed) to one user or all active users. */
    public function sendNotification(Request $request): Response
    {
        $data = Validator::check($request->post(), ['title' => 'required|max:200', 'body' => 'max:1000']);
        $db = Database::instance();
        $target = $request->str('target');
        if ($target === 'all') {
            $ids = array_column($db->fetchAll("SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL"), 'id');
        } else {
            $u = $db->fetch('SELECT id FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL', [$request->str('user'), strtolower($request->str('user'))]);
            if (!$u) {
                throw new ValidationException('User not found.');
            }
            $ids = [$u['id']];
        }
        $email = $request->bool('email');
        foreach ($ids as $uid) {
            $db->insert('notifications', ['user_id' => $uid, 'type' => 'admin', 'title' => $data['title'], 'body' => $data['body'] ?: null, 'created_at' => now()]);
            if ($email) {
                $addr = $db->fetchColumn('SELECT email FROM users WHERE id = ?', [$uid]);
                \App\Services\MailService::queue((string) $addr, $data['title'], '<p>' . nl2br(e($data['body'] ?? '')) . '</p>');
            }
        }
        AuditService::log('notification.send', 'users', $target === 'all' ? 'all' : (string) $ids[0], ['count' => count($ids), 'email' => $email]);
        $this->success('Notification sent to ' . count($ids) . ' user(s)' . ($email ? ' (emails queued)' : '') . '.');
        return Response::redirect(admin_url('notifications'));
    }
}
