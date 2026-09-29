<?php

declare(strict_types=1);

/*
 * Scheduler — the only cron job you need (every minute, or every 5 minutes):
 *
 *   /usr/bin/php /home/USER/domains/example.com/public_html/cron/run.php
 *
 * Use the PHP *CLI* binary (Admin → Cron tasks shows the right one for your
 * server), not lsphp. Runs each task whose interval has elapsed; MySQL named
 * locks guarantee a task never runs twice at the same time.
 *
 * Output: one summary line per run; errors go to stderr (visible in Hostinger's
 * cron output / cPanel cron email) and to storage/logs/cron-YYYY-MM-DD.log.
 *
 * Options:
 *   --check        diagnose the environment (PHP, extensions, paths, DB, locks); runs no task
 *   --task=NAME    run one task now, whether due or not
 *   --force        run every task now
 *   --list         list tasks with their schedule and last result
 *   -v, --verbose  print each task's output
 *
 * Exit codes: 0 ok · 1 a task failed · 2 environment/configuration problem · 3 database unavailable
 */
require __DIR__ . '/bootstrap.php';

use App\Core\Cli;
use App\Services\CronService;

$args = Cli::args();
$has = static fn (string ...$names): bool => (bool) array_intersect($names, $args);
$opt = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};
$verbose = $has('-v', '--verbose');
$stamp = static fn (): string => '[' . gmdate('Y-m-d H:i:s') . ' UTC]';

if ($has('-h', '--help')) {
    Cli::stdout('Usage: php cron/run.php [--check | --list | --task=NAME | --force] [-v]');
    Cli::stdout('Tasks: ' . implode(', ', array_keys(CronService::TASKS)));
    smm_cron_finish(0, null, ['help' => true]);
}

if ($has('--check')) {
    $bad = 0;
    Cli::stdout("{$stamp()} Cron diagnostics for " . BASE_PATH);
    Cli::stdout('  Started from working directory: ' . $GLOBALS['smm_cron_cwd']);
    foreach (CronService::diagnose() as [$label, $ok, $detail]) {
        $bad += $ok ? 0 : 1;
        Cli::stdout(sprintf('  [%s] %-32s %s', $ok ? ' OK ' : 'FAIL', $label, $detail));
    }
    if (Cli::isNonCliBinary()) {
        Cli::stdout('  [WARN] This is the "' . PHP_SAPI . '" binary, not the PHP CLI. Recommended: ' . Cli::recommendedCliBinary());
    }
    foreach (CronService::status() as $task => $t) {
        Cli::stdout(sprintf('  task %-14s every %4d min · last %s (%s) · %s', $task, $t['interval'], $t['last']['started_at'] ?? 'never', $t['last']['status'] ?? '-', $t['due'] ? 'due now' : 'not due'));
    }
    Cli::stdout($bad ? "  {$bad} check(s) failed." : '  All checks passed.');
    smm_cron_finish($bad ? 2 : 0, $bad ? "{$bad} diagnostic check(s) failed (see --check output)" : null, ['check' => $bad ? 'failed' : 'ok']);
}

if ($has('--list')) {
    foreach (CronService::status() as $task => $t) {
        Cli::stdout(sprintf('%-14s %-12s %s · last %s %s', $task, $t['schedule'], $t['label'], $t['last']['started_at'] ?? 'never', $t['last']['status'] ?? ''));
    }
    smm_cron_finish(0, null, ['list' => true]);
}

$only = $opt('task');
if ($only !== null && !isset(CronService::TASKS[$only])) {
    smm_cron_finish(2, "Unknown task '{$only}'. Tasks: " . implode(', ', array_keys(CronService::TASKS)), null);
}

if ($only !== null) {
    $results = [$only => CronService::run($only)];
} elseif ($has('--force')) {
    $results = [];
    foreach (array_keys(CronService::TASKS) as $task) {
        $results[$task] = CronService::run($task);
    }
} else {
    $results = CronService::runDue();
}

$errors = [];
$summary = [];
foreach ($results as $task => $r) {
    $summary[$task] = $r['status'];
    if ($r['status'] === 'failed') {
        $errors[] = "{$task}: " . ($r['output']['error'] ?? 'unknown error');
    }
    if ($verbose) {
        Cli::stdout("{$stamp()} {$task}: {$r['status']} " . json_encode($r['output'] ?? null, JSON_UNESCAPED_SLASHES));
    }
}
Cli::stdout("{$stamp()} cron " . ($errors ? 'FAILED' : 'ok') . ': ' . ($summary ? implode(', ', array_map(static fn ($t, $s) => "{$t}={$s}", array_keys($summary), $summary)) : 'nothing due'));
smm_cron_finish($errors ? 1 : 0, $errors ? implode(' | ', $errors) : null, $summary);
