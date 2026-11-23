<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Plain-PHP templates and texts of one form: app/forms/<form>/templates/*.php and
 * app/forms/<form>/content.json. Templates must pass every value through e().
 * Copied from vea-join; generalized from one fixed folder to one folder per form.
 */
final class Template
{
    public const FORMS_DIR = __DIR__ . '/../../forms';

    /** @param array<string, mixed> $vars */
    public static function render(string $form, string $name, array $vars): string
    {
        $file = self::formDir($form) . '/templates/' . basename($name) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('template not found');
        }
        ob_start();
        try {
            (static function (string $__file, array $v): void {
                include $__file;
            })($file, $vars);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /** HTML-escape anything. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Replace {name} placeholders; unknown ones are left as they are. */
    public static function fmt(string $tpl, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $tpl);
    }

    /** @return array<string, mixed> the form's content.json */
    public static function content(string $form): array
    {
        static $cache = [];

        return $cache[$form] ??= json_decode(
            (string) file_get_contents(self::formDir($form) . '/content.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    /** The form version (content.json → _meta.formVersion). */
    public static function formVersion(string $form): string
    {
        $v = self::content($form)['_meta']['formVersion'] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException('content.json has no _meta.formVersion');
        }

        return $v;
    }

    private static function formDir(string $form): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/', $form)) {
            throw new \InvalidArgumentException('invalid form name');
        }

        return self::FORMS_DIR . '/' . $form;
    }
}
