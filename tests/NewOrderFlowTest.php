<?php

declare(strict_types=1);

use App\Core\Database;
use App\Helpers\Platforms;

/*
 * New order page: platform shortcuts/icons, server-side quote for the
 * confirmation dialog, JSON confirm with a success state, no double submission.
 */

$nfProvider = Fx::provider();
$nfCats = [];
foreach (['Instagram Followers', 'TikTok Views', 'YouTube Subscribers', 'Telegram Members'] as $i => $name) {
    $nfCats[$name] = Database::instance()->insert('categories', ['name' => $name, 'slug' => 'nf-cat-' . $i, 'status' => 'active', 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
}
$nfService = Fx::service($nfProvider, ['name' => 'IG Followers [Fast]', 'category_id' => $nfCats['Instagram Followers'], 'average_time' => '2 hours', 'refill' => 1]);
Fx::service($nfProvider, ['name' => 'TikTok Views', 'category_id' => $nfCats['TikTok Views']]);
$nfJson = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

T::test('Platforms: categories map to platforms by name (no false positives on common words)', function () {
    foreach ([
        'Instagram Followers' => 'instagram', 'IG Likes [Real]' => 'instagram', 'Facebook Page Likes' => 'facebook', 'TikTok Views' => 'tiktok',
        'YouTube Subscribers' => 'youtube', 'YouTube Shorts Views' => 'youtube', 'Telegram Members' => 'telegram', 'Twitter Followers' => 'x', 'X Followers' => 'x',
        'Spotify Plays' => 'spotify', 'Website Traffic' => 'website', 'Twitch Followers' => 'twitch', 'LinkedIn Connections' => 'linkedin',
        'VK Friends' => 'vk', 'Vkontakte Likes' => 'vk', 'Threads Followers' => 'threads',
        'Extra Services' => 'other', 'Express Delivery' => 'other', 'Mixed Package' => 'other',
    ] as $name => $platform) {
        T::eq($platform, Platforms::detect($name), $name);
    }
    T::true(str_contains(Platforms::icon('tiktok'), '<svg') && str_contains(Platforms::icon('unknown'), '<svg'));
});

T::test('Order page: 2-column platform card grid (All … Other) filters by the configured category platform', function () use ($nfCats) {
    $u = Fx::user('10');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    // Every card, in order, each with an icon and a name; cards without services are disabled, not hidden.
    preg_match_all('/<button class="pf-card[^"]*" type="button" data-platform="([a-z]*)"/', $html, $m);
    T::eq(['', 'instagram', 'tiktok', 'youtube', 'facebook', 'x', 'telegram', 'spotify', 'threads', 'vk', 'twitch', 'other'], $m[1]);
    T::true((bool) preg_match('/data-platform="spotify"[^>]*disabled/', $html), 'no services → disabled card');
    T::true(!preg_match('/data-platform="instagram"[^>]*disabled/', $html));
    T::true(str_contains($html, 'id="platform-search"') && str_contains($html, 'class="pf-grid"'), 'searchable grid');
    T::true(str_contains($html, 'id="order-confirm"') && str_contains($html, 'id="confirm-submit"'), 'confirmation dialog present');
    T::true(str_contains($html, 'platform-icon'));
    // "Before you order" is gone (and its sidebar with it).
    T::true(!str_contains($html, 'Before you order') && !str_contains($html, 'sticky-side'));
    // Category options carry their card group; the category platform set by the admin wins over the name.
    T::true(str_contains($html, 'data-platform="tiktok" data-group="tiktok"'));
    Database::instance()->update('categories', ['platform' => 'vk'], ['id' => $nfCats['TikTok Views']]);
    $html = http('GET', '/order')->body();
    T::true(str_contains($html, 'data-platform="vk" data-group="vk"'), 'admin-configured platform used');
    T::true(!preg_match('/data-platform="vk"[^>]*disabled/', $html));
    // Platforms without their own card are grouped under Other.
    Database::instance()->update('categories', ['platform' => 'discord'], ['id' => $nfCats['TikTok Views']]);
    T::true(str_contains(http('GET', '/order')->body(), 'data-platform="discord" data-group="other"'));
    Database::instance()->update('categories', ['platform' => null], ['id' => $nfCats['TikTok Views']]);
});

T::test('Admin categories: platform is configurable and validated (unknown values fall back to auto-detect)', function () use ($nfCats) {
    $db = Database::instance();
    $admin = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'nfadmin', 'email' => 'nfadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($admin);
    $id = $nfCats['Telegram Members'];
    http('POST', '/' . admin_path() . '/categories/save', ['_token' => csrf(), 'id' => $id, 'name' => 'Telegram Members', 'slug' => 'nf-cat-3', 'sort_order' => '3', 'status' => 'active', 'platform' => 'threads']);
    T::eq('threads', $db->fetchColumn('SELECT platform FROM categories WHERE id = ?', [$id]));
    http('POST', '/' . admin_path() . '/categories/save', ['_token' => csrf(), 'id' => $id, 'name' => 'Telegram Members', 'slug' => 'nf-cat-3', 'sort_order' => '3', 'status' => 'active', 'platform' => '<script>']);
    T::eq(null, $db->fetchColumn('SELECT platform FROM categories WHERE id = ?', [$id]));
    T::eq('telegram', Platforms::forCategory(['name' => 'Telegram Members', 'platform' => null]));
    $html = http('GET', '/' . admin_path() . '/categories')->body();
    T::true(str_contains($html, 'name="platform"') && str_contains($html, '(auto)'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Quote: server computes the price (client values ignored) and charges nothing', function () use ($nfService, $nfJson) {
    $u = Fx::user('10', ['custom_discount' => '20.00']);
    login_as_user($u);
    $r = http('POST', '/order/quote', ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/nf', 'quantity' => '1000', 'charge' => '0.01', 'rate' => '0'], $nfJson);
    T::eq(200, $r->status());
    $q = json_decode($r->body(), true);
    T::eq(['$2.00', false], [$q['charge'], $q['insufficient']], '2.50 − 20% = 2.00');
    T::eq('IG Followers [Fast]', $q['service']['name']);
    T::eq(['2 hours', true], [$q['service']['average_time'], $q['service']['refill']]);
    T::eq('10.000000', Fx::balance((int) $u['id']));
    T::eq(0, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$u['id']]));
});

T::test('Quote: validation errors come back as JSON 422; low balance is flagged, not hidden', function () use ($nfService, $nfJson) {
    $u = Fx::user('1');
    login_as_user($u);
    $bad = http('POST', '/order/quote', ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/nf', 'quantity' => '5'], $nfJson);
    T::eq(422, $bad->status());
    T::true(str_contains((string) json_decode($bad->body(), true)['error'], 'Enter a quantity between'));
    $low = json_decode(http('POST', '/order/quote', ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/nf', 'quantity' => '1000'], $nfJson)->body(), true);
    T::eq(true, $low['insufficient']);
    T::eq(422, http('POST', '/order/quote', ['_token' => csrf(), 'service' => '999999', 'link' => 'https://x.com', 'quantity' => '100'], $nfJson)->status());
});

T::test('Confirm: JSON submit returns the order id; double submission places one order', function () use ($nfService, $nfJson) {
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['order' => 777001])]);
    $u = Fx::user('10');
    login_as_user($u);
    $form = ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/confirm', 'quantity' => '1000', 'form_key' => 'confirm-key-1'];
    $r = json_decode(http('POST', '/order', $form, $nfJson)->body(), true);
    T::eq([true, 'order'], [$r['ok'], $r['type']]);
    T::true($r['order_id'] > 0 && str_contains($r['url'], '/orders/' . $r['order_id']));
    T::true(strlen((string) $r['form_key']) === 32, 'fresh idempotency key for the next order');
    $again = json_decode(http('POST', '/order', $form, $nfJson)->body(), true);
    T::eq($r['order_id'], $again['order_id']);
    T::true(str_contains($again['message'], 'already placed'));
    T::eq(1, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$u['id']]));
    T::eq('7.500000', Fx::balance((int) $u['id']));
    // Without JavaScript the classic form post still works (redirect + flash).
    $plain = http('POST', '/order', ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/nojs', 'quantity' => '1000', 'form_key' => 'confirm-key-2']);
    T::eq(302, $plain->status());
});

T::test('Confirm: errors (e.g. insufficient balance) return JSON 422 and charge nothing', function () use ($nfService, $nfJson) {
    $u = Fx::user('1');
    login_as_user($u);
    $r = http('POST', '/order', ['_token' => csrf(), 'service' => (string) $nfService, 'link' => 'https://instagram.com/poor', 'quantity' => '1000', 'form_key' => 'poor-1'], $nfJson);
    T::eq(422, $r->status());
    T::true(str_contains((string) json_decode($r->body(), true)['error'], 'Insufficient balance'));
    T::eq('1.000000', Fx::balance((int) $u['id']));
});

T::test('Order page copy: plain customer wording, no technical jargon; friendly validation messages', function () use ($nfService, $nfJson) {
    $u = Fx::user('100');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    foreach (['Choose a platform', 'Search all services', 'Per 1,000', 'About this service', 'How do you want to order?', 'One-time order', 'Repeat automatically',
        'Make sure the account or post is public', 'Deliver gradually (drip-feed)', 'Nothing is charged until you confirm', 'Check your order', 'Place order', 'Go back',
        'Number of new posts', 'Amount per post', 'Start after', 'Stop on'] as $text) {
        T::true(str_contains($html, e($text)) || str_contains($html, $text), 'shows: ' . $text);
    }
    foreach (['server-calculated', 'provider', 'idempotency', 'Drip-feed (deliver in runs)', 'Estimated charge', 'Quantity per post', 'Confirm your order'] as $jargon) {
        T::true(!str_contains(strtolower(strip_tags(preg_replace('#<script.*?</script>#s', '', $html))), strtolower($jargon)), 'no jargon: ' . $jargon);
    }
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/app.js');
    foreach (['Check your subscription', 'Total to pay', 'Held from your balance now (maximum)', 'No services match your search'] as $text) {
        T::true(str_contains($js, $text), 'script wording: ' . $text);
    }
    $q = fn (array $f) => (string) (json_decode(http('POST', '/order/quote', $f + ['_token' => csrf(), 'service' => (string) $nfService], $nfJson)->body(), true)['error'] ?? '');
    T::true(str_contains($q(['link' => 'not a link', 'quantity' => '1000']), 'Enter the full link, starting with https://'));
    T::true(str_contains($q(['link' => 'https://instagram.com/nf', 'quantity' => 'lots']), 'whole number, for example 1000'));
    T::true(str_contains($q(['link' => 'https://instagram.com/nf', 'quantity' => '5']), 'Enter a quantity between'));
    $bad = json_decode(http('POST', '/order/quote', ['_token' => csrf(), 'service' => '999999', 'link' => 'https://x.com', 'quantity' => '100'], $nfJson)->body(), true);
    T::true(str_contains((string) $bad['error'], 'not available right now'));
    App\Services\Auth::logoutUser();
});
