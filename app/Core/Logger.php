<?php

declare(strict_types=1);

namespace App\Core;

/**
 * File logger with channels (app, payment, provider, api, webhook, cron, security).
 * Files: storage/logs/{channel}-YYYY-MM-DD.log. Secrets are redacted.
 */
final class Logger
{
    private const REDACT_KEYS = ['password', 'pass', 'key', 'api_key', 'secret', 'token', 'sign', 'hmac', 'merchant_api_key', 'authorization', 'cookie', 'twofa_secret'];

    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'app';
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? ' ' . json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log($channel, 'error', $message, $context);
    }

    public static function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log($channel, 'warning', $message, $context);
    }

    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::log($channel, 'info', $message, $context);
    }

    public static function redact(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT_KEYS, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif ($v instanceof \Throwable) {
                $data[$k] = get_class($v) . ': ' . $v->getMessage() . ' @ ' . $v->getFile() . ':' . $v->getLine();
            }
        }
        return $data;
    }

    /** @return list<string> available log files, newest first */
    public static function files(): array
    {
        $files = glob(STORAGE_PATH . '/logs/*.log') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map('basename', $files);
    }

    /** Return the last $lines lines of a log file (safe basename only). */
    public static function tail(string $file, int $lines = 300): string
    {
        $file = basename($file);
        $path = STORAGE_PATH . '/logs/' . $file;
        if (!preg_match('/^[a-z0-9_-]+-\d{4}-\d{2}-\d{2}\.log$/i', $file) || !is_file($path)) {
            return '';
        }
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        $chunk = min($size, 512 * 1024);
        fseek($fh, -$chunk, SEEK_END);
        $data = fread($fh, $chunk) ?: '';
        fclose($fh);
        $all = explode("\n", rtrim($data));
        return implode("\n", array_slice($all, -$lines));
    }
}
