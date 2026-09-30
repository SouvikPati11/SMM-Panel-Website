<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApiKeyService;
use App\Services\Auth;

/*
 * Admin blog (list, filters, publish/unpublish, scheduling, validation, public
 * display), the user API Access page, and where admins find balance controls.
 */

$cdb = Database::instance();
$cAdmin = (int) $cdb->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $cdb->insert('admins', ['username' => 'contentadmin', 'email' => 'contentadmin@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$cFlash = static fn () => !empty($_SESSION['_flash']) ? (string) end($_SESSION['_flash'])['message'] : '';
$savePost = static function (array $fields) {
    return http('POST', '/' . admin_path() . '/blog/save', $fields + ['_token' => csrf(), 'id' => '0', 'excerpt' => '', 'tags' => '', 'seo_title' => '', 'seo_description' => '', 'category_id' => '', 'published_at' => '']);
};

T::test('Blog admin: create, validate, publish/unpublish, schedule; list filters and counts; public page shows only live posts', function () use ($cdb, $cAdmin, $cFlash, $savePost) {
    Auth::logoutUser();
    login_as_admin($cAdmin);
    $cat = $cdb->insert('blog_categories', ['name' => 'Growth tips', 'slug' => 'growth-tips-' . bin2hex(random_bytes(2)), 'created_at' => now()]);
    $savePost(['title' => 'Bad date post', 'content' => '<p>x</p>', 'status' => 'published', 'published_at' => 'not-a-date']);
    T::true(str_contains($cFlash(), 'valid publish date'), 'invalid date → clear error, not a 500');
    $savePost(['title' => 'Empty post', 'content' => '<script>alert(1)</script>', 'status' => 'draft']);
    T::true(str_contains($cFlash(), 'content is required'), 'script-only content is stripped to nothing');

    $savePost(['title' => 'Live post about growth', 'content' => '<h2>Hello</h2><p>Body <script>alert(1)</script><a href="javascript:alert(1)">x</a></p>', 'status' => 'published', 'category_id' => (string) $cat, 'tags' => 'instagram, tips', 'seo_title' => 'Live SEO title', 'seo_description' => 'Live SEO description']);
    T::eq('Post published.', $cFlash());
    $live = $cdb->fetch("SELECT * FROM blog_posts WHERE title = 'Live post about growth'");
    T::true(!str_contains($live['content'], '<script') && !str_contains($live['content'], 'javascript:'), 'sanitised');
    $future = gmdate('Y-m-d\TH:i', time() + 7 * 86400);
    $savePost(['title' => 'Scheduled post', 'content' => '<p>Later</p>', 'status' => 'published', 'published_at' => $future, 'category_id' => (string) $cat]);
    T::true(str_starts_with($cFlash(), 'Post scheduled for'));
    $savePost(['title' => 'Draft post', 'content' => '<p>Draft</p>', 'status' => 'draft']);
    T::eq('Draft saved.', $cFlash());
    $savePost(['title' => 'Duplicate', 'slug' => $live['slug'], 'content' => '<p>x</p>', 'status' => 'draft']);
    T::true(str_contains($cFlash(), 'already uses the address'), 'slug conflict');

    $list = http('GET', '/' . admin_path() . '/blog')->body();
    T::true(str_contains($list, 'Live post about growth') && str_contains($list, '>Scheduled<') && str_contains($list, '>Draft<') && str_contains($list, 'Unpublish'));
    $drafts = http('GET', '/' . admin_path() . '/blog', ['status' => 'draft'])->body();
    T::true(str_contains($drafts, 'Draft post') && !str_contains($drafts, 'Live post about growth'));
    T::true(str_contains(http('GET', '/' . admin_path() . '/blog', ['q' => 'Scheduled'])->body(), 'Scheduled post'));

    // Public site: live post visible with SEO + JSON-LD; scheduled and draft are not.
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $index = http('GET', '/blog')->body();
    T::true(str_contains($index, 'Live post about growth') && !str_contains($index, 'Scheduled post') && !str_contains($index, 'Draft post'));
    T::true(str_contains($index, 'Growth tips <span class="count">1</span>'), 'category count excludes the scheduled post');
    $page = http('GET', '/blog/' . $live['slug']);
    T::eq(200, $page->status());
    T::true(str_contains($page->body(), '<title>Live SEO title') && str_contains($page->body(), 'Live SEO description') && str_contains($page->body(), '"BlogPosting"'));
    $sched = $cdb->fetch("SELECT * FROM blog_posts WHERE title = 'Scheduled post'");
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('GET', '/blog/' . $sched['slug'])); // scheduled post is not public yet
    T::true(!str_contains(http('GET', '/sitemap.xml')->body(), $sched['slug']));

    // Unpublish / publish from the list.
    login_as_admin($cAdmin);
    http('POST', '/' . admin_path() . '/blog/' . $live['id'] . '/toggle', ['_token' => csrf()]);
    T::eq('draft', $cdb->fetchColumn('SELECT status FROM blog_posts WHERE id = ?', [$live['id']]));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('GET', '/blog/' . $live['slug']));
    login_as_admin($cAdmin);
    http('POST', '/' . admin_path() . '/blog/' . $live['id'] . '/toggle', ['_token' => csrf()]);
    T::eq('published', $cdb->fetchColumn('SELECT status FROM blog_posts WHERE id = ?', [$live['id']]));
    T::eq($live['published_at'], $cdb->fetchColumn('SELECT published_at FROM blog_posts WHERE id = ?', [$live['id']]), 'original date kept');
    // Edit keeps tags; delete removes.
    T::true(str_contains(http('GET', '/' . admin_path() . '/blog/' . $live['id'] . '/edit')->body(), 'instagram, tips'));
    http('POST', '/' . admin_path() . '/blog/' . $sched['id'] . '/delete', ['_token' => csrf()]);
    T::eq(null, $cdb->fetchColumn('SELECT id FROM blog_posts WHERE id = ?', [$sched['id']]));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});

