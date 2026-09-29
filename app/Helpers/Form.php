<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Session;

/**
 * Escaped form-field builders. Values fall back to flashed old input after a
 * validation error so users never lose what they typed.
 */
final class Form
{
    private static function value(string $name, mixed $value): string
    {
        $old = Session::old($name, "\0");
        return $old !== "\0" ? $old : (string) ($value ?? '');
    }

    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === false || $v === null) {
                continue;
            }
            $out .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
        }
        return $out;
    }

    private static function wrap(string $name, string $label, string $control, ?string $hint, string $class = ''): string
    {
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        return '<div class="field ' . e($class) . '">' . ($label !== '' ? '<label for="' . $id . '">' . e($label) . '</label>' : '')
            . $control . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . '</div>';
    }

    /** $opts: type, placeholder, hint (raw HTML), required, readonly, class, attrs[] */
    public static function input(string $name, string $label, mixed $value = '', array $opts = []): string
    {
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $type = $opts['type'] ?? 'text';
        $val = $type === 'password' ? '' : self::value($name, $value);
        $control = '<input class="input" id="' . $id . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($val) . '"' . self::attrs([
            'placeholder' => $opts['placeholder'] ?? null,
            'required' => !empty($opts['required']),
            'readonly' => !empty($opts['readonly']),
            'autocomplete' => $opts['autocomplete'] ?? null,
            'inputmode' => $opts['inputmode'] ?? null,
            'step' => $opts['step'] ?? null,
            'min' => $opts['min'] ?? null,
            'max' => $opts['max'] ?? null,
            'maxlength' => $opts['maxlength'] ?? null,
        ] + ($opts['attrs'] ?? [])) . '>';
        return self::wrap($name, $label, $control, $opts['hint'] ?? null, $opts['class'] ?? '');
    }

    public static function textarea(string $name, string $label, mixed $value = '', array $opts = []): string
    {
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $control = '<textarea class="textarea ' . e($opts['textarea_class'] ?? '') . '" id="' . $id . '" name="' . e($name) . '"' . self::attrs([
            'rows' => $opts['rows'] ?? 4,
            'placeholder' => $opts['placeholder'] ?? null,
            'required' => !empty($opts['required']),
            'maxlength' => $opts['maxlength'] ?? null,
        ] + ($opts['attrs'] ?? [])) . '>' . e(self::value($name, $value)) . '</textarea>';
        return self::wrap($name, $label, $control, $opts['hint'] ?? null, $opts['class'] ?? '');
    }

    /** @param array<string|int,string> $options value => label */
    public static function select(string $name, string $label, array $options, mixed $selected = '', array $opts = []): string
    {
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $sel = self::value($name, $selected);
        $html = '<select class="select" id="' . $id . '" name="' . e($name) . '"' . self::attrs(['required' => !empty($opts['required'])] + ($opts['attrs'] ?? [])) . '>';
        if (isset($opts['empty'])) {
            $html .= '<option value="">' . e($opts['empty']) . '</option>';
        }
        foreach ($options as $v => $l) {
            $html .= '<option value="' . e($v) . '"' . ((string) $v === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        $html .= '</select>';
        return self::wrap($name, $label, $html, $opts['hint'] ?? null, $opts['class'] ?? '');
    }

    public static function toggle(string $name, string $label, bool $checked, ?string $hint = null): string
    {
        return '<div class="field"><input type="hidden" name="' . e($name) . '" value="0"><label class="switch"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '> <span>' . e($label) . '</span></label>'
            . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . '</div>';
    }

    public static function check(string $name, string $label, bool $checked, string $value = '1'): string
    {
        return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '> <span>' . $label . '</span></label>';
    }
}
