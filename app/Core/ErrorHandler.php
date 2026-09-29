<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use Throwable;

/**
 * Converts PHP errors to exceptions, logs everything, and never shows raw
 * errors to visitors unless APP_DEBUG=true.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Deprecations must never break a live site (e.g. after a host PHP upgrade): log only.
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning('Deprecated: ' . $message, ['file' => $file, 'line' => $line]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    echo self::genericPage(500, 'Something went wrong', 'An unexpected error occurred. Please try again shortly.');
                }
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->getStatus() : 500;
        if ($status >= 500) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'uri'  => $_SERVER['REQUEST_URI'] ?? 'cli',
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ]);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            exit(1);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
        }

        $debug = (bool) Config::get('debug', false);
        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');

        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => $status >= 500 ? 'Internal server error' : $e->getMessage()]);
            return;
        }

        [$title, $text] = match ($status) {
            403 => ['Access denied', $e->getMessage() ?: 'You do not have permission to view this page.'],
            404 => ['Page not found', 'The page you are looking for does not exist or has been moved.'],
            405 => ['Method not allowed', 'This action is not allowed.'],
            419 => ['Session expired', 'Your session expired. Please go back, refresh the page and try again.'],
            429 => ['Too many requests', $e->getMessage() ?: 'Please slow down and try again in a moment.'],
            503 => ['Maintenance', $e->getMessage() ?: 'We are performing scheduled maintenance. Please check back soon.'],
            default => ['Something went wrong', 'An unexpected error occurred. Our team has been notified.'],
        };

        if ($debug && $status >= 500) {
            $text .= "\n\n" . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        }

        try {
            if (is_file(VIEW_PATH . '/errors/error.php')) {
                echo View::render('errors/error', ['status' => $status, 'title' => $title, 'text' => $text]);
                return;
            }
        } catch (Throwable) {
            // fall through to the static page
        }
        echo self::genericPage($status, $title, $text);
    }

    public static function genericPage(int $status, string $title, string $text): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        $m = nl2br(htmlspecialchars($text, ENT_QUOTES));
        return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$t}</title>"
            . "<style>body{font-family:system-ui,sans-serif;background:#f6f7fb;color:#1a1d29;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}"
            . ".b{max-width:520px;background:#fff;border-radius:16px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.06)}h1{margin:0 0 8px;font-size:22px}p{color:#5b6275;line-height:1.6}"
            . "code{font-size:12px;white-space:pre-wrap}</style></head><body><div class=\"b\"><div style=\"font-size:13px;color:#6b5cff;font-weight:600\">Error {$status}</div><h1>{$t}</h1><p>{$m}</p><p><a href=\"/\">Back to home</a></p></div></body></html>";
    }
}
