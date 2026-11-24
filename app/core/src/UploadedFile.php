<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/** One upload that passed UploadValidator (docs/PLAN.md section 4). */
final class UploadedFile
{
    public function __construct(
        public readonly string $originalName,   // as sent by the browser; display only, escaped
        public readonly string $safeName,       // 01_ .. 05_ + [A-Za-z0-9._-], max 100 chars
        public readonly int $size,
        public readonly string $extension,
        public readonly string $mime,           // content type by extension (finfo checked it); never the browser's
        public readonly string $sha256,
        public readonly string $tmpPath,        // under /run/vea-intake; PHP deletes it at request end
    ) {
    }
}
