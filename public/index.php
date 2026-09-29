<?php

declare(strict_types=1);

/*
 * Front controller — the only PHP file meant to be requested over HTTP.
 * Everything else lives outside the web root (or is blocked by .htaccess).
 */

// PHP built-in dev server: serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\App::run();
