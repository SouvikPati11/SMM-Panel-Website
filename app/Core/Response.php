<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private array $headers = [];
    /** @var list<array{name:string,value:string,options:array}> */
    private array $cookies = [];

    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->headers[$k] = $v;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($body, $status, ['Content-Type' => $type . '; charset=UTF-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (!preg_match('#^https?://#i', $to)) {
            $to = url($to);
        }
        return new self('', $status, ['Location' => $to]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** @param array{expires?:int,path?:string,secure?:bool,httponly?:bool,samesite?:string} $options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options + ['expires' => 0, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']];
        return $this;
    }

    /** @return list<array{name:string,value:string,options:array}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->cookies as $c) {
                setcookie($c['name'], $c['value'], $c['options']);
            }
        }
        echo $this->body;
    }
}
