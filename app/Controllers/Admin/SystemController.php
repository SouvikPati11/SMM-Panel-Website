<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Install\Installer;
use App\Install\Migrator;
use App\Services\AuditService;
use App\Services\CronService;

final class SystemController extends Controller
{
    public function logs(Request $request): Response
    {
        $type = in_array($request->str('type'), ['audit', 'api', 'webhook', 'login', 'files'], true) ? $request->str('type') : 'audit';
        $page = $this->pageNum($request);
        $q = mb_substr($request->str('q'), 0, 100);
        $data = ['title' => 'Logs', 'type' => $type, 'q' => $q];
        switch ($type) {
            case 'audit':
                $where = $q !== '' ? 'WHERE l.action LIKE ? OR l.target_id = ?' : '';
                $data['rows'] = Paginator::query(
                    "l.*, CASE l.actor_type WHEN 'admin' THEN a.username WHEN 'user' THEN u.username ELSE 'system' END AS actor",
                    "FROM audit_logs l LEFT JOIN admins a ON l.actor_type = 'admin' AND a.id = l.actor_id LEFT JOIN users u ON l.actor_type = 'user' AND u.id = l.actor_id {$where}",
                    $q !== '' ? [Database::like($q), $q] : [],
                    'l.id DESC',
                    $page,
                    50
                );
                break;
            case 'api':
                $data['rows'] = Paginator::query('l.*, u.username', 'FROM api_logs l LEFT JOIN users u ON u.id = l.user_id', [], 'l.id DESC', $page, 50);
                break;
            case 'webhook':
                $data['rows'] = Paginator::query('*', 'FROM webhook_logs', [], 'id DESC', $page, 30);
                break;
            case 'login':
                $data['rows'] = Paginator::query('*', 'FROM login_attempts' . ($q !== '' ? ' WHERE identifier LIKE ? OR ip = ?' : ''), $q !== '' ? [Database::like($q), $q] : [], 'id DESC', $page, 50);
                break;
            case 'files':
                $data['files'] = Logger::files();
                $file = $request->str('file');
                $data['file'] = in_array($file, $data['files'], true) ? $file : ($data['files'][0] ?? '');
                $data['content'] = $data['file'] ? Logger::tail($data['file'], 400) : '';
                break;
        }
        return $this->view('admin/system/logs', $data);
    }

    public function health(Request $request): Response
    {
        $db = Database::instance();
        $checks = Installer::checks();
        $checks[] = ['APP_DEBUG disabled', !Config::get('debug'), 'Debug mode shows stack traces — keep it off in production.', true];
        $checks[] = ['APP_KEY set', strlen((string) Config::get('key')) >= 32, 'Used to encrypt credentials.', true];
        $checks[] = ['Installer locked', is_file(STORAGE_PATH . '/installed.lock'), '', true];
        $checks[] = ['HTTPS URL', str_starts_with((string) Config::get('url'), 'https://'), 'APP_URL should use https://', false];
        $checks[] = ['Admin path customised', admin_path() !== 'admin', 'A non-default ADMIN_PATH reduces automated attacks.', false];
        $checks[] = ['.env not web-readable', !is_file(PUBLIC_PATH . '/.env'), '.env must live outside /public.', true];
        $superWith2fa = (int) $db->fetchColumn("SELECT COUNT(*) FROM admins WHERE status = 'active' AND twofa_enabled = 0");
        $checks[] = ['All admins use 2FA', $superWith2fa === 0, $superWith2fa . ' active admin(s) without 2FA.', false];
        $pending = Migrator::pending();
        $checks[] = ['Database schema up to date', $pending === [], 'Pending upgrades: ' . implode(', ', $pending) . ' — use "Apply database upgrades" below or run php database/migrate.php.', true];

        $info = [
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Database' => (string) $db->fetchColumn('SELECT VERSION()'),
            'DB size' => round((float) $db->fetchColumn('SELECT SUM(data_length + index_length) / 1048576 FROM information_schema.tables WHERE table_schema = DATABASE()'), 1) . ' MB',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'Server time (UTC)' => gmdate('Y-m-d H:i:s'),
            'Decimal engine' => function_exists('bcadd') ? 'bcmath' : 'pure-PHP fallback',
            'Disk free' => is_callable('disk_free_space') && ($f = @disk_free_space(BASE_PATH)) ? round($f / 1073741824, 1) . ' GB' : 'n/a',
        ];
        $counts = [
            'Orders awaiting submission' => (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE submit_state = 'queued' AND provider_id IS NOT NULL"),
            'Orders needing review' => (int) $db->fetchColumn('SELECT COUNT(*) FROM orders WHERE needs_attention = 1'),
            'Emails queued' => (int) $db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'queued'"),
            'Emails failed' => (int) $db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'"),
            'Pending payments' => (int) $db->fetchColumn("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'Provider errors (24h)' => (int) $db->fetchColumn('SELECT COUNT(*) FROM provider_logs WHERE success = 0 AND created_at > ?', [gmdate('Y-m-d H:i:s', time() - 86400)]),
            'Rejected webhooks (24h)' => (int) $db->fetchColumn('SELECT COUNT(*) FROM webhook_logs WHERE signature_valid = 0 AND created_at > ?', [gmdate('Y-m-d H:i:s', time() - 86400)]),
        ];
        return $this->view('admin/system/health', ['title' => 'System health', 'checks' => $checks, 'info' => $info, 'counts' => $counts, 'cron' => CronService::status(), 'pendingMigrations' => $pending]);
    }

    public function migrate(Request $request): Response
    {
        $applied = Migrator::run();
        AuditService::log('system.migrate', 'system', null, ['applied' => $applied]);
        $this->success($applied ? 'Applied: ' . implode(', ', $applied) . '.' : 'Database schema is already up to date.');
        return Response::redirect(admin_url('health'));
    }

    public function cron(Request $request): Response
    {
        return $this->view('admin/system/cron', [
            'title' => 'Cron tasks',
            'tasks' => CronService::status(),
            'runs' => Database::instance()->fetchAll('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 40'),
            'php' => PHP_BINARY ?: '/usr/local/bin/php',
            'base' => BASE_PATH,
        ]);
    }

    public function runCron(Request $request, string $task): Response
    {
        if (!isset(CronService::TASKS[$task])) {
            throw new ValidationException('Unknown task.');
        }
        $r = CronService::run($task);
        AuditService::log('cron.run', 'cron', $task, ['status' => $r['status']]);
        $r['status'] === 'success' ? $this->success("Task '{$task}' completed.") : $this->error("Task '{$task}': {$r['status']}" . (isset($r['output']['error']) ? ' — ' . $r['output']['error'] : ''));
        return Response::redirect(admin_url('cron'));
    }
}
