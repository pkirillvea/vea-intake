<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Validator;

/** The generic helpers of the core Validator, through a tiny test subclass. */
final class ValidatorTest extends TestCase
{
    private static function v(): Validator
    {
        return new class () extends Validator {
            public function validate(array $in): array
            {
                $this->errors = [];
                $this->unknownKeys($in, ['name', 'zip', 'kind', 'notes']);
                $d = [
                    'name' => $this->str($in, 'name', 1, 5),
                    'zip' => $this->match($in, 'zip', '/^\d{5}$/'),
                    'kind' => $this->enum($in, 'kind', ['a', 'b']),
                    'notes' => $this->str($in, 'notes', 0, 20, true),
                ];

                return ['errors' => $this->errors, 'data' => $d];
            }
        };
    }

    public function testValidInputIsTrimmed(): void
    {
        $r = self::v()->validate(['name' => '  Ann ', 'zip' => '23220', 'kind' => 'a', 'notes' => "line 1\nline 2"]);
        self::assertSame([], $r['errors']);
        self::assertSame('Ann', $r['data']['name']);
        self::assertSame("line 1\nline 2", $r['data']['notes']);
    }

    public function testFailures(): void
    {
        $r = self::v()->validate(['name' => "An\nn", 'zip' => '2322', 'kind' => 'c', 'notes' => "\u{202E}x", 'extra' => 1]);
        self::assertSame(['extra' => 'unknown', 'name' => 'invalid', 'zip' => 'invalid', 'kind' => 'invalid', 'notes' => 'invalid'], $r['errors']);
        self::assertSame(['extra', 'name', 'zip', 'kind', 'notes'], Validator::fieldList($r['errors']));

        $r = self::v()->validate(['name' => 'Annabel', 'zip' => '', 'kind' => 'a']);
        self::assertSame(['name' => 'too_long', 'zip' => 'required', 'notes' => 'required'], $r['errors']);
    }

    public function testDataHashIgnoresKeyOrder(): void
    {
        $a = Validator::dataHash(['b' => 1, 'a' => ['y' => 2, 'x' => 'é']]);
        self::assertSame($a, Validator::dataHash(['a' => ['x' => 'é', 'y' => 2], 'b' => 1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, Validator::dataHash(['b' => 2, 'a' => ['y' => 2, 'x' => 'é']]));
    }

    public function testWordCount(): void
    {
        self::assertSame(0, Validator::wordCount("  \n "));
        self::assertSame(4, Validator::wordCount(" one two\nthree\t four "));
    }
}
