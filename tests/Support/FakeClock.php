<?php

declare(strict_types=1);

namespace VeaIntake\Tests\Support;

/** A clock that only moves when told to: sleep() advances it instead of waiting. */
final class FakeClock
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $now = 1_800_000_000.0)
    {
    }

    public function clock(): \Closure
    {
        return fn (): float => $this->now;
    }

    public function sleeper(): \Closure
    {
        return function (float $seconds): void {
            $this->sleeps[] = $seconds;
            $this->now += $seconds;
        };
    }
}
