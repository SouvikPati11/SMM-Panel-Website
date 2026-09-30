<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $attributes = [];
    private ?string $rawBody = null;

    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $post,
        private array $files,
        private array $server,
        private array $cookies = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        // Support installation in a sub-directory: strip the base path of APP_URL.
        $basePath = rtrim((string) parse_url((string) Config::get('url', ''), PHP_URL_PATH), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        // When the whole project sits in public_html, Apache rewrites to /public/...
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }
        $path = '/' . trim($path, '/');

        $post = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($json)) {
                $post = $json;
            }
        }

        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $post, $_FILES, $_SERVER, $_COOKIE);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Path plus the query string, e.g. "/admin/settings?tab=orders" (for returning to the same page). */
    public function pathWithQuery(): string
    {
        return $this->path . ($this->query ? '?' . http_build_query($this->query) : '');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    /** String input only (arrays rejected) — protects against type-juggling via param[]= */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->str($key, '');
        return preg_match('/^-?\d{1,18}$/', $v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->str($key), ['1', 'on', 'true', 'yes'], true);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function post(): array
    {
        return $this->post;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** @param list<string> $keys */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->str($k);
        }
        return $out;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function headers(): array
    {
        $out = [];
        foreach ($this->server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $out[str_replace('_', '-', ucwords(strtolower(substr($k, 5)), '_'))] = $v;
            }
        }
        if (isset($this->server['CONTENT_TYPE'])) {
            $out['Content-Type'] = $this->server['CONTENT_TYPE'];
        }
        return $out;
    }

    public function rawBody(): string
    {
        return $this->rawBody ??= (string) file_get_contents('php://input');
    }

    public function setRawBody(string $body): void
    {
        $this->rawBody = $body;
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('trusted_proxies', []);
        if ($trusted && in_array($remote, $trusted, true)) {
            $cf = $this->server['HTTP_CF_CONNECTING_IP'] ?? null;
            if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
            $xff = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
            $parts = array_map('trim', explode(',', $xff));
            $candidate = $parts[0] ?? '';
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $trusted = (array) Config::get('trusted_proxies', []);
        return $trusted && in_array($this->server['REMOTE_ADDR'] ?? '', $trusted, true)
            && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function wantsJson(): bool
    {
        return $this->isAjax() || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function fullUrl(): string
    {
        return url($this->path) . (empty($this->query) ? '' : '?' . http_build_query($this->query));
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function server(string $key): mixed
    {
        return $this->server[$key] ?? null;
    }

    /** Build a request by hand (tests, CLI). */
    public static function create(string $method, string $path, array $params = [], array $server = [], ?string $rawBody = null): self
    {
        $server += ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $method];
        $cookies = [];
        foreach (explode(';', (string) ($server['HTTP_COOKIE'] ?? '')) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $cookies[trim($k)] = rawurldecode(trim($v));
            }
        }
        $req = new self(strtoupper($method), $path, strtoupper($method) === 'GET' ? $params : [], strtoupper($method) === 'GET' ? [] : $params, [], $server, $cookies);
        if ($rawBody !== null) {
            $req->setRawBody($rawBody);
        }
        return $req;
    }
}
