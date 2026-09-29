<?php

declare(strict_types=1);

/*
 * DEVELOPMENT ONLY: fills the database configured in .env with a sample catalog
 * and a demo user so the UI can be previewed. Never run on production.
 *   php tests/dev-seed.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\WalletService;

$db = Database::instance();
$now = now();
$cats = ['Instagram Followers', 'Instagram Likes', 'TikTok Views', 'YouTube Views', 'Telegram Members'];
foreach ($cats as $i => $name) {
    if (!$db->fetchColumn('SELECT id FROM categories WHERE name = ?', [$name])) {
        $db->insert('categories', ['name' => $name, 'slug' => slugify($name), 'sort_order' => $i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    }
}
$catIds = $db->fetchPairs('SELECT name, id FROM categories');
$svc = [
    ['Instagram Followers', 'Instagram Followers — High Quality | 30 Day Refill', '2.1500', 50, 50000, 1, 1, 0, 'default', '0-2 hours'],
    ['Instagram Followers', 'Instagram Followers — Real Mixed | No Refill', '0.9800', 100, 20000, 0, 1, 0, 'default', '0-6 hours'],
    ['Instagram Likes', 'Instagram Likes — Instant', '0.3200', 20, 100000, 0, 0, 1, 'default', 'Instant'],
    ['Instagram Likes', 'Instagram Custom Comments', '12.0000', 5, 500, 0, 0, 0, 'custom_comments', '1-12 hours'],
    ['TikTok Views', 'TikTok Views — Fast', '0.0450', 100, 10000000, 0, 1, 1, 'default', '0-1 hour'],
    ['YouTube Views', 'YouTube Views — Retention 60s | Lifetime Refill', '1.8000', 500, 1000000, 1, 0, 0, 'default', '1-24 hours'],
    ['Telegram Members', 'Telegram Channel Members Package — 1K', '3.5000', 1, 1, 0, 0, 0, 'package', '0-12 hours'],
];
foreach ($svc as $i => [$cat, $name, $rate, $min, $max, $refill, $cancel, $drip, $type, $time]) {
    if (!$db->fetchColumn('SELECT id FROM services WHERE name = ?', [$name])) {
        $db->insert('services', [
            'category_id' => $catIds[$cat], 'name' => $name, 'description' => "Start: 0-1 hour\nSpeed: 5K/day\nQuality: high\nLink: public profile or post URL",
            'type' => $type, 'rate' => $rate, 'min_quantity' => $min, 'max_quantity' => $max, 'refill' => $refill, 'cancel' => $cancel,
            'dripfeed' => $drip, 'average_time' => $time, 'status' => 'active', 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
if (!$db->fetchColumn("SELECT id FROM users WHERE username = 'demo'")) {
    $id = $db->insert('users', ['username' => 'demo', 'email' => 'demo@example.com', 'password_hash' => password_hash('DemoPass123', PASSWORD_DEFAULT), 'referral_code' => 'demo1234', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    WalletService::createWallet($id);
    WalletService::apply($id, '125.50', 'deposit', 'devseed:' . $id, 'Demo deposit');
}
$db->query("UPDATE payment_methods SET status = 'active' WHERE gateway = 'manual'");
$db->query("INSERT INTO announcements (title, body, level, status, created_at) SELECT 'Welcome to the new panel', 'Faster ordering, automatic refunds and a brand-new API. <a href=\"/api-docs\">Read the docs</a>.', 'info', 'active', ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM announcements)", [$now]);
echo "Dev data seeded. Login: demo / DemoPass123\n";
