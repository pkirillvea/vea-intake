<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Log;

final class LogTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::setWriter(null);
    }

    public function testOnlyAllowedKeysAndCleanValues(): void
    {
        $lines = [];
        Log::setWriter(static function (string $l) use (&$lines): void { $lines[] = $l; });
        Log::event('submit ok', ['form' => 'legal', 'id' => '20261123-1200-ABCD', 'narrative' => 'secret text', 'reason' => "a b\nc"]);
        self::assertSame(['vea-intake event=submit_ok form=legal id=20261123-1200-ABCD reason=a_b_c'], $lines);
    }
}
