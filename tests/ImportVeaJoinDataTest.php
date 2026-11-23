<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/** bin/import-vea-join-data.php: locals from dues.json, worksites copied byte for byte. */
final class ImportVeaJoinDataTest extends TestCase
{
    public function testImport(): void
    {
        $t = new TempTree();
        try {
            $t->json('join/dues.json', [
                ['local' => 'B E A', 'type' => 'AC-1-100', 'ttlDues' => 1],
                ['local' => 'A E A', 'type' => 'AC-1-100', 'ttlDues' => 2],
                ['local' => 'B E A', 'type' => 'AC-2-100', 'ttlDues' => 3],
            ]);
            $sites = "[\n  {\"local\": \"A E A\", \"worksite\": \"X\"}\n]\n";
            $t->write('join/worksites.json', $sites);

            [$code, $out] = $t->run('import-vea-join-data.php', "--from={$t->root}/join");
            self::assertSame(0, $code, $out);
            self::assertSame(['A E A', 'B E A'], json_decode($t->read('app/data/source/locals.json'), true));
            self::assertStringNotContainsString('ttlDues', $t->read('app/data/source/locals.json'), 'no dues figures copied');
            self::assertSame($sites, $t->read('app/data/source/worksites.json'));
            self::assertStringContainsString('2 locals, 1 worksite rows', $out);
        } finally {
            $t->remove();
        }
    }
}
