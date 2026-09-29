<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\RateLimiter;

/**
 * Cron task registry. Every task runs under a MySQL named lock (so overlapping
 * cron invocations on shared hosting never run the same task twice at once)
 * and is recorded in cron_runs for the admin "Cron / Tasks" page.
 */
final class CronService
{
    public const TASKS = [
        'orders' => ['label' => 'Submit queued orders & sync order statuses', 'schedule' => '*/3 * * * *', 'interval' => 3, 'stale_after' => 30],
        'subscriptions' => ['label' => 'Place due auto-subscription orders (retries with back-off)', 'schedule' => '* * * * *', 'interval' => 1, 'stale_after' => 15],
        'refills' => ['label' => 'Sync refill statuses', 'schedule' => '*/15 * * * *', 'interval' => 15, 'stale_after' => 90],
        'payments' => ['label' => 'Verify pending payments & expire invoices', 'schedule' => '*/5 * * * *', 'interval' => 5, 'stale_after' => 30],
        'provider_sync' => ['label' => 'Refresh provider catalogs, prices & balances', 'schedule' => '0 */6 * * *', 'interval' => 360, 'stale_after' => 60 * 13],
        'notifications' => ['label' => 'Send queued emails', 'schedule' => '* * * * *', 'interval' => 1, 'stale_after' => 15],
        'cleanup' => ['label' => 'Prune old logs, rate limits and tokens', 'schedule' => '30 3 * * *', 'interval' => 1440, 'stale_after' => 60 * 49],
    ];

    /** cron_runs row of the task currently executing in this process (for fatal-error recording). */
    private static ?int $currentRunId = null;

