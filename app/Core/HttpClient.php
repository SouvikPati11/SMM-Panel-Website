<?php

declare(strict_types=1);

namespace App\Core;

/**
 * cURL wrapper used by provider and payment adapters.
 *
 * Distinguishes failures that happened BEFORE the request reached the remote
 * server (DNS/connect errors — safe to retry) from failures AFTER it may have
 * been received (timeouts, resets — NOT safe to blindly retry for financial
 * or order-creating calls).
 */
final class HttpClient
{
    /** Tests can inject a fake transport: fn(string $method, string $url, array $opts): HttpResponse */
    private static ?\Closure $fake = null;

    public static function fake(?\Closure $handler): void
    {
        self::$fake = $handler;
    }

    /**
     * @param array{headers?:array<string,string>, form?:array, json?:array, query?:array, timeout?:int, connect_timeout?:int} $opts
     */
    public static function request(string $method, string $url, array $opts = []): HttpResponse
    {
        $method = strtoupper($method);
        if (!empty($opts['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($opts['query']);
        }
        if (self::$fake) {
            return (self::$fake)($method, $url, $opts);
        }

        $headers = $opts['headers'] ?? [];
        $body = null;
        if (isset($opts['json'])) {
            $body = is_string($opts['json']) ? $opts['json'] : json_encode($opts['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        } elseif (isset($opts['form'])) {
            $body = http_build_query($opts['form']);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (isset($opts['body'])) {
            $body = (string) $opts['body'];
        }
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';
        $headers['User-Agent'] = $headers['User-Agent'] ?? 'SMMPanel/1.0 (+' . (Config::get('url') ?: 'localhost') . ')';

        $ch = curl_init($url);
        $flat = [];
        foreach ($headers as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? 10),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 20 * 1024 * 1024,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$respHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        });

        $start = microtime(true);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($raw === false || $errno !== 0) {
            // Errors where the request provably never reached the server.
            $preSend = in_array($errno, [
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_PROXY,
                CURLE_SSL_CONNECT_ERROR, CURLE_URL_MALFORMAT, CURLE_UNSUPPORTED_PROTOCOL,
            ], true) || (defined('CURLE_PEER_FAILED_VERIFICATION') && $errno === CURLE_PEER_FAILED_VERIFICATION);
            return new HttpResponse(0, '', $ms, $error ?: 'Network error', $preSend);
        }
        return new HttpResponse($status, (string) $raw, $ms, null, false, $respHeaders);
    }

    public static function post(string $url, array $opts = []): HttpResponse
    {
        return self::request('POST', $url, $opts);
    }

    public static function get(string $url, array $opts = []): HttpResponse
    {
        return self::request('GET', $url, $opts);
    }
}
