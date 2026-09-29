<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;

/**
 * Display currencies.
 *
 * The site currency (Settings → currency_code) is the BASE currency: every
 * balance, price, ledger entry, payment and provider cost is stored and charged
 * in it. Other currencies are display-only: amounts are converted with the
 * admin-set rate (exact decimals, half-up rounding) when shown to a user who
 * picked that currency. Changing a user's display currency never touches
 * stored values.
 *
 * Admin pages, the API, cron, emails and payment amounts always use the base.
 */
final class CurrencyService
{
    private static ?array $display = null;
    private static bool $forceBase = false;

    /** @return array{code:string,name:string,symbol:string,position:string,decimals:int,rate:string,base:bool} */
    public static function base(): array
    {
        $code = strtoupper((string) setting('currency_code', 'USD'));
        return [
            'code' => $code,
            'name' => $code,
            'symbol' => (string) setting('currency_symbol', '$'),
            'position' => setting('currency_position', 'before') === 'after' ? 'after' : 'before',
            'decimals' => max(0, min(6, (int) setting('currency_decimals', '2'))),
            'rate' => '1',
            'base' => true,
        ];
    }

    /** @return array<string, array> code => currency, base first */
    public static function all(bool $enabledOnly = true): array
    {
        $base = self::base();
        $out = [$base['code'] => $base];
        try {
            $rows = Database::instance()->fetchAll('SELECT * FROM currencies' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, code');
        } catch (\Throwable) {
            $rows = []; // table missing before migration
        }
        foreach ($rows as $r) {
            if (strtoupper($r['code']) === $base['code']) {
                continue; // the base currency is defined by settings only
            }
            $out[$r['code']] = [
                'code' => $r['code'], 'name' => $r['name'], 'symbol' => $r['symbol'],
                'position' => $r['position'], 'decimals' => (int) $r['decimals'],
                'rate' => Money::of((string) $r['rate'], 8), 'base' => false, 'enabled' => (int) $r['enabled'] === 1,
            ];
        }
        return $out;
    }

    public static function switchEnabled(): bool
    {
        return setting('currency_switch_enabled', '1') === '1' && count(self::all()) > 1;
    }

    /** Pin the current request to the base currency (admin area, API). */
    public static function useBase(): void
    {
        self::$forceBase = true;
        self::$display = null;
    }

    /** Forget cached state (tests, or after a user changes currency). */
    public static function reset(): void
    {
        self::$display = null;
        self::$forceBase = false;
    }

    /** Currency used to display amounts in the current request. */
    public static function display(): array
    {
        if (self::$display !== null) {
            return self::$display;
        }
        $base = self::base();
        $path = App::request()?->path() ?? '';
        $inAdmin = $path === '/' . admin_path() || str_starts_with($path, '/' . admin_path() . '/');
        $user = !self::$forceBase && !$inAdmin ? Auth::user() : null;
        $code = strtoupper((string) ($user['currency'] ?? ''));
        if ($code !== '' && $code !== $base['code'] && setting('currency_switch_enabled', '1') === '1') {
            $all = self::all();
            if (isset($all[$code])) {
                return self::$display = $all[$code];
            }
        }
        return self::$display = $base;
    }

    public static function isConverted(): bool
    {
        return !self::display()['base'];
    }

    /** Convert a base-currency amount (exact, half-up to the currency's decimals + 4 guard digits). */
    public static function convert(string $baseAmount, array $currency): string
    {
        if ($currency['base']) {
            return Money::of($baseAmount);
        }
        return Money::round(Money::mul(Money::of($baseAmount), $currency['rate'], 12), 6);
    }

    public static function format(string|int|null $baseAmount, ?array $currency = null, ?int $decimals = null): string
    {
        $currency ??= self::display();
        $value = self::convert((string) ($baseAmount ?? '0'), $currency);
        $formatted = Money::format($value, $decimals ?? $currency['decimals']);
        $neg = str_starts_with($formatted, '-');
        $formatted = ltrim($formatted, '-');
        $out = $currency['position'] === 'after' ? $formatted . ' ' . $currency['symbol'] : $currency['symbol'] . $formatted;
        return ($neg ? '-' : '') . $out;
    }

    public static function formatRate(string|int|null $baseAmount, ?array $currency = null): string
    {
        $currency ??= self::display();
        $f = Money::formatRate(self::convert((string) ($baseAmount ?? '0'), $currency));
        return $currency['position'] === 'after' ? $f . ' ' . $currency['symbol'] : $currency['symbol'] . $f;
    }

    /** Client-side formatting config (the order form previews prices with it). */
    public static function clientConfig(): array
    {
        $d = self::display();
        $b = self::base();
        return ['symbol' => $d['symbol'], 'position' => $d['position'], 'decimals' => $d['decimals'], 'rate' => $d['rate'], 'code' => $d['code'],
            'base' => ['symbol' => $b['symbol'], 'position' => $b['position'], 'decimals' => $b['decimals'], 'code' => $b['code']]];
    }

    // ------------------------------------------------------------------ user choice & admin config

    public static function setUserCurrency(int $userId, string $code): void
    {
        $code = strtoupper(trim($code));
        $all = self::all();
        if (!isset($all[$code])) {
            throw new ValidationException('That currency is not available.');
        }
        if (!self::switchEnabled() && $code !== self::base()['code']) {
            throw new ValidationException('Currency selection is disabled.');
        }
        Database::instance()->update('users', ['currency' => $code === self::base()['code'] ? null : $code, 'updated_at' => now()], ['id' => $userId]);
        self::reset();
    }

    public static function save(array $input, ?string $originalCode = null): string
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new ValidationException('Currency code must be 3 letters (ISO 4217), e.g. EUR.');
        }
        if ($code === self::base()['code']) {
            throw new ValidationException('That is the base currency; change it under Settings → General.');
        }
        $rate = trim((string) ($input['rate'] ?? ''));
        if (!Money::isNumeric($rate) || !Money::isPositive($rate) || Money::cmp($rate, '1000000') > 0) {
            throw new ValidationException('Enter a positive exchange rate: how many ' . $code . ' equal 1 ' . self::base()['code'] . '.');
        }
        $decimals = (int) ($input['decimals'] ?? 2);
        if ($decimals < 0 || $decimals > 4) {
            throw new ValidationException('Decimals must be between 0 and 4.');
        }
        $symbol = trim((string) ($input['symbol'] ?? ''));
        if ($symbol === '' || mb_strlen($symbol) > 8) {
            throw new ValidationException('Enter a symbol (max 8 characters).');
        }
        $row = [
            'code' => $code,
            'name' => mb_substr(trim((string) ($input['name'] ?? $code)) ?: $code, 0, 60),
            'symbol' => $symbol,
            'position' => ($input['position'] ?? 'before') === 'after' ? 'after' : 'before',
            'decimals' => $decimals,
            'rate' => Money::of($rate, 8),
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'updated_at' => now(),
        ];
        $db = Database::instance();
        if ($originalCode !== null && $originalCode !== $code) {
            throw new ValidationException('The currency code cannot be changed; add a new currency instead.');
        }
        if ($originalCode !== null) {
            $db->update('currencies', $row, ['code' => $originalCode]);
        } else {
            if ($db->fetchColumn('SELECT code FROM currencies WHERE code = ?', [$code])) {
                throw new ValidationException("{$code} already exists.");
            }
            $db->insert('currencies', $row);
        }
        return $code;
    }
}
