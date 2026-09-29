<?php

declare(strict_types=1);

use App\Core\Requirements;
use App\Install\Installer;

/*
 * Composer must stay optional: a host's automatic `composer install` (Hostinger
 * Git deployment) must never fail on platform requirements, and the app must
 * boot identically with or without vendor/. Requirements are enforced at runtime.
 */

$php = static fn (string $args): array => [exec(escapeshellarg(PHP_BINARY) . ' ' . $args . ' 2>&1', $out, $code), $code, implode("\n", $out)];

T::test('Deploy: composer.json declares no platform requirements and no duplicate autoload files', function () {
    $c = json_decode((string) file_get_contents(BASE_PATH . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['require', 'require-dev'] as $section) {
        foreach (array_keys($c[$section] ?? []) as $pkg) {
            T::true($pkg !== 'php' && !str_starts_with($pkg, 'ext-') && !str_starts_with($pkg, 'lib-'), "{$section} must not contain platform requirement {$pkg}");
        }
    }
    T::eq(false, $c['config']['platform-check'] ?? null, 'no generated vendor/composer/platform_check.php');
    T::true(empty($c['autoload']['files']), 'helpers are loaded by app/bootstrap.php only');
    T::eq(['App\\' => 'app/'], $c['autoload']['psr-4']);
    $lock = json_decode((string) file_get_contents(BASE_PATH . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    T::eq([], $lock['packages']);
    T::eq([], (array) $lock['platform']);
    T::eq(Requirements::EXTENSIONS, $c['extra']['runtime-requirements']['extensions'], 'documented list matches the enforced list');
});

T::test('Deploy: runtime requirements are a single enforced list, all present here', function () {
    T::eq(Requirements::EXTENSIONS, Installer::REQUIRED_EXTENSIONS);
    T::eq([], Requirements::missingExtensions());
    T::true(PHP_VERSION_ID >= Requirements::MIN_PHP_ID);
});

T::test('Deploy: bootstrap still refuses to run when a required extension is missing', function () use ($php) {
    // `php -n` loads no ini files, so shared extensions are absent.
    [, $code, $out] = $php('-n -r ' . escapeshellarg('echo json_encode(get_loaded_extensions());'));
    $loaded = array_map('strtolower', json_decode($out, true) ?: []);
    $missing = array_values(array_diff(Requirements::EXTENSIONS, $loaded));
    if ($missing === []) {
        echo "    (skipped: every required extension is compiled into this PHP)\n";
        return;
    }
    [, $code, $out] = $php('-n -r ' . escapeshellarg('require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . '; echo "BOOTED";'));
    T::eq(1, $code, $out);
    T::true(!str_contains($out, 'BOOTED'), 'must not boot');
    foreach ($missing as $ext) {
        T::true(str_contains($out, $ext), "message names {$ext}: {$out}");
    }
});

T::test('Deploy: bootstrap tolerates helpers already loaded by a Composer autoloader', function () use ($php) {
    $code = 'require ' . var_export(BASE_PATH . '/app/Helpers/functions.php', true) . '; require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . '; echo function_exists("e") && class_exists("App\\\\Core\\\\Money") ? "BOOTED" : "NO";';
    [, $exit, $out] = $php('-r ' . escapeshellarg($code));
    T::eq(0, $exit, $out);
    T::eq('BOOTED', trim($out));
});
