<?php

declare(strict_types=1);

use App\Core\Requirements;
use App\Install\Installer;

/*
 * Composer must stay optional: a host's automatic `composer install` (Hostinger
 * Git deployment) must never fail on platform requirements, and the app must
 * boot identically with or without vendor/. Requirements are enforced at runtime.
 */

$php = static fn (string $args): array => [exec(escapeshellarg(PHP_BINARY) . ' ' . $args . ' 2>&1', $out, $code), $code, implode("\n", $out)];

T::test('Deploy: composer.json declares no platform requirements and no duplicate autoload files', function () {
    $c = json_decode((string) file_get_contents(BASE_PATH . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['require', 'require-dev'] as $section) {
        foreach (array_keys($c[$section] ?? []) as $pkg) {
            T::true($pkg !== 'php' && !str_starts_with($pkg, 'ext-') && !str_starts_with($pkg, 'lib-'), "{$section} must not contain platform requirement {$pkg}");
        }
    }
    T::eq(false, $c['config']['platform-check'] ?? null, 'no generated vendor/composer/platform_check.php');
    T::true(empty($c['autoload']['files']), 'helpers are loaded by app/bootstrap.php only');
    T::eq(['App\\' => 'app/'], $c['autoload']['psr-4']);
    $lock = json_decode((string) file_get_contents(BASE_PATH . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    T::eq([], $lock['packages']);
    T::eq([], (array) $lock['platform']);
    T::eq(Requirements::EXTENSIONS, $c['extra']['runtime-requirements']['extensions'], 'documented list matches the enforced list');
});

T::test('Deploy: runtime requirements are a single enforced list, all present here', function () {
    T::eq(Requirements::EXTENSIONS, Installer::REQUIRED_EXTENSIONS);
    T::eq([], Requirements::missingExtensions());
    T::true(PHP_VERSION_ID >= Requirements::MIN_PHP_ID);
});

T::test('Deploy: bootstrap still refuses to run when a required extension is missing', function () use ($php) {
    // `php -n` loads no ini files, so shared extensions are absent.
    [, $code, $out] = $php('-n -r ' . escapeshellarg('echo json_encode(get_loaded_extensions());'));
    $loaded = array_map('strtolower', json_decode($out, true) ?: []);
    $missing = array_values(array_diff(Requirements::EXTENSIONS, $loaded));
    if ($missing === []) {
        echo "    (skipped: every required extension is compiled into this PHP)\n";
        return;
    }
    [, $code, $out] = $php('-n -r ' . escapeshellarg('require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . '; echo "BOOTED";'));
    T::eq(1, $code, $out);
    T::true(!str_contains($out, 'BOOTED'), 'must not boot');
    foreach ($missing as $ext) {
        T::true(str_contains($out, $ext), "message names {$ext}: {$out}");
    }
});

/** The root .htaccess deny rules ("RewriteRule <regex> - [F…]") as PCRE patterns. */
$rootDenyRules = static function (): array {
    preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F[^\]]*\]/m', (string) file_get_contents(BASE_PATH . '/.htaccess'), $m);
    return array_map(static fn (string $re): string => '~' . str_replace('~', '\~', $re) . '~i', $m[1]);
};
$deniedAtRoot = static function (string $path) use ($rootDenyRules): bool {
    foreach ($rootDenyRules() as $re) {
        if (preg_match($re, $path)) {
            return true;
        }
    }
    return false;
};

T::test('Deploy (public_html = repo root): front controller routing is single-pass, no nested .htaccess needed', function () {
    $ht = (string) file_get_contents(BASE_PATH . '/.htaccess');
    $index = (string) file_get_contents(BASE_PATH . '/index.php');
    T::true(str_contains($index, "require __DIR__ . '/public/index.php';"), 'root index.php delegates to public/index.php');
    T::true((bool) preg_match('~^\s*RewriteRule \^\(assets\|uploads\)/\(\.\+\)\$ public/\$1/\$2 \[L\]~m', $ht), 'static files mapped into public/');
    T::true((bool) preg_match('~^\s*RewriteRule \^ index\.php \[L\]\s*$~m', $ht), 'everything else → root index.php');
    T::true((bool) preg_match('~^\s*RewriteRule \^index\\\\\.php\$ - \[L\]~m', $ht), 'front controller short-circuits the second pass');
    // The old catch-all relied on public/.htaccess being re-evaluated (404 on LiteSpeed/Hostinger).
    T::true(!preg_match('~RewriteRule\s+\^\(\.\*\)\$\s+public/\$1~', $ht), 'no "rewrite everything to public/$1"');
    T::true(!preg_match('~RewriteRule\s+\^\([^)]*\binstall\b~i', $ht), '/install is an application route and must not be blocked (was a 403)');
});

T::test('Deploy (public_html = repo root): every non-public top-level entry is denied, routes are not', function () use ($deniedAtRoot) {
    $public = ['index.php', 'public', '.htaccess'];
    foreach (scandir(BASE_PATH) ?: [] as $entry) {
        if (in_array($entry, ['.', '..'], true) || in_array($entry, $public, true)) {
            continue;
        }
        // Local-only artifacts (vendor/, .env, .git …) must be denied too if they exist.
        $probe = is_dir(BASE_PATH . '/' . $entry) ? $entry . '/x' : $entry;
        T::true($deniedAtRoot($probe), "/{$probe} must be denied by the root .htaccess");
    }
    foreach (['.env', '.git/config', 'vendor/autoload.php', 'storage/installed.lock', 'storage/logs/app.log', 'database/schema.sql', 'composer.lock', 'uploads/a.php', 'public/uploads/a.PHTML', 'public/.htaccess'] as $secret) {
        T::true($deniedAtRoot($secret), "/{$secret} must be denied");
    }
    foreach (['', 'install', 'install/database', 'login', 'admin/login', 'dashboard', 'api/v2', 'webhooks/p2gateway', 'tasks/run/abc', 'robots.txt', 'sitemap.xml', 'assets/css/app.css', 'uploads/qr/2026/09/a.png', '.well-known/acme-challenge/t', 'index.php'] as $route) {
        T::true(!$deniedAtRoot($route), "/{$route} must not be denied");
    }
});

T::test('Deploy (document root = public/): public/.htaccess keeps routing and protections', function () {
    $ht = (string) file_get_contents(BASE_PATH . '/public/.htaccess');
    T::true(str_contains($ht, 'RewriteRule ^ index.php [L]'), 'front controller');
    T::true(str_contains($ht, 'RewriteCond %{REQUEST_FILENAME} -f'), 'real files served directly');
    $up = (string) file_get_contents(BASE_PATH . '/public/uploads/.htaccess');
    T::true(str_contains($up, 'php_flag engine off') && str_contains($up, 'Require all denied'), 'uploads never execute');
});

T::test('Deploy: bootstrap tolerates helpers already loaded by a Composer autoloader', function () use ($php) {
    $code = 'require ' . var_export(BASE_PATH . '/app/Helpers/functions.php', true) . '; require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . '; echo function_exists("e") && class_exists("App\\\\Core\\\\Money") ? "BOOTED" : "NO";';
    [, $exit, $out] = $php('-r ' . escapeshellarg($code));
    T::eq(0, $exit, $out);
    T::eq('BOOTED', trim($out));
});

T::test('Deploy: every runtime file the app writes is gitignored (Git deploys never see local changes)', function () {
    if (!is_dir(BASE_PATH . '/.git')) {
        echo "    (skipped: not a git checkout)\n";
        return;
    }
    foreach (['.env', 'storage/installed.lock', 'storage/cron-status.json', 'storage/cron-check.json', 'storage/cron-status.json.123.tmp', 'storage/logs/cron-2026-01-01.log', 'storage/sessions/sess_x', 'storage/cache/x', 'storage/uploads/proofs/x.png', 'public/uploads/qr/x.png', 'vendor/autoload.php'] as $path) {
        exec('cd ' . escapeshellarg(BASE_PATH) . ' && git check-ignore -q ' . escapeshellarg($path), $out, $code);
        T::eq(0, $code, "{$path} must be ignored by git");
    }
});
