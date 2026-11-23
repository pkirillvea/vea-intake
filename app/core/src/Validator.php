<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Base class for each form's authoritative validator (docs/PLAN.md section 4).
 *
 * The generic parts of vea-join's Validator, unchanged in behaviour: reject
 * unknown keys, trim and length-cap every string, reject control characters
 * (header injection), whitelist every choice. The form's own rules live in its
 * subclass (VeaIntake\Forms\Legal\LegalValidator, Prompt 4). vea-join's
 * signature() helper is not carried over: there is no signature (PLAN 1.4).
 */
abstract class Validator
{
    // C0/C1 controls, DEL, line/paragraph separators, bidi overrides/isolates
    public const CONTROL = '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';
    // Same, but allows tab, LF and CR (multi-line text such as a narrative)
    public const CONTROL_MULTILINE = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** @var array<string, string> field => reason */
    protected array $errors = [];

    /**
     * @param array<string, mixed> $in the decoded payload
     * @return array{errors: array<string, string>, data: array<string, mixed>}
     */
    abstract public function validate(array $in): array;

    /** Field names that failed (for the client; no values). @return list<string> */
    public static function fieldList(array $errors): array
    {
        return array_values(array_map('strval', array_keys($errors)));
    }

    /** SHA-256 of the canonical JSON of the validated data (keys sorted recursively). */
    public static function dataHash(array $data): string
    {
        $sort = static function (array $a) use (&$sort): array {
            ksort($a, SORT_STRING);
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $sort($v);
                }
            }

            return $a;
        };

        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Number of words, as the front end's live counter counts them. */
    public static function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    // ------------------------------------------------------------ helpers
    /** @param list<string> $allowed */
    protected function unknownKeys(array $in, array $allowed): void
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, $allowed, true)) {
                $this->fail((string) $k, 'unknown');
            }
        }
    }

    protected function fail(string $field, string $reason): void
    {
        $this->errors[$field] ??= $reason;
    }

    protected function str(array $in, string $key, int $min, int $max, bool $multiline = false): ?string
    {
        if (!array_key_exists($key, $in)) {
            $this->fail($key, 'required');

            return null;
        }
        $v = $in[$key];
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match($multiline ? self::CONTROL_MULTILINE : self::CONTROL, $v)) {
            $this->fail($key, 'invalid');

            return null;
        }
        $v = trim($v);
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min) {
            $this->fail($key, 'required');

            return null;
        }
        if ($len > $max) {
            $this->fail($key, 'too_long');

            return null;
        }

        return $v;
    }

    protected function match(array $in, string $key, string $pattern, int $max = 32): ?string
    {
        $v = $this->str($in, $key, 1, $max);
        if ($v !== null && !preg_match($pattern, $v)) {
            $this->fail($key, 'invalid');

            return null;
        }

        return $v;
    }

    /** @param list<string> $allowed */
    protected function enum(array $in, string $key, array $allowed, int $max = 200): ?string
    {
        $v = $this->str($in, $key, 1, $max);
        if ($v !== null && !in_array($v, $allowed, true)) {
            $this->fail($key, 'invalid');

            return null;
        }

        return $v;
    }
}
