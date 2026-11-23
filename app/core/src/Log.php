<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * One-line event log (docs/PLAN.md section 3). Only an event name plus a fixed
 * set of context keys whose values are reduced to [A-Za-z0-9_.:,-]. Never field
 * values, file names, request bodies or Graph response bodies.
 * Copied from vea-join; prefix "vea-intake" and a `form` key added.
 */
final class Log
{
    private const KEYS = ['form', 'id', 'status', 'stage', 'reason', 'class', 'fields'];

    /** @var callable(string):void|null */
    private static $writer = null;

    /** Replace the writer (tests). Default: error_log() → the pool's error_log file. */
    public static function setWriter(?callable $writer): void
    {
        self::$writer = $writer;
    }

    /** @param array<string, scalar|null> $context */
    public static function event(string $event, array $context = []): void
    {
        $line = 'vea-intake event=' . self::clean($event);
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== null) {
                $line .= " $key=" . self::clean((string) $context[$key]);
            }
        }
        $writer = self::$writer ?? static function (string $l): void {
            error_log($l);
        };
        $writer($line);
    }

    private static function clean(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.:,-]/', '_', $value), 0, 120);
    }
}
