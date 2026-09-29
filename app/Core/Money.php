<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Exact decimal arithmetic on numeric strings. Never uses floats.
 *
 * Uses bcmath when available; otherwise a pure-PHP implementation that works
 * on arbitrary-length integer strings scaled by 10^SCALE. Results are
 * truncated/rounded to $scale decimal places (half-up).
 */
final class Money
{
    public const SCALE = 6;

    /** Normalise user/DB input into a canonical decimal string or throw. */
    public static function of(string|int|null $value, int $scale = self::SCALE): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = '0';
        }
        if (!self::isNumeric($value)) {
            throw new \InvalidArgumentException('Invalid decimal value');
        }
        return self::round($value, $scale);
    }

    public static function isNumeric(string $value): bool
    {
        return (bool) preg_match('/^-?\d{1,15}(\.\d{1,12})?$/', trim($value));
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        if (function_exists('bcadd')) {
            return self::round(bcadd($a, $b, $scale + 2), $scale);
        }
        return self::fromScaled(self::intAdd(self::toScaled($a, $scale + 2), self::toScaled($b, $scale + 2)), $scale + 2, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::add($a, self::negate($b), $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        if (function_exists('bcmul')) {
            return self::round(bcmul($a, $b, $scale + 4), $scale);
        }
        $s = $scale + 2;
        $prod = self::intMul(self::toScaled($a, $s), self::toScaled($b, $s)); // scaled by 10^(2s)
        return self::fromScaled($prod, 2 * $s, $scale);
    }

    /** Divide by a positive integer divisor (e.g. 1000 for per-1000 rates, 100 for percent). */
    public static function divInt(string $a, int $divisor, int $scale = self::SCALE): string
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('Divisor must be positive');
        }
        if (function_exists('bcdiv')) {
            return self::round(bcdiv($a, (string) $divisor, $scale + 4), $scale);
        }
        $s = $scale + 4;
        $scaled = self::toScaled($a, $s);
        $neg = str_starts_with($scaled, '-');
        $digits = ltrim($scaled, '-');
        $q = '';
        $rem = 0;
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $rem = $rem * 10 + (int) $digits[$i];
            $q .= intdiv($rem, $divisor);
            $rem %= $divisor;
        }
        $q = ltrim($q, '0') ?: '0';
        return self::fromScaled(($neg && $q !== '0' ? '-' : '') . $q, $s, $scale);
    }

    /** Percentage of an amount: $amount * $percent / 100 */
    public static function percent(string $amount, string $percent, int $scale = self::SCALE): string
    {
        return self::divInt(self::mul($amount, $percent, $scale + 4), 100, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, self::SCALE + 4);
        }
        $d = self::sub($a, $b, self::SCALE + 4);
        if (self::isZero($d)) {
            return 0;
        }
        return str_starts_with($d, '-') ? -1 : 1;
    }

    public static function isZero(string $a): bool
    {
        return trim(str_replace(['-', '.', '0'], '', $a)) === '';
    }

    public static function isNegative(string $a): bool
    {
        return str_starts_with(trim($a), '-') && !self::isZero($a);
    }

    public static function isPositive(string $a): bool
    {
        return !self::isNegative($a) && !self::isZero($a);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function negate(string $a): string
    {
        $a = trim($a);
        if (self::isZero($a)) {
            return ltrim($a, '-');
        }
        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    public static function abs(string $a): string
    {
        return ltrim(trim($a), '-');
    }

    /** Half-up rounding to $scale decimals. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $value = trim($value);
        $neg = str_starts_with($value, '-');
        $value = ltrim($value, '-+');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $int = ltrim($int, '0') ?: '0';
        $frac = str_pad($frac, $scale + 1, '0');
        $keep = substr($frac, 0, $scale);
        $roundDigit = (int) $frac[$scale];
        $digits = $int . $keep;
        if ($roundDigit >= 5) {
            $digits = self::intAdd($digits, '1');
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $intPart = ltrim(substr($digits, 0, strlen($digits) - $scale), '0') ?: '0';
        $fracPart = $scale > 0 ? substr($digits, -$scale) : '';
        $out = $intPart . ($scale > 0 ? '.' . $fracPart : '');
        if ($neg && !self::isZero($out)) {
            $out = '-' . $out;
        }
        return $out;
    }

    /** Format for display, e.g. 12.5 -> "12.50" (min 2 decimals, trims beyond). */
    public static function format(string|int|null $value, int $decimals = 2): string
    {
        $v = self::round((string) ($value ?? '0'), $decimals);
        [$i, $f] = array_pad(explode('.', ltrim($v, '-'), 2), 2, '');
        $i = strrev(implode(',', str_split(strrev($i), 3)));
        return (str_starts_with($v, '-') ? '-' : '') . $i . ($decimals > 0 ? '.' . $f : '');
    }

    /** Display a per-unit rate with trailing zeros trimmed (keeps at least 2 decimals). */
    public static function formatRate(string|int|null $value): string
    {
        $v = self::round((string) ($value ?? '0'), self::SCALE);
        [$i, $f] = explode('.', $v);
        $f = rtrim($f, '0');
        if (strlen($f) < 2) {
            $f = str_pad($f, 2, '0');
        }
        return $i . '.' . $f;
    }

    // ---- pure-PHP big integer helpers (strings of digits with optional leading '-') ----

    private static function toScaled(string $value, int $scale): string
    {
        $value = trim($value);
        $neg = str_starts_with($value, '-');
        $value = ltrim($value, '-+');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);
        $digits = ltrim($int . $frac, '0') ?: '0';
        return ($neg && $digits !== '0' ? '-' : '') . $digits;
    }

    private static function fromScaled(string $digits, int $fromScale, int $toScale): string
    {
        $neg = str_starts_with($digits, '-');
        $digits = str_pad(ltrim($digits, '-'), $fromScale + 1, '0', STR_PAD_LEFT);
        $str = substr($digits, 0, -$fromScale) . '.' . substr($digits, -$fromScale);
        return self::round(($neg ? '-' : '') . $str, $toScale);
    }

    private static function intAdd(string $a, string $b): string
    {
        $an = str_starts_with($a, '-');
        $bn = str_starts_with($b, '-');
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');
        if ($an === $bn) {
            $r = self::absAdd($a, $b);
            return ($an && $r !== '0' ? '-' : '') . $r;
        }
        $c = self::absCmp($a, $b);
        if ($c === 0) {
            return '0';
        }
        if ($c > 0) {
            $r = self::absSub($a, $b);
            return ($an ? '-' : '') . $r;
        }
        $r = self::absSub($b, $a);
        return ($bn ? '-' : '') . $r;
    }

    private static function absAdd(string $a, string $b): string
    {
        $len = max(strlen($a), strlen($b));
        $a = str_pad($a, $len, '0', STR_PAD_LEFT);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $carry = 0;
        $out = '';
        for ($i = $len - 1; $i >= 0; $i--) {
            $s = (int) $a[$i] + (int) $b[$i] + $carry;
            $out = ($s % 10) . $out;
            $carry = intdiv($s, 10);
        }
        if ($carry) {
            $out = $carry . $out;
        }
        return ltrim($out, '0') ?: '0';
    }

    /** $a >= $b assumed */
    private static function absSub(string $a, string $b): string
    {
        $len = strlen($a);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $borrow = 0;
        $out = '';
        for ($i = $len - 1; $i >= 0; $i--) {
            $d = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $d < 0 ? 1 : 0;
            $out = ($d + ($borrow ? 10 : 0)) . $out;
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function absCmp(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    private static function intMul(string $a, string $b): string
    {
        $neg = (str_starts_with($a, '-') xor str_starts_with($b, '-'));
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int) $a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $res[$i + $j + 1] += $da * (int) $b[$j];
            }
        }
        for ($k = $la + $lb - 1; $k > 0; $k--) {
            if ($res[$k] >= 10) {
                $res[$k - 1] += intdiv($res[$k], 10);
                $res[$k] %= 10;
            }
        }
        $out = ltrim(implode('', $res), '0') ?: '0';
        return ($neg ? '-' : '') . $out;
    }
}
