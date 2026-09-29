<?php

declare(strict_types=1);

/*
 * Application bootstrap: shared by the web front controller, the installer,
 * cron scripts and the test runner.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('This application requires PHP 8.1 or newer. Current version: ' . PHP_VERSION);
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('VIEW_PATH', BASE_PATH . '/resources/views');
define('PUBLIC_PATH', BASE_PATH . '/public');

// Composer is optional. When present, use it (it may carry optional packages);
// otherwise fall back to a minimal PSR-4 autoloader for the App\ namespace.
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

require APP_PATH . '/Helpers/functions.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\ErrorHandler::register();

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
