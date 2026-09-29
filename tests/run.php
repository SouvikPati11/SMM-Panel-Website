<?php

declare(strict_types=1);

/*
 * Test runner:  php tests/run.php [filter]
 * Requires a disposable MySQL/MariaDB database (see tests/bootstrap.php).
 */

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    echo "\n" . basename($file, '.php') . "\n";
    require $file;
}

echo "\n" . T::$pass . ' passed, ' . count(T::$fail) . " failed\n";
if (T::$fail) {
    echo "Failed:\n - " . implode("\n - ", T::$fail) . "\n";
    exit(1);
}
