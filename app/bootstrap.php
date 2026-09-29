<?php

declare(strict_types=1);

/*
 * Application bootstrap: shared by the web front controller, the installer,
 * cron scripts and the test runner.
 */

// Runtime requirements are enforced here, not by Composer (see App\Core\Requirements).
// Command line = no HTTP request (also true for lsphp/php-cgi started by cron, whose
// PHP_SAPI is not "cli"). This block must stay parseable by old PHP versions.
$smmCommandLine = PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD']);
if (PHP_VERSION_ID < 80100) {
    if ($smmCommandLine) {
        fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'wb'), 'This application requires PHP 8.1 or newer. Current version: ' . PHP_VERSION . ' (' . PHP_BINARY . ')' . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    exit('This application requires PHP 8.1 or newer. Current version: ' . PHP_VERSION);
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('VIEW_PATH', BASE_PATH . '/resources/views');
define('PUBLIC_PATH', BASE_PATH . '/public');

// Composer is optional. When present (e.g. a host ran `composer install` on
// deploy), use it; otherwise fall back to a minimal PSR-4 autoloader for App\.
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if ($missing = App\Core\Requirements::missingExtensions()) {
    $msg = 'This application requires these PHP extensions, which are not enabled: ' . implode(', ', $missing) . '. Enable them in your hosting control panel (PHP configuration / extensions).';
    if ($smmCommandLine) {
        App\Core\Cli::stderr($msg . ' [' . PHP_BINARY . ', PHP ' . PHP_VERSION . ', ' . PHP_SAPI . ']');
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($msg);
}

// require_once: safe even if a Composer autoloader from an older checkout also loads it.
require_once APP_PATH . '/Helpers/functions.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\ErrorHandler::register();

unset($smmCommandLine);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
