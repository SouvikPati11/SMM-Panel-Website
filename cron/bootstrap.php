<?php

declare(strict_types=1);

/* Shared bootstrap for cron scripts. Refuses to run over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require dirname(__DIR__) . '/app/bootstrap.php';

if (!App\Core\App::isInstalled()) {
    fwrite(STDERR, "Not installed yet.\n");
    exit(1);
}
App\Services\SettingsService::boot();

function cron_task(string $task): void
{
    $r = App\Services\CronService::run($task);
    echo '[' . gmdate('Y-m-d H:i:s') . "] {$task}: {$r['status']} " . json_encode($r['output'] ?? null) . PHP_EOL;
    exit($r['status'] === 'failed' ? 1 : 0);
}
