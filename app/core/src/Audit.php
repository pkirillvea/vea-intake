<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use DateTimeImmutable;

/**
 * The PDF's audit block (docs/PLAN.md section 11): submission ID, submitted (ET,
 * ISO-8601), submitted by (Access identity), client IP (real IP via nginx),
 * approximate location (Cloudflare visitor location headers), user agent, form
 * version, data SHA-256, and per file: name, size, type, SHA-256.
 * No signature method (PLAN 1.4). Implemented in Prompt 4.
 */
final class Audit
{
    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $data validated data
     * @param list<UploadedFile> $files
     * @return array<string, mixed>
     */
    public static function build(string $id, AccessIdentity $who, array $server, array $data, array $files, string $formVersion, DateTimeImmutable $now): array
    {
        throw new \LogicException('Prompt 4');
    }

    /** REMOTE_ADDR (nginx has already replaced it with CF-Connecting-IP for Cloudflare peers). */
    public static function clientIp(array $server): string
    {
        throw new \LogicException('Prompt 4');
    }

    /** "City, Region, Country" from the cf-ipcity / cf-region / cf-ipcountry headers, or "Unknown". */
    public static function location(array $server): string
    {
        throw new \LogicException('Prompt 4');
    }
}
