<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeaIntake\Core\FormData;

final class FormDataTest extends TestCase
{
    private static function data(): FormData
    {
        return FormData::fromArray([
            'version' => 'abc',
            'notListed' => 'OTHER / NOT LISTED',
            'locals' => [
                ['name' => 'A E A', 'worksites' => ['School 1', 'OTHER / NOT LISTED']],
                ['name' => 'OTHER / NOT LISTED', 'worksites' => ['OTHER / NOT LISTED']],
            ],
            'states' => ['Virginia', 'Maryland'],
        ]);
    }

    public function testLookups(): void
    {
        $d = self::data();
        self::assertSame(['A E A', 'OTHER / NOT LISTED'], $d->localNames());
        self::assertTrue($d->hasLocal('OTHER / NOT LISTED'));
        self::assertFalse($d->hasLocal('a e a'), 'exact match only');
        self::assertTrue($d->isWorksite('A E A', 'School 1'));
        self::assertTrue($d->isWorksite('A E A', 'OTHER / NOT LISTED'));
        self::assertFalse($d->isWorksite('A E A', 'School 2'));
        self::assertNull($d->worksites('Z'));
        self::assertTrue($d->isState('Virginia'));
        self::assertSame('OTHER / NOT LISTED', $d->notListed());
    }

    public function testRejectsUnexpectedShape(): void
    {
        $this->expectException(RuntimeException::class);
        FormData::fromArray(['version' => 'x', 'locals' => [], 'states' => []]);
    }

    public function testBuiltFileLoadsAndEndsWithNotListed(): void
    {
        if (!is_file(FormData::DEFAULT_PATH)) {
            self::markTestSkipped('run composer build-data first');
        }
        $d = FormData::load();
        $names = $d->localNames();
        self::assertSame($d->notListed(), end($names));
        foreach ($names as $local) {
            $sites = $d->worksites($local);
            self::assertSame($d->notListed(), end($sites), "$local: NOT LISTED last");
        }
        self::assertContains('Virginia', $d->states());
    }
}
