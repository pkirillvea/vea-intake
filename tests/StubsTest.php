<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\AccessIdentity;
use VeaIntake\Core\Audit;
use VeaIntake\Core\FormData;
use VeaIntake\Tests\Support\MockHttpClient;

/** The Prompt 4 classes exist with their signatures and refuse to run until implemented. */
final class StubsTest extends TestCase
{
    public static function stubs(): iterable
    {
        $who = new AccessIdentity('a@example.org', 'A B', 'sub');
        yield 'Audit::build' => [fn () => Audit::build('id', $who, [], [], [], 'v', new \DateTimeImmutable())];
    }

    #[DataProvider('stubs')]
    public function testThrowsUntilPrompt4(\Closure $call): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Prompt 4');
        $call();
    }

    private static function formData(): FormData
    {
        return FormData::fromArray(['version' => 'v', 'notListed' => 'N', 'locals' => [['name' => 'N', 'worksites' => ['N']]], 'states' => ['Virginia']]);
    }
}