T::test('Blog admin: requires content permission; toggle and delete require CSRF', function () use ($cdb) {
    $support = $cdb->insert('admins', ['username' => 'blogsupport', 'email' => 'blogsupport@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $cdb->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Support'", [$support]);
    login_as_admin($support);
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('GET', '/' . admin_path() . '/blog'));
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    $id = (int) $cdb->fetchColumn('SELECT id FROM blog_posts LIMIT 1');
    T::throws(App\Core\Exceptions\HttpException::class, fn () => http('POST', '/' . admin_path() . '/blog/' . $id . '/toggle', []));
});

T::test('API page: endpoint, status, examples and warning; key shown once, never another user\'s key', function () use ($cdb) {
    Auth::logoutUser();
    $u = Fx::user('12.5');
    $other = Fx::user('0');
    $otherKey = ApiKeyService::generate((int) $other['id']);
    login_as_user($u);
    $html = http('GET', '/account/api')->body();
    T::true(str_contains($html, 'https://panel.test/api/v2') && str_contains($html, 'No key yet') && str_contains($html, 'Generate API key'));
    T::true(str_contains($html, 'Keep your key secret') && str_contains($html, 'action=balance') && str_contains($html, 'refill_status'));
    T::true(str_contains($html, 'YOUR_API_KEY') && !str_contains($html, $otherKey) && !str_contains($html, substr($otherKey, 0, 12)));
    http('POST', '/account/api/generate', ['_token' => csrf()]);
    $once = http('GET', '/account/api')->body();
    preg_match('/id="new-key">([^<]+)</', $once, $m);
    T::true(!empty($m[1]) && ApiKeyService::authenticate($m[1], '1.2.3.4') !== null, 'the shown key works');
    T::true(str_contains($once, '>Active<'));
    $again = http('GET', '/account/api')->body();
    T::true(!str_contains($again, $m[1]), 'the full key is never shown again');
    T::true(str_contains($again, substr($m[1], 0, 8)), 'prefix identifies it');
    T::true(!str_contains($again, $otherKey));
    $cdb->update('users', ['api_enabled' => 0], ['id' => $u['id']]);
    T::true(str_contains(http('GET', '/account/api')->body(), 'API access is disabled for your account'));
    Auth::logoutUser();
});

T::test('Admin balance controls: header buttons, dialog and list shortcuts only for admins with users.balance', function () use ($cdb, $cAdmin) {
    $u = Fx::user('3');
    login_as_admin($cAdmin);
    $page = http('GET', '/' . admin_path() . '/users/' . $u['id'], ['balance' => 'add'])->body();
    T::true(str_contains($page, 'Add balance') && str_contains($page, 'Remove balance') && str_contains($page, 'id="balance-dialog"'));
    T::true((bool) preg_match('/data-fill=\'\{"direction":"add"\}\'[^>]*data-click-on-load/', $page), '?balance=add opens the add dialog');
    T::true(str_contains($page, 'name="adjust_key"') && str_contains($page, 'name="reason"'));
    $list = http('GET', '/' . admin_path() . '/users')->body();
    T::true(str_contains($list, 'users/' . $u['id'] . '?balance=add') && str_contains($list, '?balance=remove'));
    $finance = $cdb->insert('admins', ['username' => 'nobalance' . bin2hex(random_bytes(2)), 'email' => 'nb' . bin2hex(random_bytes(2)) . '@example.com', 'password_hash' => 'x', 'status' => 'active', 'is_super' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $cdb->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Support'", [$finance]);
    login_as_admin($finance);
    $page = http('GET', '/' . admin_path() . '/users/' . $u['id'])->body();
    T::true(!str_contains($page, 'balance-dialog') && !str_contains($page, 'Add balance'), 'hidden without users.balance');
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
});
