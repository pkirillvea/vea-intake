<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Birthdate;

/** Birthdate is copied from vea-join; these pin its behaviour for the Legal form's date of birth. */
final class BirthdateTest extends TestCase
{
    public function testTodayIsEasternTime(): void
    {
        $utc = new DateTimeImmutable('2026-11-24 03:30', new DateTimeZone('UTC'));   // still Nov 23 in New York
        self::assertSame('2026-11-23', Birthdate::today($utc));
    }

    public function testCheck(): void
    {
        self::assertNull(Birthdate::check('1980-05-01', '2026-11-23', 16, 100));
        self::assertSame('invalid', Birthdate::check('1980-02-30', '2026-11-23', 16, 100));
        self::assertSame('invalid', Birthdate::check('05/01/1980', '2026-11-23', 16, 100));
        self::assertSame('future', Birthdate::check('2026-11-24', '2026-11-23', 16, 100));
        self::assertSame('out_of_range', Birthdate::check('2020-01-01', '2026-11-23', 16, 100));
    }

    public function testAgeAndLeapDay(): void
    {
        self::assertSame(29, Birthdate::age('1997-11-23', '2026-11-23'));
        self::assertSame(28, Birthdate::age('1997-11-24', '2026-11-23'));
        self::assertSame(25, Birthdate::age('2000-02-29', '2026-02-28'));
        self::assertSame(26, Birthdate::age('2000-02-29', '2026-03-01'));
        self::assertSame('05/01/1980', Birthdate::toUs('1980-05-01'));
    }
}
