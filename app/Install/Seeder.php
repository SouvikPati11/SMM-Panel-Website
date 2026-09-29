<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Database;
use App\Services\SettingsService;

/** Initial data: permissions, roles, settings, legal pages, FAQ, payment methods. Idempotent. */
final class Seeder
{
    public const PERMISSIONS = [
        'dashboard.view' => ['Dashboard', 'View dashboard & statistics'],
        'users.view' => ['Users', 'View users'],
        'users.manage' => ['Users', 'Create, edit, suspend and delete users'],
        'users.balance' => ['Users', 'Adjust user balances'],
        'orders.view' => ['Orders', 'View orders'],
        'orders.manage' => ['Orders', 'Change order status, refund, resolve'],
        'services.manage' => ['Catalog', 'Manage services and categories'],
        'providers.manage' => ['Catalog', 'Manage providers and synchronisation'],
        'payments.view' => ['Finance', 'View payments'],
        'payments.manage' => ['Finance', 'Approve/reject manual payments'],
        'gateways.manage' => ['Finance', 'Configure payment gateways and methods'],
        'transactions.view' => ['Finance', 'View wallet transactions'],
        'coupons.manage' => ['Marketing', 'Manage promo codes'],
        'affiliates.manage' => ['Marketing', 'View and manage affiliates'],
        'notifications.manage' => ['Marketing', 'Announcements and notifications'],
        'tickets.manage' => ['Support', 'View and reply to tickets'],
        'content.manage' => ['Content', 'Pages, FAQ and blog'],
        'seo.manage' => ['Content', 'SEO settings'],
        'settings.manage' => ['System', 'Site, email and currency settings'],
        'logs.view' => ['System', 'View logs and audit trail'],
        'admins.manage' => ['System', 'Manage administrators and roles'],
        'system.manage' => ['System', 'System health and cron tasks'],
    ];

