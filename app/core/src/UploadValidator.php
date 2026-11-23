<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Upload checks (docs/PLAN.md section 4): count, per-file and total size,
 * UPLOAD_ERR_OK + is_uploaded_file, extension allowlist AND finfo MIME match,
 * Office ZIP checks (no macros, zip-bomb limits, no encrypted files), PDF/PNG/JPG
 * magic, CSV/TXT encoding, SHA-256, sanitized names. Never move_uploaded_file.
 * Implemented in Prompt 4.
 */
final class UploadValidator
{
    public const EXTENSIONS = ['docx', 'xlsx', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'txt'];

    public function __construct(
        private readonly int $maxFiles,
        private readonly int $maxFileBytes,
        private readonly int $maxTotalBytes,
    ) {
    }

    /**
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files from Http::readMultipart
     * @return list<UploadedFile>
     * @throws HttpError 400/413 with the failing field names
     */
    public function validate(array $files): array
    {
        throw new \LogicException('Prompt 4');
    }

    /** "01_" .. "05_" + the name reduced to [A-Za-z0-9._-], at most 100 characters. */
    public static function safeName(string $name, int $index): string
    {
        throw new \LogicException('Prompt 4');
    }
}
