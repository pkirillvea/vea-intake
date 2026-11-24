<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The PDF's audit block (docs/PLAN.md section 11): submission ID, submitted (ISO-8601
 * in DISPLAY_TZ), submitted by (Access email), client IP (REMOTE_ADDR: nginx has
 * already replaced it with CF-Connecting-IP for Cloudflare peers), approximate
 * location (Cloudflare visitor location headers, else "not available"), user agent,
 * form and data versions, data SHA-256, and per file: name, size, type, SHA-256.
 * No signature (PLAN 1.4).
 */
final class Audit
{
    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $data validated data
     * @param list<UploadedFile> $files
     * @return array{id: string, submitted: string, submittedBy: string, ip: string, location: string, userAgent: string,
     *               formVersion: string, dataVersion: string, sha256: string,
     *               files: list<array{name: string, attachedAs: string, size: int, type: string, extension: string, sha256: string}>}
     */
    public static function build(string $id, AccessIdentity $who, array $server, array $data, array $files, DateTimeImmutable $now, string $tz, string $notAvailable): array
    {
        return [
            'id' => $id,
            'submitted' => $now->setTimezone(new DateTimeZone($tz))->format('c'),
            'submittedBy' => $who->email,
            'ip' => self::clientIp($server, $notAvailable),
            'location' => self::location($server, $notAvailable),
            'userAgent' => self::clean((string) ($server['HTTP_USER_AGENT'] ?? ''), 300) ?: $notAvailable,
            'formVersion' => (string) ($data['formVersion'] ?? ''),
            'dataVersion' => (string) ($data['dataVersion'] ?? ''),
            'sha256' => Validator::dataHash($data),
            'files' => array_map(static fn (UploadedFile $f): array => [
                'name' => $f->originalName, 'attachedAs' => $f->safeName, 'size' => $f->size, 'type' => $f->mime,
                'extension' => $f->extension, 'sha256' => $f->sha256,
            ], $files),
        ];
    }

    /** REMOTE_ADDR after nginx's real-IP module. */
    public static function clientIp(array $server, string $notAvailable = 'not available'): string
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $notAvailable;
    }

    /** "City, Region, Country" from cf-ipcity / cf-region / cf-ipcountry (whichever are present). */
    public static function location(array $server, string $notAvailable = 'not available'): string
    {
        $parts = [];
        foreach (['HTTP_CF_IPCITY', 'HTTP_CF_REGION', 'HTTP_CF_IPCOUNTRY'] as $h) {
            $v = self::clean((string) ($server[$h] ?? ''), 80);
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        return $parts === [] ? $notAvailable : implode(', ', $parts);
    }

    private static function clean(string $s, int $max): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = trim((string) preg_replace(Validator::CONTROL, '', $s));

        return mb_substr($s, 0, $max, 'UTF-8');
    }
}
