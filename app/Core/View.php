<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PHP template renderer with single-level layout inheritance.
 * A template calls $this->extend('layouts/user') and the rendered output
 * becomes $content in the layout. Variables are shared with the layout.
 */
final class View
{
    private static array $shared = [];
    private ?string $layout = null;
    private array $sections = [];
    private ?string $currentSection = null;

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    public static function render(string $template, array $data = []): string
    {
        return (new self())->renderTemplate($template, array_merge(self::$shared, $data));
    }

    private function renderTemplate(string $template, array $data): string
    {
        $content = $this->capture($template, $data);
        $guard = 0;
        while ($this->layout !== null && $guard++ < 5) {
            $layout = $this->layout;
            $this->layout = null;
            $data = array_merge(self::$shared, $data);
            $data['content'] = $content;
            $content = $this->capture($layout, $data);
        }
        return $content;
    }

    private function capture(string $template, array $__data): string
    {
        $__file = VIEW_PATH . '/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    /** Render a partial with the given variables (plus shared ones). */
    public function partial(string $template, array $data = []): string
    {
        return (new self())->capture($template, array_merge(self::$shared, $data));
    }

    public function start(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->currentSection === null) {
            return;
        }
        $this->sections[$this->currentSection] = (string) ob_get_clean();
        self::$shared['__section_' . $this->currentSection] = $this->sections[$this->currentSection];
        $this->currentSection = null;
    }

    public function section(string $name): string
    {
        return self::$shared['__section_' . $name] ?? '';
    }
}
