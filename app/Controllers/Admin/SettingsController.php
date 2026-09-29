<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Mailer;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Services\UploadService;

final class SettingsController extends Controller
{
    /** Tab => [key => rule]. Only these keys can be written through this form. */
    private const TABS = [
        'general' => [
            'site_name' => 'required|max:100', 'site_tagline' => 'max:200', 'timezone' => 'required|max:64',
            'maintenance_mode' => 'boolean', 'maintenance_message' => 'max:500', 'footer_text' => 'max:500', 'blog_enabled' => 'boolean',
        ],
        'contact' => [
            'contact_email' => 'email', 'contact_telegram' => 'max:100', 'contact_whatsapp' => 'max:50', 'contact_address' => 'max:300',
            'social_facebook' => 'url', 'social_instagram' => 'url', 'social_x' => 'url', 'social_youtube' => 'url', 'social_telegram' => 'url',
        ],
        'currency' => [
            'currency_code' => 'required|regex:/^[A-Z]{3}$/', 'currency_symbol' => 'required|max:5', 'currency_position' => 'required|in:before,after', 'currency_decimals' => 'required|in:0,2,3,4',
        ],
        'users' => [
            'registration_enabled' => 'boolean', 'email_verification' => 'boolean', 'login_max_attempts' => 'required|integer|min:3|max:50',
            'login_lockout_minutes' => 'required|integer|min:1|max:1440', 'default_price_level' => 'integer',
        ],
        'orders' => [
            'min_order_amount' => 'decimal|min:0', 'mass_order_enabled' => 'boolean', 'mass_order_max_lines' => 'required|integer|min:1|max:500',
            'order_cancel_enabled' => 'boolean', 'refill_enabled' => 'boolean', 'order_sync_batch' => 'required|integer|min:10|max:1000',
        ],
        'funds' => [
            'min_deposit' => 'required|decimal|min:0', 'max_deposit' => 'required|decimal|min:1', 'payment_expiry_minutes' => 'required|integer|min:10|max:2880',
        ],
        'referral' => [
            'referral_enabled' => 'boolean', 'referral_percent' => 'required|decimal|min:0|max:50', 'referral_min_withdrawal' => 'required|decimal|min:0', 'referral_same_ip_block' => 'boolean',
        ],
        'api' => [
            'api_enabled' => 'boolean', 'api_rate_limit' => 'required|integer|min:1|max:10000', 'api_rate_window' => 'required|integer|min:1|max:3600',
        ],
        'tickets' => [
            'ticket_attachments' => 'boolean', 'ticket_max_open' => 'required|integer|min:1|max:100',
        ],
    ];

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->str('tab'), self::TABS) ? $request->str('tab') : 'general';
        return $this->view('admin/settings/index', [
            'title' => 'Settings',
            'tab' => $tab,
            'levels' => Database::instance()->fetchPairs('SELECT id, name FROM price_levels ORDER BY discount_percent'),
        ]);
    }

    public function save(Request $request): Response
    {
        $tab = array_key_exists($request->str('tab'), self::TABS) ? $request->str('tab') : 'general';
        $rules = self::TABS[$tab];
        $input = [];
        foreach ($rules as $key => $rule) {
            $v = $request->post()[$key] ?? '';
            $input[$key] = is_string($v) ? trim($v) : '';
            if (str_contains($rule, 'boolean')) {
                $input[$key] = $request->bool($key) ? '1' : '0';
            }
        }
        Validator::check($input, $rules);
        if ($tab === 'general' && !in_array($input['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException('Select a valid timezone.');
        }
        if ($tab === 'funds' && Money::cmp($input['max_deposit'], $input['min_deposit']) < 0) {
            throw new ValidationException('Maximum deposit must be greater than the minimum.');
        }
        if ($tab === 'general') {
            foreach (['site_logo' => 'logo', 'site_favicon' => 'favicon'] as $key => $field) {
                if ($file = $request->file($field)) {
                    UploadService::deletePublic((string) setting($key));
                    $input[$key] = UploadService::storePublicImage($file, 'branding');
                } elseif ($request->bool('remove_' . $field)) {
                    UploadService::deletePublic((string) setting($key));
                    $input[$key] = '';
                }
            }
        }
        $changed = [];
        foreach ($input as $k => $v) {
            if ((string) setting($k) !== $v) {
                $changed[$k] = ['from' => setting($k), 'to' => $v];
            }
        }
        SettingsService::setMany($input);
        AuditService::log('settings.' . $tab, 'settings', $tab, $changed);
        $this->success('Settings saved.');
        return Response::redirect(admin_url('settings?tab=' . $tab));
    }

    public function email(Request $request): Response
    {
        $db = Database::instance();
        return $this->view('admin/settings/email', [
            'title' => 'Email settings',
            'queue' => $db->fetchPairs('SELECT status, COUNT(*) FROM email_queue GROUP BY status'),
            'failed' => $db->fetchAll("SELECT to_email, subject, last_error, created_at FROM email_queue WHERE status = 'failed' OR (status = 'queued' AND attempts > 0) ORDER BY id DESC LIMIT 10"),
            'hasPassword' => (string) setting('mail_password') !== '',
        ]);
    }

    public function saveEmail(Request $request): Response
    {
        $data = Validator::check($request->post(), [
            'mail_driver' => 'required|in:smtp,mail,log',
            'mail_host' => 'max:190', 'mail_port' => 'integer|min:1|max:65535', 'mail_encryption' => 'in:tls,ssl,none',
            'mail_username' => 'max:190', 'mail_from' => 'required|email', 'mail_from_name' => 'max:100', 'admin_notify_email' => 'email',
        ]);
        foreach (['email_notify_orders', 'email_notify_payments', 'email_notify_tickets'] as $k) {
            $data[$k] = $request->bool($k) ? '1' : '0';
        }
        $pw = (string) $request->input('mail_password', '');
        if ($pw !== '') {
            $data['mail_password'] = $pw;
        }
        SettingsService::setMany(array_map('strval', $data));
        AuditService::log('settings.email', 'settings', 'email', ['driver' => $data['mail_driver'], 'host' => $data['mail_host'], 'password_changed' => $pw !== '']);
        $this->success('Email settings saved.');
        return Response::redirect(admin_url('settings/email'));
    }

    public function testEmail(Request $request): Response
    {
        $to = $request->str('to') ?: (string) $this->admin()['email'];
        try {
            (new Mailer(SettingsService::mailConfig()))->send($to, 'Test email from ' . site_name(), \App\Services\MailService::wrap('Test email', '<p>Your email settings work. Sent ' . gmdate('Y-m-d H:i:s') . ' UTC.</p>'));
            $this->success('Test email sent to ' . $to . '.');
        } catch (\Throwable $e) {
            $this->error('Sending failed: ' . $e->getMessage());
        }
        return Response::redirect(admin_url('settings/email'));
    }

    public function seo(Request $request): Response
    {
        return $this->view('admin/settings/seo', ['title' => 'SEO']);
    }

    public function saveSeo(Request $request): Response
    {
        $data = Validator::check($request->post(), [
            'seo_title' => 'required|max:200', 'seo_description' => 'max:320', 'seo_keywords' => 'max:300', 'seo_robots_extra' => 'max:2000',
            'seo_google_verification' => 'regex:/^[A-Za-z0-9_\-]{0,100}$/', 'seo_bing_verification' => 'regex:/^[A-Za-z0-9_\-]{0,100}$/',
        ]);
        if ($file = $request->file('og_image')) {
            UploadService::deletePublic((string) setting('seo_og_image'));
            $data['seo_og_image'] = UploadService::storePublicImage($file, 'branding');
        }
        SettingsService::setMany(array_map(static fn ($v) => (string) $v, $data));
        AuditService::log('settings.seo', 'settings', 'seo');
        $this->success('SEO settings saved.');
        return Response::redirect(admin_url('seo'));
    }

    public function levels(Request $request): Response
    {
        return $this->view('admin/settings/levels', ['title' => 'Price levels', 'levels' => Database::instance()->fetchAll('SELECT l.*, (SELECT COUNT(*) FROM users u WHERE u.price_level_id = l.id) AS users FROM price_levels l ORDER BY discount_percent')]);
    }

    public function saveLevel(Request $request): Response
    {
        $data = Validator::check($request->post(), ['name' => 'required|max:60', 'discount_percent' => 'required|decimal|min:0|max:100']);
        $db = Database::instance();
        $row = ['name' => $data['name'], 'discount_percent' => Money::of($data['discount_percent'], 2)];
        $id = $request->int('id');
        $id ? $db->update('price_levels', $row, ['id' => $id]) : $db->insert('price_levels', $row + ['created_at' => now()]);
        AuditService::log('price_level.save', 'price_level', $id ?: null, $row);
        $this->success('Price level saved.');
        return Response::redirect(admin_url('price-levels'));
    }

    public function deleteLevel(Request $request, int $id): Response
    {
        Database::instance()->delete('price_levels', ['id' => $id]); // users fall back to no level (FK SET NULL)
        AuditService::log('price_level.delete', 'price_level', $id);
        $this->success('Price level deleted.');
        return Response::redirect(admin_url('price-levels'));
    }
}