    public static function run(): void
    {
        $db = Database::instance();
        $now = now();

        foreach (self::PERMISSIONS as $name => [$grp, $label]) {
            $db->query('INSERT INTO permissions (name, label, grp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label), grp = VALUES(grp)', [$name, $label, $grp]);
        }
        $roles = [
            'Administrator' => ['Full access', array_keys(self::PERMISSIONS)],
            'Support' => ['Support agents', ['dashboard.view', 'users.view', 'orders.view', 'tickets.manage', 'payments.view']],
            'Finance' => ['Payments & balances', ['dashboard.view', 'users.view', 'users.balance', 'payments.view', 'payments.manage', 'transactions.view', 'coupons.manage', 'affiliates.manage']],
            'Content Editor' => ['Blog, pages & SEO', ['dashboard.view', 'content.manage', 'seo.manage', 'notifications.manage']],
        ];
        foreach ($roles as $name => [$desc, $perms]) {
            $roleId = (int) $db->fetchColumn('SELECT id FROM roles WHERE name = ?', [$name]);
            if (!$roleId) {
                $roleId = $db->insert('roles', ['name' => $name, 'description' => $desc, 'created_at' => $now]);
                foreach ($perms as $p) {
                    $db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$roleId, $p]);
                }
            }
        }

        foreach (SettingsService::DEFAULTS as $k => $v) {
            $db->query('INSERT IGNORE INTO settings (`key`, `value`, is_secret, updated_at) VALUES (?, ?, 0, ?)', [$k, $v, $now]);
        }

        if (!(int) $db->fetchColumn('SELECT COUNT(*) FROM price_levels')) {
            foreach ([['Standard', '0'], ['Reseller', '5'], ['VIP', '10']] as [$n, $d]) {
                $db->insert('price_levels', ['name' => $n, 'discount_percent' => $d, 'created_at' => $now]);
            }
        }

        foreach (self::pages() as $slug => [$title, $content, $footer]) {
            $db->query(
                "INSERT IGNORE INTO pages (slug, title, content, status, is_system, show_in_footer, created_at, updated_at) VALUES (?, ?, ?, 'published', 1, ?, ?, ?)",
                [$slug, $title, $content, $footer, $now, $now]
            );
        }

        if (!(int) $db->fetchColumn('SELECT COUNT(*) FROM faqs')) {
            foreach (self::faqs() as $i => [$q, $a]) {
                $db->insert('faqs', ['question' => $q, 'answer' => $a, 'sort_order' => $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $methods = [
            ['oxapay', 'Crypto (OxaPay)', 'Pay with USDT, BTC, ETH, TRX and 20+ cryptocurrencies. Your balance is credited automatically after network confirmation.'],
            ['cryptomus', 'Crypto (Cryptomus)', 'Pay with popular cryptocurrencies via Cryptomus. Credited automatically after confirmation.'],
            ['p2gateway', 'UPI / Cards (P2Gateway)', 'Placeholder — requires implementation from official P2Gateway.in API documentation.'],
        ];
        foreach ($methods as $i => [$gw, $name, $instr]) {
            if (!$db->fetchColumn('SELECT id FROM payment_methods WHERE gateway = ?', [$gw])) {
                $db->insert('payment_methods', ['gateway' => $gw, 'name' => $name, 'instructions' => $instr, 'min_amount' => '1', 'max_amount' => '10000', 'status' => 'disabled', 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if (!$db->fetchColumn("SELECT id FROM payment_methods WHERE gateway = 'manual'")) {
            $db->insert('payment_methods', [
                'gateway' => 'manual', 'name' => 'UPI (manual)',
                'instructions' => "1. Send the exact amount to the UPI ID below.\n2. Copy the UTR / transaction reference from your UPI app.\n3. Submit it here with a screenshot. Funds are added after verification (usually within 1 hour).",
                'account' => 'yourname@upi', 'min_amount' => '1', 'max_amount' => '5000', 'require_proof' => 1,
                'status' => 'disabled', 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private static function pages(): array
    {
        $note = '<p><em>Administrators: edit this page in Admin → Pages. This default text is a starting template and is not legal advice.</em></p>';
        return [
            'about' => ['About Us', '<h2>Who we are</h2><p>We provide social media marketing services for creators, agencies and resellers, with a transparent catalog, instant ordering and a reseller API.</p><h2>What we value</h2><ul><li>Clear pricing per 1,000 units</li><li>Automated delivery and status tracking</li><li>Responsive human support</li></ul>' . $note, 1],
            'terms' => ['Terms of Service', '<p>By creating an account or placing an order you agree to these terms.</p><h2>1. Services</h2><p>Services are delivered as described in the catalog. Delivery times are estimates.</p><h2>2. Your responsibilities</h2><p>You must only submit links you own or are authorised to promote, and must comply with the rules of each social platform and applicable law.</p><h2>3. Payments</h2><p>Deposits are added to your account balance and used for orders.</p><h2>4. Changes</h2><p>We may update services, prices and these terms. Continued use means acceptance of the updated terms.</p>' . $note, 1],
            'privacy' => ['Privacy Policy', '<p>This policy explains what data we collect and how we use it.</p><h2>Data we collect</h2><ul><li>Account data: username, email, hashed password</li><li>Order data: links and quantities you submit</li><li>Technical data: IP address and browser for security and fraud prevention</li></ul><h2>How we use it</h2><p>To provide the service, process payments, prevent abuse and support you. We do not sell personal data.</p><h2>Your rights</h2><p>Contact support to access or delete your data.</p>' . $note, 1],
            'refund-policy' => ['Refund Policy', '<h2>Automatic refunds</h2><p>If an order fails, is cancelled or is only partially delivered, the undelivered amount is automatically returned to your account balance.</p><h2>Deposits</h2><p>Funds added to your balance are normally non-refundable to the original payment method. Contact support for exceptional cases.</p><h2>Refills</h2><p>Services marked with refill may be refilled within the stated refill period.</p>' . $note, 1],
        ];
    }

    private static function faqs(): array
    {
        return [
            ['What is an SMM panel?', 'An SMM panel is a platform where you can buy social media marketing services — such as followers, likes, views and comments — for your own accounts or your clients\', at wholesale prices.'],
            ['How do I place an order?', 'Create an account, add funds to your balance, open "New Order", choose a category and service, paste your link, enter the quantity and submit. The price is calculated before you confirm.'],
            ['How fast will my order start?', 'Most services start within minutes. The estimated start and speed are shown in each service description.'],
            ['What happens if an order is only partially delivered?', 'Any undelivered quantity is refunded to your balance automatically when the order is marked Partial.'],
            ['Which payment methods do you accept?', 'Available methods are shown on the Add Funds page and may include cryptocurrency and manual methods such as UPI or bank transfer.'],
            ['Do you have an API for resellers?', 'Yes. Generate an API key in your account and see the API documentation. The API follows the standard SMM panel v2 format, so it works with most panel scripts.'],
            ['Is my account secure?', 'Passwords are hashed, sessions are protected and you can enable two-factor authentication in Security settings.'],
        ];
    }
}
