<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Date-of-birth rules. Copied from vea-join as-is (namespace only); used for the
 * Legal form's optional date of birth ("plausible range", docs/PLAN.md section 4).
 * The value is an ISO date YYYY-MM-DD; it must be a real calendar date, not in
 * the future, and the age on today's date in America/New_York must be within
 * the given range. The front end mirrors these rules (Prompt 3).
 *
 * Age is whole years: it goes up on the birthday itself. Someone born on
 * Feb 29 turns a year older on Mar 1 in non-leap years.
 */
final class Birthdate
{
    public const TZ = 'America/New_York';

    /** Today's date in America/New_York as YYYY-MM-DD. */
    public static function today(?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** @return array{0: int, 1: int, 2: int}|null [year, month, day] for a real calendar date */
    public static function parse(string $iso): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return checkdate($mo, $d, $y) ? [$y, $mo, $d] : null;
    }

    /** Whole years from $birth to $today (both valid YYYY-MM-DD). */
    public static function age(string $birth, string $today): int
    {
        [$by, $bm, $bd] = self::parse($birth) ?? throw new \InvalidArgumentException('invalid birthdate');
        [$ty, $tm, $td] = self::parse($today) ?? throw new \InvalidArgumentException('invalid date');

        return $ty - $by - (($tm < $bm || ($tm === $bm && $td < $bd)) ? 1 : 0);
    }

    /** null when acceptable, otherwise the reason: invalid | future | out_of_range. */
    public static function check(string $iso, string $today, int $ageMin, int $ageMax): ?string
    {
        if (self::parse($iso) === null) {
            return 'invalid';
        }
        if (strcmp($iso, $today) > 0) {    // same fixed-width format: string order is date order
            return 'future';
        }
        $age = self::age($iso, $today);

        return ($age < $ageMin || $age > $ageMax) ? 'out_of_range' : null;
    }

    /** YYYY-MM-DD → MM/DD/YYYY (display in the PDF). */
    public static function toUs(string $iso): string
    {
        [$y, $m, $d] = self::parse($iso) ?? throw new \InvalidArgumentException('invalid date');

        return sprintf('%02d/%02d/%04d', $m, $d, $y);
    }
}
