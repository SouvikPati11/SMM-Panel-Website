<?php

declare(strict_types=1);

// Runs the "refills" task once. Use either this per-task script OR cron/run.php.
require __DIR__ . '/bootstrap.php';
cron_task('refills');
