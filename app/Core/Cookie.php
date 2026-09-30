<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cookies set by services during a request (e.g. "remember me"). They are
 * queued and attached to whatever Response the request produces, so they also
 * reach redirects and error responses, and tests can inspect them.
 */
final class Cookie
{
    /** @var list<array{name:string,value:string,options:array}> */
    private static array $queue = [];

    public static function queue(string $name, string $value, int $expires, Request $request): void
    {
        $basePath = rtrim((string) parse_url((string) Config::get('url', ''), PHP_URL_PATH), '/');
        self::$queue[] = ['name' => $name, 'value' => $value, 'options' => [
            'expires' => $expires,
            'path' => ($basePath ?: '') . '/',
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]];
    }

    public static function forget(string $name, Request $request): void
    {
        self::queue($name, '', time() - 3600, $request);
    }

    public static function attach(Response $response): Response
    {
        foreach (self::$queue as $c) {
            $response->withCookie($c['name'], $c['value'], $c['options']);
        }
        self::$queue = [];
        return $response;
    }

    public static function reset(): void
    {
        self::$queue = [];
    }
}
