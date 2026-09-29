<?php

declare(strict_types=1);

/*
 * Shared bootstrap for cron scripts (cron/run.php and the per-task scripts).
 *
 * Works when started by cron with any working directory and with the PHP CLI
 * or a web PHP binary (lsphp / php-cgi — their PHP_SAPI is not "cli", which
 * used to make every run exit silently with "Forbidden"). Refuses HTTP.
 *
 * Every invocation is recorded in storage/cron-status.json (Admin → Cron tasks)
 * — including failures that happen before the database is reachable.
 *
 * Exit codes: 0 = ok, 1 = a task failed, 2 = environment/configuration problem
 * (PHP version, extensions, not installed), 3 = database unavailable.
 *
 * This file must stay parseable by PHP 7.1+ (no arrow functions, match, nullsafe
 * or union types here) so a too-old CLI binary gives a clear, recorded message
 * instead of a parse error. The application itself requires PHP 8.1+.
 */

// HTTP requests always carry REQUEST_METHOD; a command-line run never does.
if (!empty($_SERVER['REQUEST_METHOD'])) {
    if (function_exists('http_response_code')) {
        http_response_code(403);
    }
    exit('Forbidden');
}

// Web binaries (lsphp, php-cgi) started from cron do not define the CLI streams.
if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'rb'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

$GLOBALS['smm_cron_base'] = dirname(__DIR__);
// Diagnostic runs (--check/--list/--help) are recorded separately so they never
// look like a successful scheduler run.
$smmArgv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : (isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : array());
$GLOBALS['smm_cron_status_file'] = $GLOBALS['smm_cron_base'] . '/storage/' . (array_intersect(array('--check', '--list', '--help', '-h'), $smmArgv) ? 'cron-check.json' : 'cron-status.json');
unset($smmArgv);
$GLOBALS['smm_cron_cwd'] = (string) getcwd();
@chdir($GLOBALS['smm_cron_base']); // never depend on cron's working directory
if (function_exists('set_time_limit')) {
    @set_time_limit(300); // web binaries default to a 30 s limit
}

/** Merge fields into storage/cron-status.json (same file as App\Services\CronStatus) or cron-check.json. */
function smm_cron_status(array $fields)
{
    $file = $GLOBALS['smm_cron_status_file'];
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $data = array_merge(is_array($data) ? $data : array(), $fields);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, $file);
    }
    @unlink($tmp);
}

/** Record the outcome of this invocation and exit with $code. */
function smm_cron_finish($code, $error, $summary)
{
    $now = gmdate('Y-m-d H:i:s');
    $fields = array('finished_at' => $now, 'exit_code' => (int) $code, 'error' => $error, 'summary' => $summary, 'stage' => $code === 0 ? 'done' : 'failed');
    if ($code === 0) {
        $fields['last_success_at'] = $now;
    } else {
        $fields['last_error_at'] = $now;
        $fields['last_error'] = $error;
    }
    smm_cron_status($fields);
    if ($error !== null && $error !== '') {
        fwrite(STDERR, '[' . $now . ' UTC] cron ERROR: ' . $error . PHP_EOL);
    }
    exit((int) $code);
}

$GLOBALS['smm_cron_user'] = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : (string) get_current_user();
smm_cron_status(array(
    'invoked_at' => gmdate('Y-m-d H:i:s'),
    'finished_at' => null,
    'stage' => 'starting',
    'exit_code' => null,
    'error' => null,
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'php_binary' => PHP_BINARY,
    'script' => isset($_SERVER['SCRIPT_FILENAME']) ? basename((string) $_SERVER['SCRIPT_FILENAME']) : 'run.php',
    'cwd' => $GLOBALS['smm_cron_cwd'],
    'pid' => getmypid(),
    'user' => $GLOBALS['smm_cron_user'],
));
if (!is_writable($GLOBALS['smm_cron_base'] . '/storage')) {
    fwrite(STDERR, 'cron WARNING: ' . $GLOBALS['smm_cron_base'] . '/storage is not writable by this user; logs and diagnostics cannot be saved.' . PHP_EOL);
}

if (PHP_VERSION_ID < 80100) {
    smm_cron_finish(2, 'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ') is too old for cron: PHP 8.1 or newer is required. Use the CLI binary of the PHP version your website uses, e.g. /opt/alt/php83/usr/bin/php or /usr/bin/php8.3.', null);
}

require_once $GLOBALS['smm_cron_base'] . '/app/Core/Requirements.php';
if ($smmMissing = App\Core\Requirements::missingExtensions()) {
    smm_cron_finish(2, 'The PHP binary used by cron (' . PHP_BINARY . ', PHP ' . PHP_VERSION . ') is missing required extensions: ' . implode(', ', $smmMissing) . '. Use the same PHP version/binary as the website or enable them for the CLI.', null);
}

require $GLOBALS['smm_cron_base'] . '/app/bootstrap.php';

if (!App\Core\App::isInstalled()) {
    smm_cron_finish(2, 'Not installed yet (storage/installed.lock is missing). Complete /install first.', null);
}
// Without this the database error reads "Access denied for user ''", which hides the cause.
if (is_file($GLOBALS['smm_cron_base'] . '/.env') && !is_readable($GLOBALS['smm_cron_base'] . '/.env')) {
    smm_cron_finish(2, $GLOBALS['smm_cron_base'] . '/.env exists but is not readable by the user cron runs as (' . $GLOBALS['smm_cron_user'] . '). Run the cron job as the account that owns the website files, or make .env readable by that user.', null);
}
try {
    App\Core\Database::instance()->fetchColumn('SELECT 1');
} catch (\Throwable $e) {
    App\Core\Logger::error('Cron: database unavailable: ' . $e->getMessage(), [], 'cron');
    smm_cron_finish(3, 'Database connection failed: ' . $e->getMessage(), null);
}
App\Services\SettingsService::boot();
smm_cron_status(['stage' => 'running']);

// A fatal error (memory, timeout…) inside a task must still be recorded.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $msg = 'Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'];
        App\Services\CronService::abortCurrent($msg);
        smm_cron_status(['finished_at' => gmdate('Y-m-d H:i:s'), 'exit_code' => 255, 'stage' => 'failed', 'error' => $msg, 'last_error' => $msg, 'last_error_at' => gmdate('Y-m-d H:i:s')]);
    }
});

/** Per-task scripts (cron/orders.php …): run one task now and exit with its status. */
function cron_task(string $task): void
{
    $r = App\Services\CronService::run($task);
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] {$task}: {$r['status']}" . (isset($r['output']['error']) ? ' — ' . $r['output']['error'] : '');
    App\Core\Cli::stdout($line);
    $failed = $r['status'] === 'failed';
    smm_cron_finish($failed ? 1 : 0, $failed ? "Task {$task} failed: " . ($r['output']['error'] ?? 'unknown error') : null, [$task => $r['status']]);
}
