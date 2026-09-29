<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $items = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$items === null) {
            self::$items = require BASE_PATH . '/config/app.php';
        }
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        self::get('name'); // ensure loaded
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = $ref[$segment] ?? [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function reset(): void
    {
        self::$items = null;
    }
}
