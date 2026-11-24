<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Deadline;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Tests\Support\FakeClock;

final class DeadlineTest extends TestCase
{
    public function testCountsFromRequestStart(): void
    {
        $c = new FakeClock(1000.0);
        $d = Deadline::fromRequest(['REQUEST_TIME_FLOAT' => 990.0], 80.0, $c->clock());
        self::assertEqualsWithDelta(70.0, $d->remaining(), 0.001);
        self::assertSame(15, $d->timeout(15));
        $c->now = 1065.5;
        self::assertSame(4, $d->timeout(15), 'never past the deadline');
        $c->now = 1071.0;
        self::assertSame(0, $d->timeout(15));
        self::assertTrue($d->expired());
    }

    public function testImplausibleStartFallsBackToNow(): void
    {
        $c = new FakeClock(1000.0);
        self::assertEqualsWithDelta(80.0, Deadline::fromRequest(['REQUEST_TIME_FLOAT' => 'x'], 80.0, $c->clock())->remaining(), 0.001);
        self::assertEqualsWithDelta(80.0, Deadline::fromRequest(['REQUEST_TIME_FLOAT' => 5000.0], 80.0, $c->clock())->remaining(), 0.001);
        self::assertEqualsWithDelta(80.0, Deadline::fromRequest([], 80.0, $c->clock())->remaining(), 0.001);
    }

    public function testResponseHeadersAreCaseInsensitive(): void
    {
        $r = new HttpResponse(429, '', ['retry-after' => '7']);
        self::assertSame('7', $r->header('Retry-After'));
        self::assertNull($r->header('Location'));
    }
}