    /**
     * Run one task under its named lock. With $onlyIfDue the schedule is checked
     * again *after* the lock is acquired, so two overlapping schedulers can never
     * run the same task back-to-back.
     */
    public static function run(string $task, bool $onlyIfDue = false): array
    {
        if (!isset(self::TASKS[$task])) {
            throw new \InvalidArgumentException("Unknown cron task {$task}");
        }
        $db = Database::instance();
        $lock = 'smm_cron_' . $task;
        $start = microtime(true);
        if (!$db->acquireLock($lock, 0)) {
            $db->insert('cron_runs', ['task' => $task, 'status' => 'skipped', 'output' => 'Previous run still in progress', 'started_at' => now(), 'finished_at' => now()]);
            return ['status' => 'skipped'];
        }
        try {
            if ($onlyIfDue && !self::isDue($task)) {
                return ['status' => 'not_due'];
            }
            // We hold the lock, so any row still "running" belongs to a process that died.
            $db->query("UPDATE cron_runs SET status = 'failed', output = ?, finished_at = ? WHERE task = ? AND status = 'running'", [json_encode(['error' => 'Interrupted: the process ended before finishing (PHP timeout, memory limit or killed)']), now(), $task]);
            $runId = self::$currentRunId = $db->insert('cron_runs', ['task' => $task, 'status' => 'running', 'started_at' => now()]);
            try {
                @set_time_limit(300);
                $output = self::execute($task);
                $status = 'success';
            } catch (\Throwable $e) {
                $output = ['error' => $e->getMessage()];
                $status = 'failed';
                Logger::error("Cron {$task} failed: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => substr($e->getTraceAsString(), 0, 2000)], 'cron');
            }
            $db->update('cron_runs', [
                'status' => $status,
                'output' => mb_substr(json_encode($output, JSON_UNESCAPED_SLASHES), 0, 5000),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'finished_at' => now(),
            ], ['id' => $runId]);
            self::$currentRunId = null;
            return ['status' => $status, 'output' => $output];
        } finally {
            $db->releaseLock($lock);
        }
    }

    /** Called from the cron shutdown handler after a fatal error inside a task. */
    public static function abortCurrent(string $error): void
    {
        if (self::$currentRunId === null) {
            return;
        }
        try {
            Database::instance()->update('cron_runs', ['status' => 'failed', 'output' => mb_substr(json_encode(['error' => $error], JSON_UNESCAPED_SLASHES), 0, 5000), 'finished_at' => now()], ['id' => self::$currentRunId]);
            Logger::error('Cron task aborted: ' . $error, ['run' => self::$currentRunId], 'cron');
        } catch (\Throwable) {
            // nothing more we can do during shutdown
        }
        self::$currentRunId = null;
    }

    public static function isDue(string $task): bool
    {
        $last = Database::instance()->fetchColumn("SELECT started_at FROM cron_runs WHERE task = ? AND status <> 'skipped' ORDER BY id DESC LIMIT 1", [$task]);
        return !$last || strtotime($last . ' UTC') <= time() - (self::TASKS[$task]['interval'] * 60) + 20;
    }

    private static function execute(string $task): array
    {
        $db = Database::instance();
        switch ($task) {
            case 'orders':
                return [
                    'stuck_to_unknown' => ProviderSyncService::recoverStuckSubmissions(),
                    'submit' => ProviderSyncService::submitQueued(50),
                    'sync' => ProviderSyncService::syncOrderStatuses(max(10, (int) setting('order_sync_batch', '100'))),
                ];
            case 'subscriptions':
                return SubscriptionService::processDue(50);
            case 'refills':
                return ProviderSyncService::syncRefills();
            case 'payments':
                return PaymentService::verifyPending();
            case 'provider_sync':
                $out = [];
                foreach ($db->fetchAll("SELECT id, name FROM providers WHERE status = 'active'") as $p) {
                    $out[$p['name']]['balance'] = ProviderSyncService::checkProvider((int) $p['id']);
                    try {
                        $out[$p['name']]['catalog'] = ProviderSyncService::fetchCatalog((int) $p['id']);
                    } catch (\Throwable $e) {
                        $out[$p['name']]['catalog'] = ['error' => $e->getMessage()];
                    }
                }
                $out['prices'] = ProviderSyncService::syncServicePrices();
                return $out;
            case 'notifications':
                return MailService::processQueue(40);
            case 'cleanup':
                $d = static fn (int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
                return [
                    'rate_limits' => RateLimiter::prune(),
                    'login_attempts' => $db->query('DELETE FROM login_attempts WHERE created_at < ?', [$d(90)])->rowCount(),
                    'api_logs' => $db->query('DELETE FROM api_logs WHERE created_at < ?', [$d(60)])->rowCount(),
                    'provider_logs' => $db->query('DELETE FROM provider_logs WHERE created_at < ?', [$d(30)])->rowCount(),
                    'webhook_logs' => $db->query('DELETE FROM webhook_logs WHERE created_at < ?', [$d(180)])->rowCount(),
                    'password_resets' => $db->query('DELETE FROM password_resets WHERE expires_at < ?', [$d(1)])->rowCount(),
                    'email_verifications' => $db->query('DELETE FROM email_verifications WHERE expires_at < ?', [$d(1)])->rowCount(),
                    'email_queue' => $db->query("DELETE FROM email_queue WHERE status IN ('sent','failed') AND created_at < ?", [$d(30)])->rowCount(),
                    'cron_runs' => $db->query('DELETE FROM cron_runs WHERE started_at < ?', [$d(14)])->rowCount(),
                    'notifications' => $db->query('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < ?', [$d(180)])->rowCount(),
                    'log_files' => self::pruneLogFiles(30),
                ];
        }
        return [];
    }

    private static function pruneLogFiles(int $days): int
    {
        $n = 0;
        foreach (glob(STORAGE_PATH . '/logs/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400 && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Run every task whose interval has elapsed since its last start. Designed
     * to be called by ONE cron line every minute (or every 5 minutes).
     * @return array<string, array>
     */
    public static function runDue(): array
    {
        $out = [];
        foreach (array_keys(self::TASKS) as $task) {
            if (self::isDue($task)) {
                $r = self::run($task, true);
                if ($r['status'] !== 'not_due') {
                    $out[$task] = $r;
                }
            }
        }
        return $out;
    }

    /** Last run, last success and last failure per task for the admin pages. */
    public static function status(): array
    {
        $db = Database::instance();
        $out = [];
        foreach (self::TASKS as $task => $def) {
            $last = $db->fetch("SELECT * FROM cron_runs WHERE task = ? AND status <> 'skipped' ORDER BY id DESC LIMIT 1", [$task]);
            $stale = !$last || strtotime($last['started_at'] . ' UTC') < time() - $def['stale_after'] * 60;
            $out[$task] = $def + [
                'due' => self::isDue($task),
                'last' => $last,
                'last_success' => $db->fetchColumn("SELECT finished_at FROM cron_runs WHERE task = ? AND status = 'success' ORDER BY id DESC LIMIT 1", [$task]) ?: null,
                'last_failure' => $db->fetch("SELECT started_at, output FROM cron_runs WHERE task = ? AND status = 'failed' ORDER BY id DESC LIMIT 1", [$task]) ?: null,
                'stale' => $stale,
            ];
        }
        return $out;
    }

    /**
     * Environment checks shared by `php cron/run.php --check` and Admin → Cron tasks.
     * @return list<array{0:string,1:bool,2:string}> [label, ok, detail]
     */
    public static function diagnose(): array
    {
        $checks = [];
        $checks[] = ['PHP version ≥ ' . \App\Core\Requirements::MIN_PHP, PHP_VERSION_ID >= \App\Core\Requirements::MIN_PHP_ID, PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . PHP_BINARY];
        $missing = \App\Core\Requirements::missingExtensions();
        $checks[] = ['Required extensions', $missing === [], $missing ? 'missing: ' . implode(', ', $missing) : implode(', ', \App\Core\Requirements::EXTENSIONS)];
        foreach (['storage', 'storage/logs', 'storage/cache', 'storage/sessions'] as $dir) {
            $checks[] = ["Writable: {$dir}", is_dir(BASE_PATH . '/' . $dir) && is_writable(BASE_PATH . '/' . $dir), BASE_PATH . '/' . $dir];
        }
        $checks[] = ['Installed', \App\Core\App::isInstalled(), STORAGE_PATH . '/installed.lock'];
        try {
            $db = Database::instance();
            $dbNow = (string) $db->fetchColumn('SELECT UTC_TIMESTAMP()');
            $skew = abs(strtotime($dbNow . ' UTC') - time());
            $checks[] = ['Database connection', true, (string) $db->fetchColumn('SELECT VERSION()')];
            $checks[] = ['Clock: PHP vs database (UTC)', $skew <= 120, "difference {$skew}s (schedules compare both)"];
            $probe = 'smm_cron_diag_' . getmypid();
            $ok = $db->acquireLock($probe, 0);
            if ($ok) {
                $db->releaseLock($probe);
            }
            $checks[] = ['Named locks (GET_LOCK)', $ok, $ok ? 'available — overlapping runs are prevented' : 'GET_LOCK is not available'];
            $cols = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cron_runs'");
            $checks[] = ['cron_runs table', $cols === 1, $cols === 1 ? 'present' : 'missing — run database/migrate.php'];
        } catch (\Throwable $e) {
            $checks[] = ['Database connection', false, $e->getMessage()];
        }
        return $checks;
    }
}
