<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;

/**
 * Declarative input validation.
 *   Validator::make($data, ['email' => 'required|email|max:190', 'qty' => 'required|integer|min:1'])
 * Rules: required, nullable, string, email, url, integer, decimal, numeric, min:n, max:n (length for
 * strings, value for numbers), between:a,b, in:a,b,c, alpha_dash, username, slug, regex:/.../,
 * confirmed, same:field, date, boolean.
 */
final class Validator
{
    private array $errors = [];

    private function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    /** Validate or throw ValidationException with the first error as message. */
    public static function check(array $data, array $rules, array $labels = []): array
    {
        $v = self::make($data, $rules, $labels);
        if ($v->fails()) {
            throw new ValidationException($v->first(), $v->errors());
        }
        return $v->validated();
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(): string
    {
        return (string) (array_values($this->errors)[0] ?? '');
    }

    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            $v = $this->data[$field] ?? null;
            $out[$field] = is_string($v) ? trim($v) : $v;
        }
        return $out;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $raw = $this->data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $isEmpty = $value === null || $value === '' || $value === [];

            if (in_array('required', $rules, true) && $isEmpty) {
                $this->errors[$field] = "{$label} is required.";
                continue;
            }
            if ($isEmpty) {
                continue;
            }
            if (is_array($value) && !in_array('array', $rules, true)) {
                $this->errors[$field] = "{$label} is invalid.";
                continue;
            }
            $numericType = in_array('integer', $rules, true) || in_array('decimal', $rules, true) || in_array('numeric', $rules, true);

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'required', 'nullable', 'string', 'array' => null,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) && strlen((string) $value) <= 190 ? null : "{$label} must be a valid email address.",
                    'url' => $this->isUrl((string) $value) ? null : "{$label} must be a valid URL (http/https).",
                    'integer' => preg_match('/^-?\d{1,12}$/', (string) $value) ? null : "{$label} must be a whole number.",
                    'decimal', 'numeric' => Money::isNumeric((string) $value) ? null : "{$label} must be a number.",
                    'boolean' => in_array((string) $value, ['0', '1', 'on', 'off', 'true', 'false'], true) ? null : "{$label} is invalid.",
                    'min' => $this->checkMin($value, $param, $numericType, $label),
                    'max' => $this->checkMax($value, $param, $numericType, $label),
                    'between' => $this->checkBetween($value, $param, $numericType, $label),
                    'in' => in_array((string) $value, explode(',', (string) $param), true) ? null : "{$label} has an invalid value.",
                    'alpha_dash' => preg_match('/^[A-Za-z0-9_-]+$/', (string) $value) ? null : "{$label} may only contain letters, numbers, dashes and underscores.",
                    'username' => preg_match('/^[A-Za-z0-9_]{3,30}$/', (string) $value) ? null : "{$label} must be 3-30 characters: letters, numbers and underscore.",
                    'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) ? null : "{$label} must be lowercase letters, numbers and dashes.",
                    'regex' => preg_match((string) $param, (string) $value) ? null : "{$label} format is invalid.",
                    'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $raw ? null : "{$label} confirmation does not match.",
                    'same' => ($this->data[(string) $param] ?? null) === $raw ? null : "{$label} does not match.",
                    'date' => strtotime((string) $value) !== false ? null : "{$label} must be a valid date.",
                    default => throw new \InvalidArgumentException("Unknown validation rule {$name}"),
                };
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    private function isUrl(string $v): bool
    {
        if (strlen($v) > 1000 || !filter_var($v, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function checkMin(mixed $value, ?string $param, bool $numeric, string $label): ?string
    {
        if ($numeric) {
            return Money::isNumeric((string) $value) && Money::cmp((string) $value, (string) $param) >= 0 ? null : "{$label} must be at least {$param}.";
        }
        return mb_strlen((string) $value) >= (int) $param ? null : "{$label} must be at least {$param} characters.";
    }

    private function checkMax(mixed $value, ?string $param, bool $numeric, string $label): ?string
    {
        if ($numeric) {
            return Money::isNumeric((string) $value) && Money::cmp((string) $value, (string) $param) <= 0 ? null : "{$label} may not be greater than {$param}.";
        }
        return mb_strlen((string) $value) <= (int) $param ? null : "{$label} may not be longer than {$param} characters.";
    }

    private function checkBetween(mixed $value, ?string $param, bool $numeric, string $label): ?string
    {
        [$a, $b] = array_pad(explode(',', (string) $param), 2, '0');
        return $this->checkMin($value, $a, $numeric, $label) ?? $this->checkMax($value, $b, $numeric, $label);
    }
}
