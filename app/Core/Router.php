<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Small regex router with groups, per-route middleware and named routes.
 * Route params: {id} matches digits, {slug} matches [a-z0-9-], {any} matches anything but '/'.
 */
final class Router
{
    private array $routes = [];
    private array $named = [];
    private array $groupStack = [];

    public function get(string $path, array|\Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $name, $middleware);
    }

    public function post(string $path, array|\Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add(['POST'], $path, $handler, $name, $middleware);
    }

    public function any(string $path, array|\Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add(['GET', 'HEAD', 'POST'], $path, $handler, $name, $middleware);
    }

    /** @param array{prefix?:string, middleware?:list<string>} $attrs */
    public function group(array $attrs, callable $fn): void
    {
        $this->groupStack[] = $attrs;
        $fn($this);
        array_pop($this->groupStack);
    }

    private function add(array $methods, string $path, array|\Closure $handler, ?string $name, array $middleware): void
    {
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= '/' . trim($g['prefix'] ?? '', '/');
            $groupMw = array_merge($groupMw, $g['middleware'] ?? []);
        }
        $full = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . trim($path, '/')), '/');

        $regex = preg_replace_callback('/\{(\w+)\}/', static function ($m) {
            $pattern = match ($m[1]) {
                'id', 'page' => '\d+',
                'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
                'token' => '[A-Za-z0-9]+',
                default => '[^/]+',
            };
            return '(?P<' . $m[1] . '>' . $pattern . ')';
        }, $full);

        $route = [
            'methods' => $methods,
            'path' => $full,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => array_merge($groupMw, $middleware),
        ];
        $this->routes[] = $route;
        if ($name) {
            $this->named[$name] = $full;
        }
    }

    /** @return array{0: array, 1: array<string,string>} */
    public function match(Request $request): array
    {
        $path = $request->path();
        $allowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if (!in_array($request->method(), $route['methods'], true)) {
                    $allowed = true;
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$route, $params];
            }
        }
        throw new HttpException($allowed ? 405 : 404);
    }

    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Unknown route: {$name}");
        }
        $path = preg_replace_callback('/\{(\w+)\}/', static function ($m) use (&$params) {
            $v = $params[$m[1]] ?? '';
            unset($params[$m[1]]);
            return rawurlencode((string) $v);
        }, $this->named[$name]);
        return $path . ($params ? '?' . http_build_query($params) : '');
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }
}
