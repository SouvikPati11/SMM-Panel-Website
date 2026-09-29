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
        'orders' => ['label' => 'Submit queued orders & sync order statuses', 'schedule' => '*/5 * * * *', 'stale_after' => 30],
        'refills' => ['label' => 'Sync refill statuses', 'schedule' => '*/15 * * * *', 'stale_after' => 90],
        'payments' => ['label' => 'Verify pending payments & expire invoices', 'schedule' => '*/5 * * * *', 'stale_after' => 30],
        'provider_sync' => ['label' => 'Refresh provider catalogs, prices & balances', 'schedule' => '0 */6 * * *', 'stale_after' => 60 * 13],
        'notifications' => ['label' => 'Send queued emails', 'schedule' => '* * * * *', 'stale_after' => 15],
        'cleanup' => ['label' => 'Prune old logs, rate limits and tokens', 'schedule' => '30 3 * * *', 'stale_after' => 60 * 49],
    ];

    public static function run(string $task): array
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
        $runId = $db->insert('cron_runs', ['task' => $task, 'status' => 'running', 'started_at' => now()]);
        try {
            @set_time_limit(300);
            $output = self::execute($task);
            $status = 'success';
        } catch (\Throwable $e) {
            $output = ['error' => $e->getMessage()];
            $status = 'failed';
            Logger::error("Cron {$task} failed: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()], 'cron');
        } finally {
            $db->releaseLock($lock);
        }
        $db->update('cron_runs', [
            'status' => $status,
            'output' => mb_substr(json_encode($output, JSON_UNESCAPED_SLASHES), 0, 5000),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'finished_at' => now(),
        ], ['id' => $runId]);
        return ['status' => $status, 'output' => $output];
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

    /** Last run per task for the admin health page. */
    public static function status(): array
    {
        $db = Database::instance();
        $out = [];
        foreach (self::TASKS as $task => $def) {
            $last = $db->fetch("SELECT * FROM cron_runs WHERE task = ? AND status <> 'skipped' ORDER BY id DESC LIMIT 1", [$task]);
            $stale = !$last || strtotime($last['started_at'] . ' UTC') < time() - $def['stale_after'] * 60;
            $out[$task] = $def + ['last' => $last, 'stale' => $stale];
        }
        return $out;
    }
}
