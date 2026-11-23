<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\SubmissionId;

/** Copied from vea-join as-is (namespace only). */
final class SubmissionIdTest extends TestCase
{
    public function testFormat(): void
    {
        $now = new DateTimeImmutable('2026-11-20 09:41:07', new DateTimeZone('America/New_York'));
        for ($i = 0; $i < 200; $i++) {
            $id = SubmissionId::generate($now);
            self::assertMatchesRegularExpression('/^20261120-0941-[0-9A-HJKMNP-TV-Z]{4}$/', $id);
            self::assertTrue(SubmissionId::isValid($id));
        }
    }

    public function testUsesCrockfordAlphabetAndVaries(): void
    {
        $now = new DateTimeImmutable('2026-01-02 03:04');
        $seen = [];
        $chars = '';
        for ($i = 0; $i < 500; $i++) {
            $id = SubmissionId::generate($now);
            $seen[$id] = true;
            $chars .= substr($id, -4);
        }
        self::assertGreaterThan(480, count($seen), 'random part should rarely repeat');
        self::assertDoesNotMatchRegularExpression('/[ILOU]/', $chars);
        self::assertSame(32, strlen(SubmissionId::ALPHABET));
    }

    public function testRejectsBadIds(): void
    {
        foreach (['20261120-0941-ABCI', '2026112-0941-ABCD', '20261120-941-ABCD', '20261120-0941-abcd', '20261120-0941-ABCDE'] as $bad) {
            self::assertFalse(SubmissionId::isValid($bad), $bad);
        }
    }
}
