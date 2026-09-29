<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Last-invocation diagnostics for cron, kept in storage/cron-status.json.
 *
 * A file (not the database) on purpose: it must also capture failures that
 * happen before the database is reachable — wrong PHP version, missing
 * extension, bad credentials. cron/bootstrap.php writes the same file with
 * plain PHP before the application is loaded.
 */
final class CronStatus
{
    public static function path(): string
    {
        return STORAGE_PATH . '/cron-status.json';
    }

    /** Result of the last `php cron/run.php --check` (diagnostic runs are kept apart). */
    public static function readCheck(): array
    {
        $raw = @file_get_contents(STORAGE_PATH . '/cron-check.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public static function read(): array
    {
        $raw = @file_get_contents(self::path());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Merge $fields into the stored status (atomic replace; last writer wins). */
    public static function write(array $fields): void
    {
        $data = array_merge(self::read(), $fields);
        $tmp = self::path() . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, self::path());
        }
        @unlink($tmp);
    }

    /**
     * Human-readable problems for the admin page.
     * @return list<array{level:string,text:string}>
     */
    public static function problems(array $status, array $tasks): array
    {
        $out = [];
        $last = isset($status['invoked_at']) ? strtotime($status['invoked_at'] . ' UTC') : null;
        if (!$last) {
            $out[] = ['level' => 'danger', 'text' => 'Cron has never run on this installation. Add the cron job shown below, then check back in a few minutes.'];
        } elseif ($last < time() - 15 * 60) {
            $out[] = ['level' => 'danger', 'text' => 'Cron last ran ' . time_ago($status['invoked_at']) . '. It should run every 1–5 minutes: check that the cron job still exists and uses the PHP CLI binary.'];
        }
        if (!empty($status['exit_code']) && !empty($status['error'])) {
            $out[] = ['level' => 'danger', 'text' => 'The last cron run failed (exit code ' . (int) $status['exit_code'] . '): ' . $status['error']];
        }
        if (!empty($status['sapi']) && !in_array($status['sapi'], ['cli', 'phpdbg'], true)) {
            $out[] = ['level' => 'warning', 'text' => 'Cron is running through the "' . $status['sapi'] . '" PHP binary (' . ($status['php_binary'] ?? '?') . ') instead of the PHP CLI. It works, but web binaries such as lsphp have web time limits; use the CLI command shown below.'];
        }
        $cronMinor = implode('.', array_slice(explode('.', (string) ($status['php_version'] ?? '')), 0, 2));
        if ($cronMinor !== '' && $cronMinor !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            $out[] = ['level' => 'warning', 'text' => 'Cron uses PHP ' . $status['php_version'] . ' but the website runs PHP ' . PHP_VERSION . '. Use the CLI binary of the same version (shown below).'];
        }
        foreach ($tasks as $name => $t) {
            if (($t['last']['status'] ?? null) === 'failed') {
                $out[] = ['level' => 'warning', 'text' => "Task '{$name}' failed at " . fmt_date($t['last']['started_at']) . ': ' . str_limit((string) $t['last']['output'], 200)];
            }
        }
        return $out;
    }
}
