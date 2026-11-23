<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/** bin/build-data.php against small fixture trees. */
final class BuildDataTest extends TestCase
{
    private TempTree $t;

    protected function setUp(): void
    {
        $this->t = new TempTree();
    }

    protected function tearDown(): void
    {
        $this->t->remove();
    }

    private function source(array $locals, array $worksites): void
    {
        $this->t->json('app/data/source/locals.json', $locals);
        $this->t->json('app/data/source/worksites.json', $worksites);
    }

    /** @return array<string, mixed> */
    private function built(): array
    {
        return json_decode($this->t->read('app/data/form-data.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    public function testCountsSortingAndNotListedEntries(): void
    {
        $this->source(['B LOCAL', 'A LOCAL', 'C LOCAL'], [
            ['local' => 'A LOCAL', 'worksite' => 'School 10'],
            ['local' => 'A LOCAL', 'worksite' => 'School 2'],
            ['local' => 'B LOCAL', 'worksite' => 'Central Office'],
        ]);
        [$code, $out] = $this->t->run('build-data.php');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('3 locals (+ "OTHER / NOT LISTED"), 3 worksites, 51 states', $out);

        $d = $this->built();
        self::assertSame('OTHER / NOT LISTED', $d['notListed']);
        self::assertSame(['A LOCAL', 'B LOCAL', 'C LOCAL', 'OTHER / NOT LISTED'], array_column($d['locals'], 'name'));
        self::assertSame(['School 2', 'School 10', 'OTHER / NOT LISTED'], $d['locals'][0]['worksites'], 'natural sort, NOT LISTED last');
        self::assertSame(['OTHER / NOT LISTED'], $d['locals'][2]['worksites'], 'a local without worksites offers only NOT LISTED');
        self::assertSame(['OTHER / NOT LISTED'], $d['locals'][3]['worksites']);
        self::assertContains('Virginia', $d['states']);
        self::assertCount(51, $d['states']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $d['version']);
        self::assertStringContainsString('WARNING: worksites "C LOCAL": no worksites', $out);
    }

    public function testBothCopiesAreIdenticalAndVersionIsDeterministic(): void
    {
        $this->source(['A LOCAL'], [['local' => 'A LOCAL', 'worksite' => 'X']]);
        $this->t->run('build-data.php');
        $first = $this->t->read('app/data/form-data.json');
        self::assertSame($first, $this->t->read('public/legal/data/form-data.json'));
        $this->t->run('build-data.php');
        self::assertSame($first, $this->t->read('app/data/form-data.json'), 'same input, same bytes');
    }

    public function testDuplicatesAreRemovedWithWarnings(): void
    {
        $this->source(['A LOCAL', 'A LOCAL', ' B LOCAL '], [
            ['local' => 'A LOCAL', 'worksite' => 'X'],
            ['local' => 'A LOCAL', 'worksite' => 'X'],
            ['local' => 'B LOCAL', 'worksite' => ' Y '],
        ]);
        [$code, $out] = $this->t->run('build-data.php');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('WARNING: locals.json: duplicate local "A LOCAL" (removed)', $out);
        self::assertStringContainsString('WARNING: worksites "A LOCAL": duplicate worksite "X" (removed)', $out);
        self::assertStringContainsString('has surrounding spaces (trimmed)', $out);
        $d = $this->built();
        self::assertSame(['A LOCAL', 'B LOCAL', 'OTHER / NOT LISTED'], array_column($d['locals'], 'name'));
        self::assertSame(['X', 'OTHER / NOT LISTED'], $d['locals'][0]['worksites']);
        self::assertSame(['Y', 'OTHER / NOT LISTED'], $d['locals'][1]['worksites']);
    }

    public function testReservedValueIsAnErrorAndNothingIsWritten(): void
    {
        $this->source(['A LOCAL', 'other / not listed'], [['local' => 'A LOCAL', 'worksite' => 'OTHER / NOT LISTED']]);
        [$code, $out] = $this->t->run('build-data.php');
        self::assertSame(1, $code);
        self::assertStringContainsString('is reserved', $out);
        self::assertStringContainsString('Nothing written.', $out);
        self::assertFileDoesNotExist($this->t->root . '/app/data/form-data.json');
        self::assertFileDoesNotExist($this->t->root . '/public/legal/data/form-data.json');
    }

    public function testUnknownLocalInWorksitesIsAnError(): void
    {
        $this->source(['A LOCAL'], [['local' => 'Z LOCAL', 'worksite' => 'X']]);
        [$code, $out] = $this->t->run('build-data.php');
        self::assertSame(1, $code);
        self::assertStringContainsString('local "Z LOCAL" is not in locals.json', $out);
    }

    public function testUnreadableSourceExits2(): void
    {
        [$code, $out] = $this->t->run('build-data.php');
        self::assertSame(2, $code);
        self::assertStringContainsString('FATAL: cannot read', $out);
    }
}
