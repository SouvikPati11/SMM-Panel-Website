<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Helpers\Platforms;
use App\Services\OrderService;

/*
 * Admin → Platforms: ON/OFF per platform. OFF hides the platform's
 * categories/services from customers (shortcuts, pickers, catalog, API list,
 * homepage) and refuses new orders, without touching any stored data.
 */

$pldb = Database::instance();
$plAdmin = (int) $pldb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $pldb->insert('admins', ['username' => 'pladmin', 'email' => 'pladmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$plCat = static function (string $name, ?string $platform) use ($pldb): int {
    return $pldb->insert('categories', ['name' => $name, 'slug' => 'pl-' . bin2hex(random_bytes(4)), 'platform' => $platform, 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
};
$tgCat = $plCat('PL Telegram Members', 'telegram');
$tgAuto = $plCat('PL Telegram Views', null); // auto-detected from the name
$igCat = $plCat('PL Instagram Likes', 'instagram');
$tgSvc = Fx::service(null, ['name' => 'PL TG members svc', 'category_id' => $tgCat]);
$tgAutoSvc = Fx::service(null, ['name' => 'PL TG views svc', 'category_id' => $tgAuto]);
$igSvc = Fx::service(null, ['name' => 'PL IG likes svc', 'category_id' => $igCat]);
$save = static function (array $off = [], array $noCard = [], array $names = []) use ($plAdmin): void {
    login_as_admin($plAdmin);
    $form = ['_token' => csrf()];
    foreach (Platforms::all() as $k => $p) {
        $form['name'][$k] = $names[$k] ?? $p['name'];
        if (!in_array($k, $off, true)) {
            $form['enabled'][$k] = '1';
        }
        if ($p['shortcut'] && !in_array($k, $noCard, true)) {
            $form['shortcut'][$k] = '1';
        }
    }
    http('POST', '/' . admin_path() . '/platforms/save', $form);
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
};
$catalog = static function (): array {
    preg_match('#<script type="application/json" id="services-data">(.*?)</script>#s', http('GET', '/order')->body(), $m);
    return json_decode($m[1] ?? '{}', true) ?: [];
};

T::test('Platforms: every known platform is listed in Admin → Platforms with icon, status and toggles (seeded ON)', function () use ($plAdmin, $pldb) {
    T::eq(count(Platforms::LIST), (int) $pldb->fetchColumn('SELECT COUNT(*) FROM platforms'));
    T::eq(0, (int) $pldb->fetchColumn("SELECT COUNT(*) FROM platforms WHERE status <> 'active'"));
    login_as_admin($plAdmin);
    $html = http('GET', '/' . admin_path() . '/platforms')->body();
    foreach (['instagram', 'telegram', 'tiktok', 'x', 'vk'] as $k) {
        T::true(str_contains($html, 'name="enabled[' . $k . ']" value="1" checked'), $k . ' ON');
        T::true(str_contains($html, 'platform-' . $k), $k . ' icon');
    }
    T::true(str_contains($html, 'name="shortcut[instagram]" value="1" checked') && !str_contains($html, 'name="shortcut[discord]" value="1" checked'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Platforms: OFF hides the card, categories and services from customers and refuses new orders; data untouched', function () use ($save, $catalog, $tgCat, $tgAuto, $igCat, $tgSvc, $tgAutoSvc, $igSvc, $pldb) {
    $before = $pldb->fetchAll('SELECT id, name, platform, status FROM categories ORDER BY id');
    $beforeSvc = $pldb->fetchAll('SELECT id, category_id, status, rate FROM services ORDER BY id');
    $save(['telegram']);
    T::eq('disabled', $pldb->fetchColumn("SELECT status FROM platforms WHERE `key` = 'telegram'"));
    T::eq($before, $pldb->fetchAll('SELECT id, name, platform, status FROM categories ORDER BY id'), 'categories unchanged');
    T::eq($beforeSvc, $pldb->fetchAll('SELECT id, category_id, status, rate FROM services ORDER BY id'), 'services unchanged');

    $u = Fx::user('100');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    T::true(!str_contains($html, 'data-platform="telegram"'), 'no Telegram card or category option');
    T::true(str_contains($html, 'data-platform="instagram"'));
    $cat = $catalog();
    $catIds = array_column($cat['categories'], 'id');
    T::true(!in_array($tgCat, $catIds, true) && !in_array($tgAuto, $catIds, true) && in_array($igCat, $catIds, true), 'stored and auto-detected Telegram categories hidden');
    T::true(!in_array($tgSvc, array_column($cat['services'], 'id'), true) && !in_array($tgAutoSvc, array_column($cat['services'], 'id'), true));
    T::true(!str_contains(http('GET', '/catalog')->body(), 'PL TG members svc'), 'user service list');
    T::true(!str_contains(http('GET', '/services')->body(), 'PL TG members svc') && str_contains(http('GET', '/services')->body(), 'PL IG likes svc'), 'public service list');
    // Server-side: a direct POST / preselect / API order for a hidden service is refused.
    T::throws(ValidationException::class, fn () => OrderService::place((int) $u['id'], $tgSvc, ['link' => 'https://t.me/x', 'quantity' => '1000']), 'not available');
    $r = http('POST', '/order/quote', ['_token' => csrf(), 'service' => (string) $tgSvc, 'link' => 'https://t.me/x', 'quantity' => '1000'], ['HTTP_ACCEPT' => 'application/json']);
    T::eq(422, $r->status());
    $key = App\Services\ApiKeyService::generate((int) $u['id']);
    App\Services\SettingsService::set('api_enabled', '1');
    $list = json_decode(http('POST', '/api/v2', ['key' => $key, 'action' => 'services'])->body(), true);
    T::true(is_array($list) && count($list) > 0, 'API answered');
    T::true(!in_array($tgSvc, array_column($list, 'service'), true) && in_array($igSvc, array_column($list, 'service'), true), 'API list');
    $add = json_decode(http('POST', '/api/v2', ['key' => $key, 'action' => 'add', 'service' => (string) $tgSvc, 'link' => 'https://t.me/x', 'quantity' => '1000'])->body(), true);
    T::true(isset($add['error']) && !isset($add['order']), 'API add refused');
    // Other platforms keep working.
    $o = OrderService::place((int) $u['id'], $igSvc, ['link' => 'https://instagram.com/p/pl', 'quantity' => '1000']);
    T::true($o['id'] > 0);
    App\Services\Auth::logoutUser();
});

T::test('Platforms: disabled platforms cannot be newly assigned to a category; a category that already has one keeps it', function () use ($plAdmin, $tgCat, $pldb) {
    login_as_admin($plAdmin);
    $html = http('GET', '/' . admin_path() . '/categories')->body();
    T::true(!str_contains($html, '<option value="telegram">Telegram</option>'), 'not an active option');
    T::true(str_contains($html, 'Platform off'), 'badge on the affected category');
    $new = $pldb->fetchColumn('SELECT id FROM categories WHERE platform = ? LIMIT 1', ['instagram']);
    http('POST', '/' . admin_path() . '/categories/save', ['_token' => csrf(), 'id' => $new, 'name' => 'PL Instagram Likes', 'slug' => 'pl-ig-x', 'sort_order' => '0', 'status' => 'active', 'platform' => 'telegram']);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'turned off'));
    T::eq('instagram', $pldb->fetchColumn('SELECT platform FROM categories WHERE id = ?', [$new]));
    // Editing the Telegram category itself (e.g. its name) keeps its platform.
    http('POST', '/' . admin_path() . '/categories/save', ['_token' => csrf(), 'id' => $tgCat, 'name' => 'PL Telegram Members (renamed)', 'slug' => 'pl-tg', 'sort_order' => '0', 'status' => 'active', 'platform' => 'telegram']);
    T::eq(['PL Telegram Members (renamed)', 'telegram'], array_values($pldb->fetch('SELECT name, platform FROM categories WHERE id = ?', [$tgCat])));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Platforms: existing subscriptions of a disabled platform keep being delivered; ON again restores everything', function () use ($save, $catalog, $tgCat, $tgSvc, $pldb) {
    $save([]); // Telegram ON
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['order' => 991])]);
    App\Services\SettingsService::set('subscriptions_enabled', '1');
    $pldb->update('services', ['subscription_enabled' => 1], ['id' => $tgSvc]);
    $u = Fx::user('100');
    $sub = App\Services\SubscriptionService::create((int) $u['id'], $tgSvc, ['link' => 'https://t.me/sub', 'quantity' => '1000'], 24, 3, 'pl-sub');
    $save(['telegram']);
    $pldb->query('UPDATE subscriptions SET next_run_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $sub['id']]);
    App\Services\SubscriptionService::processDue();
    T::eq(2, (int) App\Services\SubscriptionService::find((int) $sub['id'])['completed_cycles'], 'already-paid subscription continues');
    $save([]);
    login_as_user($u);
    $cat = $catalog();
    T::true(in_array($tgCat, array_column($cat['categories'], 'id'), true) && in_array($tgSvc, array_column($cat['services'], 'id'), true), 'restored');
    T::true(str_contains(http('GET', '/order')->body(), 'data-platform="telegram"'));
    App\Services\Auth::logoutUser();
});

T::test('Platforms: shortcut card toggle and renaming are reflected on New Order; validation', function () use ($save, $plAdmin, $pldb) {
    $save([], ['tiktok'], ['instagram' => 'Instagram & Reels']);
    $u = Fx::user('10');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    T::true(!str_contains($html, 'class="pf-card pf-card-tiktok"'), 'TikTok has no own card (grouped under Other)');
    T::true(str_contains($html, 'Instagram &amp; Reels'));
    App\Services\Auth::logoutUser();
    login_as_admin($plAdmin);
    http('POST', '/' . admin_path() . '/platforms/save', ['_token' => csrf(), 'name' => ['instagram' => ''], 'enabled' => ['instagram' => '1']]);
    T::true(str_contains(end($_SESSION['_flash'])['message'], 'needs a name'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $save([], [], ['instagram' => 'Instagram']);
    $pldb->query("UPDATE platforms SET shortcut = 1 WHERE `key` = 'tiktok'");
    Platforms::reset();
});
