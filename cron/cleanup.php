<?php

declare(strict_types=1);

// Runs the "cleanup" task once. Use either this per-task script OR cron/run.php.
require __DIR__ . '/bootstrap.php';
cron_task('cleanup');
