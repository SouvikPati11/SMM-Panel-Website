<?php

declare(strict_types=1);

/*
 * Apply pending schema upgrades (safe to re-run):  php database/migrate.php
 * Needed when updating an existing installation to a newer version.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$applied = App\Install\Migrator::run();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Database schema is up to date.' . PHP_EOL;
