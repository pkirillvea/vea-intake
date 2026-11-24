<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessIdentity;
use VeaIntake\Core\Audit;
use VeaIntake\Core\UploadedFile;
use VeaIntake\Core\Validator;

final class AuditTest extends TestCase
{
    public function testBuild(): void
    {
        $now = new DateTimeImmutable('2026-11-23 17:05:09', new DateTimeZone('UTC'));
        $file = new UploadedFile('Letter.pdf', '01_Letter.pdf', 1234, 'pdf', 'application/pdf', str_repeat('a', 64), '/run/vea-intake/php123');
        $data = ['formVersion' => '2026.11-1', 'dataVersion' => 'abc', 'firstName' => 'Testy'];
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => "Agent\x00/1.0\n", 'HTTP_CF_IPCITY' => 'Richmond', 'HTTP_CF_REGION' => 'Virginia', 'HTTP_CF_IPCOUNTRY' => 'US'];
        $a = Audit::build('20261123-1205-ABCD', new AccessIdentity('staff@veanea.org'), $server, $data, [$file], $now, 'America/New_York', 'not available');
        self::assertSame('2026-11-23T12:05:09-05:00', $a['submitted'], 'ISO-8601 in DISPLAY_TZ');
        self::assertSame('staff@veanea.org', $a['submittedBy']);
        self::assertSame('203.0.113.9', $a['ip']);
        self::assertSame('Richmond, Virginia, US', $a['location']);
        self::assertSame('Agent/1.0', $a['userAgent'], 'control characters removed');
        self::assertSame(['2026.11-1', 'abc'], [$a['formVersion'], $a['dataVersion']]);
        self::assertSame(Validator::dataHash($data), $a['sha256']);
        self::assertSame([['name' => 'Letter.pdf', 'attachedAs' => '01_Letter.pdf', 'size' => 1234, 'type' => 'application/pdf', 'extension' => 'pdf', 'sha256' => str_repeat('a', 64), 'passwordProtected' => false]], $a['files']);
        self::assertArrayNotHasKey('signature', $a);
    }

    public function testMissingLocationAndBadIp(): void
    {
        self::assertSame('not available', Audit::location([]));
        self::assertSame('US', Audit::location(['HTTP_CF_IPCOUNTRY' => 'US']));
        self::assertSame('not available', Audit::location(['HTTP_CF_IPCITY' => " \x01 "]));
        self::assertSame('not available', Audit::clientIp(['REMOTE_ADDR' => 'unix:']));
        self::assertSame('2001:db8::1', Audit::clientIp(['REMOTE_ADDR' => '2001:db8::1']));
        $a = Audit::build('x', new AccessIdentity('a@b.org'), [], [], [], new DateTimeImmutable(), 'America/New_York', 'n/a');
        self::assertSame(['n/a', 'n/a', 'n/a'], [$a['ip'], $a['location'], $a['userAgent']]);
    }
}
