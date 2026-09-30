<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\HtmlSanitizer;
use App\Services\UploadService;

$db = Database::instance();
$adminId = $db->insert('admins', ['username' => 'root', 'email' => 'root@example.com', 'password_hash' => password_hash('AdminPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
$supportId = $db->insert('admins', ['username' => 'agent', 'email' => 'agent@example.com', 'password_hash' => password_hash('AgentPass123', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 0, 'created_at' => now(), 'updated_at' => now()]);
$db->query("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Support'", [$supportId]);

T::test('CSRF: POST without token is rejected (cross-site 403; same-site form → back with a message, nothing processed)', function () {
    $e = null;
    try {
        http('POST', '/login', ['login' => 'x', 'password' => 'y']);
    } catch (HttpException $ex) {
        $e = $ex;
    }
    T::eq(403, $e?->getStatus(), 'no same-site evidence');
    $before = (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM login_attempts');
    $r = http('POST', '/login', ['login' => 'x', 'password' => 'y'], ['HTTP_ORIGIN' => rtrim(url('/'), '/'), 'HTTP_REFERER' => url('/login')]);
    T::eq(303, $r->status());
    T::true(str_ends_with((string) $r->header('Location'), '/login'));
    T::eq($before, (int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM login_attempts'), 'login not attempted');
    unset($_SESSION['_restore'], $_SESSION['_flash']);
});

T::test('CSRF: POST with wrong token is rejected, correct token accepted', function () {
    csrf();
    T::throws(HttpException::class, fn () => http('POST', '/login', ['_token' => str_repeat('a', 64), 'login' => 'x', 'password' => 'y']));
    $r = http('POST', '/login', ['_token' => csrf(), 'login' => 'nobody', 'password' => 'wrong-password']);
    T::eq(302, $r->status()); // validation error redirect, i.e. past CSRF
});

T::test('CSRF: webhooks and API are exempt (stateless)', function () {
    T::eq(401, http('POST', '/api/v2', ['key' => 'bad', 'action' => 'balance'])->status());
});

T::test('AuthZ: user panel requires login', function () {
    $r = http('GET', '/dashboard');
    T::eq(302, $r->status());
    T::true(str_ends_with((string) $r->header('Location'), '/login'));
});

T::test('AuthZ: a logged-in user is not an admin', function () {
    login_as_user(Fx::user('0'));
    $r = http('GET', '/' . admin_path() . '/users');
    T::eq(302, $r->status());
    T::true(str_contains((string) $r->header('Location'), '/' . admin_path() . '/login'));
});

T::test('AuthZ: users cannot view other users\' orders or tickets (404)', function () {
    $a = Fx::user('10');
    $b = Fx::user('0');
    $sid = Fx::service(null, ['name' => 'manual for authz']);
    $o = App\Services\OrderService::place((int) $a['id'], $sid, ['link' => 'https://x.com/a', 'quantity' => '100']);
    $t = App\Services\TicketService::create((int) $a['id'], 'order', 'Help me', 'Order stuck please', '', null);
    login_as_user($b);
    T::throws(HttpException::class, fn () => http('GET', '/orders/' . $o['id']));
    T::throws(HttpException::class, fn () => http('GET', '/tickets/' . $t));
    login_as_user($a);
    T::eq(200, http('GET', '/orders/' . $o['id'])->status());
});

T::test('AuthZ: admin role permissions are enforced (Support cannot open settings)', function () use ($supportId) {
    login_as_admin($supportId);
    T::eq(200, http('GET', '/' . admin_path() . '/tickets')->status());
    $e = null;
    try {
        http('GET', '/' . admin_path() . '/settings');
    } catch (HttpException $ex) {
        $e = $ex;
    }
    T::eq(403, $e?->getStatus());
    T::throws(HttpException::class, fn () => http('POST', '/' . admin_path() . '/users/1/balance', ['_token' => csrf(), 'amount' => '100', 'reason' => 'x']));
});

T::test('AuthZ: session invalidated when user is suspended / password changed', function () {
    $u = Fx::user('0');
    login_as_user($u);
    T::eq(200, http('GET', '/dashboard')->status());
    Database::instance()->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$u['id']]);
    T::eq(302, http('GET', '/dashboard')->status());
    login_as_user($u);
    Database::instance()->update('users', ['status' => 'suspended'], ['id' => $u['id']]);
    T::eq(302, http('GET', '/dashboard')->status());
});

T::test('XSS: user-supplied order link and ticket text are escaped (user + admin views)', function () use ($adminId) {
    $u = Fx::user('10');
    $sid = Fx::service(null, ['name' => 'Username svc', 'custom_fields' => json_encode(['link_type' => 'text'])]);
    $payload = 'javascript:alert(1)//"><img/src=x/onerror=alert(2)>';
    // text link type forbids spaces but allows this payload — it must never render as HTML/href
    $o = App\Services\OrderService::place((int) $u['id'], $sid, ['link' => $payload, 'quantity' => '100']);
    $tid = App\Services\TicketService::create((int) $u['id'], 'other', '<script>alert(3)</script>', "<svg onload=alert(4)>", '', null);
    login_as_user($u);
    foreach (['/orders', '/orders/' . $o['id'], '/tickets/' . $tid] as $p) {
        $html = http('GET', $p)->body();
        T::true(!str_contains($html, '<img/src=x'), "raw payload in {$p}");
        T::true(!str_contains($html, 'href="javascript:'), "javascript: href in {$p}");
        T::true(!str_contains($html, '<script>alert(3)') && !str_contains($html, '<svg onload'), "script in {$p}");
    }
    $_SESSION = [];
    login_as_admin($adminId);
    foreach (['/' . admin_path() . '/orders', '/' . admin_path() . '/orders/' . $o['id'], '/' . admin_path() . '/tickets/' . $tid] as $p) {
        $html = http('GET', $p)->body();
        T::true(!str_contains($html, '<img/src=x') && !str_contains($html, 'href="javascript:') && !str_contains($html, '<svg onload'), "payload in {$p}");
    }
});

T::test('XSS: admin HTML content is sanitised', function () {
    $clean = HtmlSanitizer::clean('<p onclick="x()">Hi</p><script>bad()</script><a href="javascript:alert(1)">l</a><img src=x onerror=alert(1)><iframe src="//evil"></iframe><h2>ok</h2>');
    T::true(!str_contains($clean, 'onclick') && !str_contains($clean, '<script') && !str_contains($clean, 'javascript:') && !str_contains($clean, 'onerror') && !str_contains($clean, '<iframe'));
    T::true(str_contains($clean, '<h2>ok</h2>') && str_contains($clean, '<p>Hi</p>'));
});

T::test('SQL injection: hostile search input is treated as data', function () use ($adminId) {
    $u = Fx::user('0');
    login_as_user($u);
    foreach (["' OR '1'='1", '1; DROP TABLE users; --', '%_\\', "1' UNION SELECT password_hash FROM admins -- "] as $q) {
        T::eq(200, http('GET', '/orders', ['q' => $q])->status());
        T::eq(200, http('GET', '/services', ['q' => $q])->status());
    }
    login_as_admin($adminId);
    $html = http('GET', '/' . admin_path() . '/users', ['q' => "' OR 1=1 -- "])->body();
    T::true(str_contains($html, 'No users found'), 'injection must not match all rows');
    T::true((int) Database::instance()->fetchColumn('SELECT COUNT(*) FROM users') > 0, 'users table intact');
});

T::test('Uploads: PHP disguised as image, wrong MIME and oversize are rejected', function () {
    UploadService::$allowNonUploaded = true;
    $dir = sys_get_temp_dir();
    $php = $dir . '/evil_' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($php, '<?php system($_GET["c"]); ?>');
    T::throws(ValidationException::class, fn () => UploadService::storePrivateImage(['error' => 0, 'tmp_name' => $php, 'name' => 'x.jpg', 'size' => 30], 'proofs'), 'not allowed');
    $gifPhp = $dir . '/poly_' . bin2hex(random_bytes(3)) . '.gif';
    file_put_contents($gifPhp, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<?php echo 'pwned'; ?>");
    if (function_exists('imagecreatefromstring')) {
        $rel = UploadService::storePrivateImage(['error' => 0, 'tmp_name' => $gifPhp, 'name' => 'a.php.gif', 'size' => filesize($gifPhp)], 'proofs');
        T::true(str_ends_with($rel, '.gif') && !str_contains($rel, 'php'), 'random safe name');
        T::true(!str_contains((string) file_get_contents(STORAGE_PATH . '/uploads/' . $rel), '<?php'), 'payload must be stripped by re-encoding');
        @unlink(STORAGE_PATH . '/uploads/' . $rel);
    }
    $big = $dir . '/big_' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($big, str_repeat('A', 6 * 1024 * 1024));
    T::throws(ValidationException::class, fn () => UploadService::storePrivateImage(['error' => 0, 'tmp_name' => $big, 'name' => 'b.png', 'size' => 1], 'proofs'), 'smaller than');
    T::throws(ValidationException::class, fn () => UploadService::storePrivateImage(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'c.png', 'size' => 0], 'proofs'));
    UploadService::$allowNonUploaded = false;
    array_map('unlink', array_filter([$php, $gifPhp, $big], 'is_file'));
});

T::test('Uploads: path traversal on private file serving is refused', function () {
    T::throws(HttpException::class, fn () => UploadService::serve('../../.env'));
    T::throws(HttpException::class, fn () => UploadService::serve('proofs/2026/01/../../../.env'));
});

T::test('Rate limiting: web login brute force is locked out', function () {
    $u = Fx::user('0');
    for ($i = 0; $i < 5; $i++) {
        try {
            App\Services\AuthService::attempt('user', $u['username'], 'wrong' . $i, '203.0.113.200', 'test');
        } catch (ValidationException) {
        }
    }
    T::throws(ValidationException::class, fn () => App\Services\AuthService::attempt('user', $u['username'], 'Secret123', '203.0.113.200', 'test'), 'Too many failed attempts');
});

T::test('Security headers & CSP are present on HTML responses', function () {
    $r = http('GET', '/login');
    T::true(str_contains((string) $r->header('Content-Security-Policy'), "script-src 'self'"));
    T::eq('nosniff', $r->header('X-Content-Type-Options'));
    T::eq('SAMEORIGIN', $r->header('X-Frame-Options'));
});

T::test('Secrets: provider & gateway credentials are encrypted at rest', function () {
    $pid = Fx::provider(); // self-contained: does not rely on another suite having created one
    Fx::gatewayMethod('oxapay', ['merchant_api_key' => 'OXA-TEST-KEY']);
    $raw = (string) Database::instance()->fetchColumn('SELECT api_key_enc FROM providers WHERE id = ?', [$pid]);
    T::true(str_starts_with($raw, 'enc:v1:') && !str_contains($raw, 'provkey123'));
    $g = (string) Database::instance()->fetchColumn("SELECT credentials_enc FROM payment_methods WHERE gateway = 'oxapay'");
    T::true(!str_contains($g, 'OXA-TEST'), 'gateway key must be encrypted');
});

T::test('Installer is locked once installed', function () {
    T::throws(HttpException::class, fn () => http('GET', '/install'));
});

T::test('robots.txt does not reveal the admin path', function () {
    T::true(!str_contains(http('GET', '/robots.txt')->body(), '/' . admin_path()));
});
