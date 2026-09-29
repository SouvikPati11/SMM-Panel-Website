<?php

declare(strict_types=1);

/*
 * Scheduler — the only cron line you need:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron/run.php >/dev/null 2>&1
 * Runs each task when its interval has elapsed (see App\Services\CronService::TASKS).
 * Named MySQL locks guarantee the same task never runs twice concurrently.
 */
require __DIR__ . '/bootstrap.php';

foreach (App\Services\CronService::runDue() as $task => $r) {
    echo '[' . gmdate('Y-m-d H:i:s') . "] {$task}: {$r['status']}" . PHP_EOL;
}
