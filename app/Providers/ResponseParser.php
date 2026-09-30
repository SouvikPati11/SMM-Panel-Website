<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Money;

/**
 * Tolerant parsing of SMM API v2 responses.
 *
 * Panels that follow the API v2 documentation still differ in details: the
 * balance may be a JSON number or a string ("12.5", "1,234.50", "$12.50",
 * "12.50 USD", "1.234,50"), sit in a wrapper ({"data":{"balance":…}}), and
 * some panels print a BOM or PHP notices before the JSON. These helpers accept
 * every unambiguous variant and report precisely what was received otherwise.
 */
final class ResponseParser
{
    /** Keys that hold an account balance, in order of preference. */
    private const BALANCE_KEYS = ['balance', 'user_balance', 'available_balance', 'funds', 'credit', 'credits'];
    /** Wrapper objects some panels put around the payload. */
    private const WRAPPERS = ['data', 'result', 'response', 'user', 'account', 'wallet'];

    /**
     * Decode a JSON body. Accepts a UTF-8 BOM and text around the JSON document
     * (e.g. "Notice: … {"balance":"1.00"}"). Returns null if no JSON is found.
     */
    public static function decode(string $body): mixed
    {
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body);
        if ($body === '') {
            return null;
        }
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        // Leading/trailing noise: take the outermost {...} or [...] block.
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($body, $open);
            $end = strrpos($body, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($body, $start, $end - $start + 1), true, 512, JSON_BIGINT_AS_STRING);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * Normalise a money amount to a plain decimal string ("1234.5"), or null
     * when the value is not an unambiguous number.
     */
    public static function amount(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            // Avoid "1.0E-5": format with fixed decimals, then trim.
            $s = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
            return $s === '-0' ? '0' : $s;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $negative = (bool) preg_match('/^\(.*\)$|^-|^[^\d]*-\s*\d/u', $s);
        // Drop currency symbols/codes and spaces (incl. NBSP / thin spaces), keep digits and separators.
        $s = preg_replace('/[^\d.,]/u', '', $s) ?? '';
        if ($s === '' || !preg_match('/\d/', $s)) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Both present: the one that comes last is the decimal separator.
            $s = $lastComma > $lastDot ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            // "1,234" / "1,234,567" are thousands; "12,5" / "12,50" is a decimal comma.
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : (substr_count($s, ',') === 1 ? str_replace(',', '.', $s) : '');
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            // "1.234.567" = thousands dots.
            $s = preg_match('/^\d{1,3}(\.\d{3})+$/', $s) ? str_replace('.', '', $s) : '';
        }
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m)) {
            return null;
        }
        $int = ltrim($m[1], '0') ?: '0';
        if (strlen($int) > 15) {
            return null;
        }
        $dec = isset($m[2]) ? substr($m[2], 0, 12) : '';
        $out = $int . ($dec !== '' ? '.' . $dec : '');
        return $negative && $out !== '0' && Money::isPositive($out) ? '-' . $out : $out;
    }

    /**
     * Extract the balance from a decoded "balance" response.
     * @return array{balance:string, currency:?string}|null
     */
    public static function balance(mixed $data): ?array
    {
        if (!is_array($data)) {
            // A bare number/string ("12.34") is still a balance.
            $amount = (is_string($data) || is_int($data) || is_float($data)) ? self::amount($data) : null;
            return $amount !== null ? ['balance' => $amount, 'currency' => self::currencyFrom($data)] : null;
        }
        foreach ([$data] + array_filter(array_map(static fn ($w) => $data[$w] ?? null, array_combine(self::WRAPPERS, self::WRAPPERS)), 'is_array') as $scope) {
            $lower = array_change_key_case($scope, CASE_LOWER);
            foreach (self::BALANCE_KEYS as $key) {
                if (!array_key_exists($key, $lower)) {
                    continue;
                }
                $raw = $lower[$key];
                if (is_array($raw)) {
                    // {"balance":{"amount":"1.00","currency":"USD"}}
                    $inner = array_change_key_case($raw, CASE_LOWER);
                    $raw = $inner['amount'] ?? $inner['value'] ?? $inner['balance'] ?? null;
                    $currency = self::currencyCode($inner['currency'] ?? null);
                }
                $amount = self::amount($raw);
                if ($amount !== null) {
                    return ['balance' => $amount, 'currency' => $currency ?? self::currencyCode($lower['currency'] ?? $lower['currency_code'] ?? null) ?? self::currencyFrom($raw)];
                }
                unset($currency);
            }
        }
        return null;
    }

    /**
     * The services list: a JSON array, or an object wrapping it ({"services":[…]},
     * {"data":[…]}), or an object keyed by service id.
     */
    public static function serviceList(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        if (array_is_list($data)) {
            return $data;
        }
        foreach (['services', 'data', 'result', 'response'] as $w) {
            if (isset($data[$w]) && is_array($data[$w])) {
                return self::serviceList($data[$w]);
            }
        }
        // {"1": {...}, "2": {...}}
        $rows = array_filter($data, 'is_array');
        if ($rows && count($rows) === count($data)) {
            $out = [];
            foreach ($rows as $id => $row) {
                $out[] = $row + ['service' => $id];
            }
            return $out;
        }
        return null;
    }

    /** Parse one service row into the adapter's normalised shape, or null. */
    public static function service(array $s): ?array
    {
        $l = array_change_key_case($s, CASE_LOWER);
        $id = $l['service'] ?? $l['service_id'] ?? $l['id'] ?? null;
        $name = $l['name'] ?? $l['service_name'] ?? null;
        $rate = self::amount($l['rate'] ?? $l['price'] ?? $l['rate_per_1000'] ?? null);
        if ($id === null || !is_scalar($id) || trim((string) $id) === '' || !is_scalar($name) || trim((string) $name) === '' || $rate === null || str_starts_with($rate, '-')) {
            return null;
        }
        $int = static function (mixed $v, int $default): int {
            $a = self::amount($v);
            return $a === null ? $default : max(1, (int) $a);
        };
        $bool = static fn (mixed $v): bool => is_string($v) ? in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true) : (bool) $v;
        return [
            'service' => mb_substr(trim((string) $id), 0, 50),
            'name' => mb_substr(trim((string) $name), 0, 255),
            'category' => mb_substr(trim((string) ($l['category'] ?? $l['category_name'] ?? '')) ?: 'Uncategorized', 0, 255),
            'type' => mb_substr(trim((string) ($l['type'] ?? '')) ?: 'Default', 0, 60),
            'rate' => Money::of($rate),
            'min' => $int($l['min'] ?? $l['min_quantity'] ?? null, 1),
            'max' => $int($l['max'] ?? $l['max_quantity'] ?? null, 1),
            'refill' => $bool($l['refill'] ?? false),
            'cancel' => $bool($l['cancel'] ?? false),
            'dripfeed' => $bool($l['dripfeed'] ?? false),
            'description' => is_scalar($l['description'] ?? $l['desc'] ?? null) ? (string) ($l['description'] ?? $l['desc']) : '',
        ];
    }

    /**
     * An error message carried by a response that has no "error" key, e.g.
     * {"status":"fail","message":"Invalid API key"} or {"success":false,"msg":"…"}.
     */
    public static function failureMessage(array $data): ?string
    {
        $l = array_change_key_case($data, CASE_LOWER);
        foreach (['error', 'errors'] as $k) {
            if (isset($l[$k]) && $l[$k] !== '' && $l[$k] !== false && $l[$k] !== []) {
                return is_scalar($l[$k]) ? (string) $l[$k] : (string) json_encode($l[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $status = $l['status'] ?? null;
        $msg = $l['message'] ?? $l['msg'] ?? null;
        // A textual status of "error"/"fail" only counts with a message next to it: order-status
        // responses legitimately use {"status":"Fail"} for the order itself.
        $failed = ($l['success'] ?? null) === false
            || $status === false
            || (is_string($status) && $msg !== null && in_array(strtolower($status), ['error', 'fail', 'failed', 'failure'], true));
        if ($failed) {
            return is_scalar($msg) && (string) $msg !== '' ? (string) $msg : 'The provider reported a failure without a message';
        }
        return null;
    }

    /**
     * A short, secret-free description of an unexpected payload for admins:
     * the JSON keys (never values of unknown fields beyond a short preview).
     */
    public static function describe(mixed $data, string $body, array $secrets = []): string
    {
        if (is_array($data)) {
            if (array_is_list($data)) {
                $desc = 'a JSON list with ' . count($data) . ' item(s)';
                if (isset($data[0]) && is_array($data[0])) {
                    $desc .= ' (first item keys: ' . implode(', ', array_slice(array_map('strval', array_keys($data[0])), 0, 12)) . ')';
                }
            } else {
                $desc = 'a JSON object with keys: ' . (implode(', ', array_slice(array_map('strval', array_keys($data)), 0, 12)) ?: '(none)');
            }
        } else {
            $desc = 'JSON ' . gettype($data);
        }
        return $desc . ' — preview: "' . self::preview($body, $secrets) . '"';
    }

    /** First characters of a body with secrets removed and whitespace collapsed. */
    public static function preview(string $body, array $secrets = [], int $max = 160): string
    {
        $text = self::redact($body, $secrets);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    /** Remove secrets (API keys, tokens) from text that may be shown or logged. */
    public static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if (strlen($secret) >= 6) {
                $text = str_replace([$secret, rawurlencode($secret)], '[redacted]', $text);
            }
        }
        return $text;
    }

    private static function currencyCode(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\s*([A-Za-z]{3})\s*$/', $v, $m) ? strtoupper($m[1]) : null;
    }

    /** "12.50 USD" / "USD 12.50" → USD. */
    private static function currencyFrom(mixed $v): ?string
    {
        return is_string($v) && preg_match('/(?:^|[\s\d])([A-Za-z]{3})(?:$|[\s\d])/', $v, $m) ? strtoupper($m[1]) : null;
    }
}
