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
        'Extra Services' => 'other', 'Express Delivery' => 'other', 'Mixed Package' => 'other',
    ] as $name => $platform) {
        T::eq($platform, Platforms::detect($name), $name);
    }
    T::true(str_contains(Platforms::icon('tiktok'), '<svg') && str_contains(Platforms::icon('unknown'), '<svg'));
});

T::test('Order page: platform shortcuts only for platforms with services; categories carry icons', function () {
    $u = Fx::user('10');
    login_as_user($u);
    $html = http('GET', '/order')->body();
    T::true(str_contains($html, 'data-platform="instagram"') && str_contains($html, 'data-platform="tiktok"'));
    T::true(!str_contains($html, 'class="platform-chip" type="button" data-platform="spotify"'), 'no shortcut without services');
    T::true(str_contains($html, 'id="order-confirm"') && str_contains($html, 'id="confirm-submit"'), 'confirmation dialog present');
    T::true(str_contains($html, 'platform-icon'));
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
    T::true(str_contains((string) json_decode($bad->body(), true)['error'], 'Quantity must be between'));
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
