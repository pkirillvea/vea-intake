<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use RuntimeException;

/**
 * A form's limits, read from its content.json → limits: the single source for the
 * browser (legal.js) and the server (PLAN 1.9, decision F). app.env's old
 * UPLOAD_* / NARRATIVE_MAX_WORDS keys are ignored.
 */
final class FormLimits
{
    private const INTS = [
        'maxFiles', 'maxFileMb', 'maxTotalMb', 'narrativeMaxWords', 'narrativeMaxChars',
        'requestedOutcomeMaxChars', 'dobAgeMin', 'dobAgeMax', 'reviewInlineMaxChars',
    ];

    /** @param array<string, int> $v @param list<string> $extensions */
    private function __construct(private readonly array $v, public readonly array $extensions)
    {
    }

    /** @param array<string, mixed> $content the form's content.json */
    public static function fromContent(array $content): self
    {
        $l = $content['limits'] ?? null;
        if (!is_array($l)) {
            throw new RuntimeException('content.json has no limits');
        }
        $v = [];
        foreach (self::INTS as $k) {
            if (!is_int($l[$k] ?? null) || $l[$k] < 1) {
                throw new RuntimeException("content.json limits.$k must be a positive integer");
            }
            $v[$k] = $l[$k];
        }
        $ext = $l['extensions'] ?? null;
        if (!is_array($ext) || $ext === [] || array_filter($ext, static fn ($e) => !is_string($e) || !preg_match('/^[a-z0-9]{1,8}$/', $e)) !== []) {
            throw new RuntimeException('content.json limits.extensions is invalid');
        }
        if ($v['dobAgeMin'] > $v['dobAgeMax'] || $v['maxFileMb'] > $v['maxTotalMb']) {
            throw new RuntimeException('content.json limits are inconsistent');
        }

        return new self($v, array_values($ext));
    }

    public function maxFiles(): int { return $this->v['maxFiles']; }
    public function maxFileBytes(): int { return $this->v['maxFileMb'] * 1024 * 1024; }
    public function maxTotalBytes(): int { return $this->v['maxTotalMb'] * 1024 * 1024; }
    public function narrativeMaxWords(): int { return $this->v['narrativeMaxWords']; }
    public function narrativeMaxChars(): int { return $this->v['narrativeMaxChars']; }
    public function requestedOutcomeMaxChars(): int { return $this->v['requestedOutcomeMaxChars']; }
    public function dobAgeMin(): int { return $this->v['dobAgeMin']; }
    public function dobAgeMax(): int { return $this->v['dobAgeMax']; }
    public function reviewInlineMaxChars(): int { return $this->v['reviewInlineMaxChars']; }
}
