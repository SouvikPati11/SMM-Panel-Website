<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime platform requirements, enforced by the application itself on every
 * request/CLI run (app/bootstrap.php) and shown by the installer.
 *
 * They are deliberately NOT declared as platform requirements in composer.json:
 * hosts such as Hostinger run `composer install` during Git deployment with a
 * CLI PHP that may differ from the web PHP, and a missing CLI extension would
 * block the whole deployment. Composer is optional; this class is the source
 * of truth.
 */
final class Requirements
{
    public const MIN_PHP_ID = 80100;
    public const MIN_PHP = '8.1';

    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'curl', 'openssl', 'fileinfo', 'sodium', 'ctype', 'dom'];

    /** @return list<string> required extensions that are not loaded */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(self::EXTENSIONS, static fn (string $ext): bool => !extension_loaded($ext)));
    }
}
