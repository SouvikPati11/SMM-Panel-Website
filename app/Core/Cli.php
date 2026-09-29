<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Command-line helpers that also work when a script is started by cron through
 * a non-CLI PHP binary (lsphp / php-cgi, common on LiteSpeed and cPanel hosts).
 *
 * The SAPI name alone cannot tell "started by cron" from "requested over HTTP":
 * lsphp reports PHP_SAPI = "litespeed" in both cases. An HTTP request always
 * carries REQUEST_METHOD; a command-line run never does.
 */
final class Cli
{
    /** SAPIs that are only ever used from a terminal / cron. */
    private const COMMAND_LINE_SAPIS = ['cli', 'phpdbg', 'embed'];

    public static function isCommandLine(?string $sapi = null, ?array $server = null): bool
    {
        $sapi ??= PHP_SAPI;
        $server ??= $_SERVER;
        if (in_array($sapi, self::COMMAND_LINE_SAPIS, true)) {
            return true;
        }
        if ($sapi === 'cli-server') {
            return false; // PHP's built-in web server: always HTTP
        }
        return empty($server['REQUEST_METHOD']) && empty($server['HTTP_HOST']) && empty($server['GATEWAY_INTERFACE']);
    }

    /** True when running from the command line through a web SAPI binary (lsphp, php-cgi). */
    public static function isNonCliBinary(?string $sapi = null): bool
    {
        return !in_array($sapi ?? PHP_SAPI, self::COMMAND_LINE_SAPIS, true);
    }

    public static function stderr(string $line): void
    {
        $h = defined('STDERR') ? STDERR : @fopen('php://stderr', 'wb');
        if ($h) {
            fwrite($h, rtrim($line, "\n") . PHP_EOL);
        } else {
            echo rtrim($line, "\n") . PHP_EOL;
        }
    }

    public static function stdout(string $line): void
    {
        $h = defined('STDOUT') ? STDOUT : @fopen('php://stdout', 'wb');
        if ($h) {
            fwrite($h, rtrim($line, "\n") . PHP_EOL);
        } else {
            echo rtrim($line, "\n") . PHP_EOL;
        }
    }

    /** @return list<string> command-line arguments without the script name (register_argc_argv may be off under lsphp) */
    public static function args(): array
    {
        $argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
        return array_values(array_map('strval', array_slice(is_array($argv) ? $argv : [], 1)));
    }

    /**
     * Best guess for the PHP *CLI* binary matching the PHP version serving the
     * website. The web process runs lsphp/php-fpm, so PHP_BINARY there is the
     * wrong thing to put in a cron job.
     * @return list<string> existing candidates, most specific first
     */
    public static function cliBinaryCandidates(): array
    {
        $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;           // e.g. 83
        $dotted = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; // e.g. 8.3
        $candidates = [];
        $bin = (string) PHP_BINARY;
        if ($bin !== '' && preg_match('~(^|/)(lsphp|php-cgi|php-fpm)[\d.]*$~', $bin)) {
            $candidates[] = dirname($bin) . '/php';
        } elseif ($bin !== '' && self::isCommandLine() && !self::isNonCliBinary()) {
            $candidates[] = $bin;
        }
        array_push(
            $candidates,
            "/opt/alt/php{$v}/usr/bin/php",          // CloudLinux alt-php (Hostinger, many cPanel hosts)
            "/usr/local/lsws/lsphp{$v}/bin/php",     // LiteSpeed-bundled PHP CLI
            "/opt/cpanel/ea-php{$v}/root/usr/bin/php", // cPanel EasyApache
            PHP_BINDIR . '/php',
            "/usr/bin/php{$dotted}",
            '/usr/local/bin/php',
            '/usr/bin/php'
        );
        $out = [];
        foreach (array_unique($candidates) as $c) {
            if (@is_file($c) && @is_executable($c) && !preg_match('~(lsphp|php-cgi|php-fpm)[\d.]*$~', $c)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** The binary to recommend for cron: first existing candidate, else the conventional /usr/bin/php. */
    public static function recommendedCliBinary(): string
    {
        return self::cliBinaryCandidates()[0] ?? '/usr/bin/php';
    }
}
