<?php

declare(strict_types=1);

/*
 * Apply pending schema upgrades (safe to re-run):  php database/migrate.php
 * Needed when updating an existing installation to a newer version. Works with
 * the PHP CLI and with web binaries (lsphp/php-cgi) started from cron; refuses HTTP.
 */
if (!empty($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$applied = App\Install\Migrator::run();
App\Core\Cli::stdout($applied ? 'Applied: ' . implode(', ', $applied) : 'Database schema is up to date.');
