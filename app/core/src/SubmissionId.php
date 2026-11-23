<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use DateTimeImmutable;

/**
 * Submission ID: YYYYMMDD-HHMM-XXXX (local display time + 4 Crockford base32 chars).
 * Copied from vea-join as-is (namespace only).
 */
final class SubmissionId
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';   // Crockford: no I, L, O, U
    public const PATTERN = '/^\d{8}-\d{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    public static function generate(DateTimeImmutable $now): string
    {
        $bits = unpack('N', "\0" . random_bytes(3))[1];   // 24 random bits; 20 used
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= self::ALPHABET[($bits >> (5 * $i)) & 31];
        }

        return $now->format('Ymd-Hi') . '-' . $suffix;
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match(self::PATTERN, $id);
    }
}
