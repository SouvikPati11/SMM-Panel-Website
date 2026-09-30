<?php

declare(strict_types=1);

use App\Core\Cli;
use App\Core\Config;
use App\Core\Database;
use App\Services\CronService;
use App\Services\CronStatus;

/*
 * Cron: command-line detection for non-CLI binaries (lsphp/php-cgi started by
 * cron), duplicate-execution protection, failure recording, exit codes and the
 * diagnostics shown to admins.
 */

$cronPhp = static function (string $args, array $env = []): array {
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    exec('cd / && ' . $prefix . escapeshellarg(PHP_BINARY) . ' ' . $args . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

/** A second, independent DB connection (another "process" holding locks). */
$otherConnection = static function (): PDO {
    $c = Config::get('db');
    return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};

T::test('Cron: command-line detection works for lsphp/php-cgi started by cron, never for HTTP', function () {
    T::true(Cli::isCommandLine('cli', []), 'php CLI');
    T::true(Cli::isCommandLine('litespeed', ['SCRIPT_FILENAME' => '/x/cron/run.php']), 'lsphp from cron (Hostinger)');
    T::true(Cli::isCommandLine('cgi-fcgi', ['PATH' => '/usr/bin']), 'php-cgi from cron');
    T::true(!Cli::isCommandLine('litespeed', ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com']), 'lsphp serving HTTP');
    T::true(!Cli::isCommandLine('fpm-fcgi', ['REQUEST_METHOD' => 'POST']), 'php-fpm serving HTTP');
    T::true(!Cli::isCommandLine('cli-server', []), 'built-in web server');
    T::true(Cli::isNonCliBinary('litespeed') && !Cli::isNonCliBinary('cli'));
});

T::test('Cron: recommended command is a PHP CLI binary, never lsphp, and never silenced', function () {
    $bin = Cli::recommendedCliBinary();
    T::true(!preg_match('~(lsphp|php-cgi|php-fpm)[\d.]*$~', $bin), "not a web binary: {$bin}");
    foreach (Cli::cliBinaryCandidates() as $c) {
        T::true(is_file($c) && is_executable($c), "candidate exists: {$c}");
    }
    $done = (string) file_get_contents(BASE_PATH . '/resources/views/install/done.php');
    $page = (string) file_get_contents(BASE_PATH . '/resources/views/admin/system/cron.php');
    foreach ([$done, $page] as $view) {
        T::true(!str_contains($view, 'PHP_BINARY'), 'views never print the web PHP binary');
        T::true(!preg_match('~/cron/run\.php\s*(&gt;|>)\s*/dev/null~', $view), 'no >/dev/null 2>&1 in the recommended command');
    }
});

T::test('Cron: a failing task is recorded as failed, logged, and does not stop other tasks', function () {
    $db = Database::instance();
    $db->pdo()->exec('RENAME TABLE email_queue TO email_queue_hidden');
    try {
        $r = CronService::run('notifications');
    } finally {
        $db->pdo()->exec('RENAME TABLE email_queue_hidden TO email_queue');
    }
    T::eq('failed', $r['status']);
    T::true(str_contains((string) $r['output']['error'], 'email_queue'));
    $row = $db->fetch("SELECT * FROM cron_runs WHERE task = 'notifications' ORDER BY id DESC LIMIT 1");
    T::eq('failed', $row['status']);
    T::true($row['finished_at'] !== null && str_contains((string) $row['output'], 'email_queue'));
    $log = glob(STORAGE_PATH . '/logs/cron-*.log') ?: [];
    T::true($log !== [] && str_contains((string) file_get_contents(end($log)), 'Cron notifications failed'), 'written to storage/logs/cron-*.log');
    T::eq('success', CronService::run('refills')['status'], 'next task still runs');
    $status = CronService::status();
    T::true($status['notifications']['last_failure'] !== null);
});

T::test('Cron: duplicate execution is prevented while another process holds the task lock', function () use ($otherConnection) {
    $other = $otherConnection();
    T::eq('1', (string) $other->query("SELECT GET_LOCK('smm_cron_orders', 0)")->fetchColumn());
    $before = (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM cron_runs WHERE task = 'orders' AND status <> 'skipped'");
    $r = CronService::run('orders');
    T::eq('skipped', $r['status']);
    T::eq($before, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM cron_runs WHERE task = 'orders' AND status <> 'skipped'"), 'task body did not run');
    $other->query("SELECT RELEASE_LOCK('smm_cron_orders')");
    T::eq('success', CronService::run('orders')['status'], 'runs once the lock is free');
});

T::test('Cron: overlapping schedulers never run a task twice back-to-back; runDue runs only due tasks', function () {
    $db = Database::instance();
    $db->query('DELETE FROM cron_runs');
    $first = CronService::runDue();
    T::eq(array_keys(CronService::TASKS), array_keys($first), 'everything due on the first run');
    T::eq([], CronService::runDue(), 'nothing due a second later');
    T::eq('not_due', CronService::run('payments', true)['status'], 'a second scheduler that waited on the lock re-checks the schedule');
    $db->query("UPDATE cron_runs SET started_at = ? WHERE task = 'payments'", [gmdate('Y-m-d H:i:s', time() - 6 * 60)]);
    T::eq(['payments'], array_keys(CronService::runDue()), 'due again after its 5-minute interval');
});

T::test('Cron: a run left "running" by a killed process is marked failed; fatal errors are recorded', function () {
    $db = Database::instance();
    $dead = $db->insert('cron_runs', ['task' => 'refills', 'status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    CronService::run('refills');
    $row = $db->fetch('SELECT * FROM cron_runs WHERE id = ?', [$dead]);
    T::eq('failed', $row['status']);
    T::true(str_contains((string) $row['output'], 'Interrupted'));
    CronService::abortCurrent('no current run: must be a no-op');
});

T::test('Cron: CLI scheduler works from any working directory and returns real exit codes', function () use ($cronPhp) {
    // Subprocesses use the real .env; without an installation they must fail loudly (exit 2), not silently.
    $installed = is_file(STORAGE_PATH . '/installed.lock');
    [$code, $out] = $cronPhp(escapeshellarg(BASE_PATH . '/cron/run.php') . ' --list');
    if ($installed) {
        T::true(in_array($code, [0, 3], true), "installed: exit 0 (or 3 if its database is down): {$code} {$out}");
    } else {
        T::eq(2, $code, $out);
        T::true(str_contains($out, 'Not installed'), $out);
    }
    [$code, $out] = $cronPhp(escapeshellarg(BASE_PATH . '/cron/run.php'), ['REQUEST_METHOD' => 'GET']);
    T::eq('Forbidden', trim($out), 'refuses anything that looks like an HTTP request');
});

T::test('Cron: a PHP binary missing required extensions fails with exit code 2 and a clear, recorded message', function () use ($cronPhp) {
    [, $out] = $cronPhp('-n -r ' . escapeshellarg('echo json_encode(get_loaded_extensions());'));
    $missing = array_diff(App\Core\Requirements::EXTENSIONS, array_map('strtolower', json_decode($out, true) ?: []));
    if (!$missing) {
        echo "    (skipped: all required extensions are compiled in)\n";
        return;
    }
    $statusFile = STORAGE_PATH . '/cron-status.json';
    $backup = is_file($statusFile) ? file_get_contents($statusFile) : null;
    [$code, $out] = $cronPhp('-n ' . escapeshellarg(BASE_PATH . '/cron/run.php'));
    $recorded = json_decode((string) file_get_contents($statusFile), true);
    $backup === null ? @unlink($statusFile) : file_put_contents($statusFile, $backup);
    T::eq(2, $code, $out);
    T::true(str_contains($out, 'missing required extensions'), $out);
    T::eq(2, $recorded['exit_code']);
    T::true(str_contains((string) $recorded['last_error'], 'missing required extensions'));
});

T::test('Cron: run as a user that cannot read .env fails with exit code 2 naming the cause (not "Access denied for user \'\'")', function () {
    $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
    if ($root && !is_executable('/usr/sbin/runuser') && !is_executable('/sbin/runuser')) {
        echo "    (skipped: running as root without runuser)\n";
        return;
    }
    $dir = sys_get_temp_dir() . '/smm-cron-env-' . bin2hex(random_bytes(4));
    exec('mkdir -p ' . escapeshellarg($dir . '/storage/logs') . ' && cp -r ' . escapeshellarg(BASE_PATH . '/app') . ' ' . escapeshellarg(BASE_PATH . '/cron') . ' ' . escapeshellarg(BASE_PATH . '/config') . ' ' . escapeshellarg($dir) . ' && chmod -R a+rX ' . escapeshellarg($dir) . ' && chmod -R a+rwx ' . escapeshellarg($dir . '/storage'));
    file_put_contents($dir . '/storage/installed.lock', 'test');
    file_put_contents($dir . '/.env', "DB_USER=smm\n");
    chmod($dir . '/.env', 0);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/cron/run.php');
    exec('cd / && ' . ($root ? 'runuser -u nobody -- ' . $cmd : $cmd) . ' 2>&1', $out, $code);
    $recorded = json_decode((string) @file_get_contents($dir . '/storage/cron-status.json'), true);
    exec('rm -rf ' . escapeshellarg($dir));
    $out = implode("\n", $out);
    T::eq(2, $code, $out);
    T::true(str_contains($out, '.env exists but is not readable by the user cron runs as'), $out);
    T::eq(2, $recorded['exit_code'] ?? null, 'recorded for Admin → Cron');
});

T::test('Cron: admin diagnostics explain never-run, stale, failing and non-CLI cron', function () {
    $tasks = CronService::status();
    $texts = static fn (array $p) => implode(' | ', array_column($p, 'text'));
    T::true(str_contains($texts(CronStatus::problems([], $tasks)), 'never run'));
    T::true(str_contains($texts(CronStatus::problems(['invoked_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'sapi' => 'cli', 'php_version' => PHP_VERSION], $tasks)), 'last ran'));
    T::true(str_contains($texts(CronStatus::problems(['invoked_at' => now(), 'sapi' => 'litespeed', 'php_binary' => '/usr/local/lsws/lsphp83/bin/lsphp', 'php_version' => PHP_VERSION], $tasks)), 'lsphp'));
    T::true(str_contains($texts(CronStatus::problems(['invoked_at' => now(), 'exit_code' => 3, 'error' => 'Database connection failed', 'sapi' => 'cli', 'php_version' => PHP_VERSION], $tasks)), 'Database connection failed'));
    T::true(str_contains($texts(CronStatus::problems(['invoked_at' => now(), 'sapi' => 'cli', 'php_version' => '7.4.33'], $tasks)), 'PHP 7.4.33'));
    $checks = CronService::diagnose();
    T::true(count($checks) >= 8);
    foreach ($checks as [$label, $ok]) {
        T::true($ok || str_starts_with($label, 'Writable'), "diagnostic passes here: {$label}");
    }
});

T::test('Cron: admin page shows last run, diagnostics and the CLI command; HTTP trigger records status', function () {
    $db = Database::instance();
    $adminId = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1') ?: $db->insert('admins', ['username' => 'cronadmin', 'email' => 'cronadmin@example.com', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'status' => 'active', 'is_super' => 1, 'created_at' => now(), 'updated_at' => now()]);
    login_as_admin($adminId);
    $html = http('GET', '/' . admin_path() . '/cron')->body();
    T::true(str_contains($html, 'Last cron run') && str_contains($html, 'Environment check') && str_contains($html, '/cron/run.php --check'));
    T::true(str_contains($html, e(Cli::recommendedCliBinary() . ' ' . BASE_PATH . '/cron/run.php')));
    // URL trigger (last resort) — records into the same diagnostics.
    Config::set('cron_key', str_repeat('k', 40));
    $statusFile = STORAGE_PATH . '/cron-status.json';
    $backup = is_file($statusFile) ? file_get_contents($statusFile) : null;
    $db->query('DELETE FROM rate_limits');
    $r = http('GET', '/tasks/run/' . str_repeat('k', 40));
    $recorded = CronStatus::read();
    $backup === null ? @unlink($statusFile) : file_put_contents($statusFile, $backup);
    T::eq(200, $r->status());
    T::eq('done', $recorded['stage']);
    T::eq('url', $recorded['trigger'] ?? null);
    T::eq(404, http('GET', '/tasks/run/' . str_repeat('x', 40))->status(), 'wrong key');
});


T::test('Cron URL: admin creates a working URL + wget/curl commands; calling it executes a real due subscription', function () {
    $db = Database::instance();
    Config::set('cron_key', ''); // no CRON_KEY in .env (typical upgraded install)
    App\Services\SettingsService::set('cron_key', '');
    $statusFile = STORAGE_PATH . '/cron-status.json';
    $backup = is_file($statusFile) ? file_get_contents($statusFile) : null;
    $adminId = (int) $db->fetchColumn('SELECT id FROM admins WHERE is_super = 1 LIMIT 1');
    login_as_admin($adminId);
    $html = http('GET', '/' . admin_path() . '/cron')->body();
    T::true(str_contains($html, 'Create cron URL'), 'create button'); T::true(!str_contains($html, '/tasks/run/'), 'no url yet');
    T::eq(404, http('GET', '/tasks/run/' . str_repeat('a', 48))->status());

    http('POST', '/' . admin_path() . '/cron/url', ['_token' => csrf()]);
    $key = App\Services\CronUrl::key();
    T::eq(48, strlen($key));
    T::true(!str_contains((string) $db->fetchColumn("SELECT value FROM settings WHERE `key` = 'cron_key'"), $key), 'stored encrypted');
    $url = url('/tasks/run/' . $key);
    $html = http('GET', '/' . admin_path() . '/cron')->body();
    T::true(str_contains($html, e("wget -q -O - --timeout=600 '" . $url . "'")), 'full wget command shown');
    T::true(str_contains($html, e("curl -fsS -L --max-time 600 '" . $url . "'")) && str_contains($html, 'id="cron-url-value"'), 'curl shown');
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);

    // A real due task: a scheduled subscription whose next delivery is due.
    Fx::http(['https://provider.test/' => static fn () => Fx::json(['order' => 880001])]);
    App\Services\SettingsService::set('subscriptions_enabled', '1');
    $svc = Fx::service(Fx::provider(), ['name' => 'Cron URL svc', 'subscription_enabled' => 1]);
    $u = Fx::user('50');
    $sub = App\Services\SubscriptionService::create((int) $u['id'], $svc, ['link' => 'https://instagram.com/cronurl', 'quantity' => '1000'], 24, 3, 'cronurl-1');
    $db->query('UPDATE subscriptions SET next_run_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $sub['id']]);
    $db->query("DELETE FROM cron_runs WHERE task = 'subscriptions'");
    $db->query('DELETE FROM rate_limits');
    $before = (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE subscription_id = ?', [$sub['id']]);

    $r = http('GET', '/tasks/run/' . $key, [], ['HTTP_USER_AGENT' => 'Wget/1.21.3']);
    $body = json_decode($r->body(), true);
    T::eq(200, $r->status(), $r->body());
    T::eq('success', $body['ran']['subscriptions'] ?? null);
    T::true($body['ok'] === true && str_contains($body['message'], 'due task'), 'body ' . $r->body());
    T::eq($before + 1, (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE subscription_id = ?', [$sub['id']]), 'delivery 2 ordered by the URL call');
    T::eq(2, (int) App\Services\SubscriptionService::find((int) $sub['id'])['completed_cycles']);
    T::eq('success', $db->fetchColumn("SELECT status FROM cron_runs WHERE task = 'subscriptions' ORDER BY id DESC LIMIT 1"));
    T::true(str_contains((string) $r->header('Cache-Control'), 'no-store') && $r->header('X-LiteSpeed-Cache-Control') === 'no-cache', 'never cached by LiteSpeed');
    $st = CronStatus::read();
    T::eq(['url', 'done', 200], [$st['trigger'], $st['stage'], $st['url_last_status']]);
    T::true(str_contains((string) ($st['url_last_agent'] ?? ''), 'Wget'), 'agent ' . json_encode($st));

    // Called again in the same minute: nothing due, nothing ordered twice.
    $again = json_decode(http('GET', '/tasks/run/' . $key)->body(), true);
    T::eq([], $again['ran']);
    T::true(str_contains($again['message'], 'No task was due'), 'again');
    T::eq($before + 1, (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE subscription_id = ?', [$sub['id']]));

    // Rotation: the old URL stops working and the rejected call is reported to the admin.
    login_as_admin($adminId);
    http('POST', '/' . admin_path() . '/cron/url', ['_token' => csrf()]);
    T::true(App\Services\CronUrl::key() !== $key, 'rotated');
    $db->query('DELETE FROM rate_limits');
    T::eq(404, http('GET', '/tasks/run/' . $key)->status());
    T::true(str_contains(implode(' ', array_column(CronStatus::problems(CronStatus::read(), CronService::status()), 'text')), 'wrong or old key'), 'rejected diag ' . json_encode(CronStatus::read()));
    // POST without CSRF cannot rotate it.
    $k2 = App\Services\CronUrl::key();
    try {
        http('POST', '/' . admin_path() . '/cron/url', ['_token' => 'bad']);
    } catch (\Throwable) {
    }
    T::eq($k2, App\Services\CronUrl::key());
    // CRON_KEY in .env takes precedence and cannot be rotated from the panel.
    Config::set('cron_key', str_repeat('e', 40));
    T::eq('env', App\Services\CronUrl::source());
    http('POST', '/' . admin_path() . '/cron/url', ['_token' => csrf()]);
    T::eq(str_repeat('e', 40), App\Services\CronUrl::key());
    unset($_SESSION['admin_id'], $_SESSION['admin_sv']);
    Config::set('cron_key', '');
    $backup === null ? @unlink($statusFile) : file_put_contents($statusFile, $backup);
});
