<?php

declare(strict_types=1);

namespace VeaIntake\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use VeaIntake\Core\FormData;
use VeaIntake\Core\Template;

/** FAKE Legal form data for tests (never real people). */
final class LegalFixtures
{
    public const NOW = '2026-11-23 12:00:00';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('America/New_York'));
    }

    public static function formData(): FormData
    {
        return FormData::load();
    }

    /** A local with at least two real worksites. @return array{name: string, worksites: list<string>} */
    public static function local(): array
    {
        $fd = self::formData();
        foreach ($fd->localNames() as $name) {
            $ws = $fd->worksites($name) ?? [];
            if ($name !== $fd->notListed() && count($ws) > 2) {
                return ['name' => $name, 'worksites' => $ws];
            }
        }
        throw new \RuntimeException('no local with worksites');
    }

    /** @return array<string, string> a valid payload (docs/PAYLOAD.md section 3) */
    public static function payload(array $over = []): array
    {
        $local = self::local();

        return $over + [
            'formVersion' => Template::formVersion('legal'),
            'dataVersion' => self::formData()->version(),
            'firstName' => 'Testy',
            'lastName' => 'McTestface',
            'membershipNumber' => '0001234567',
            'street' => '123 Fake Street',
            'city' => 'Richmond',
            'state' => 'Virginia',
            'zip' => '23219',
            'phone' => '8045551234',
            'email' => 'testy.member@example.org',
            'dateOfBirth' => '',
            'local' => $local['name'],
            'localOther' => '',
            'worksite' => $local['worksites'][0],
            'worksiteOther' => '',
            'jobTitle' => 'Teacher',
            'timeInPosition' => '3 years',
            'issueType' => 'Dismissal',
            'issueTypeOther' => '',
            'incidentDate' => '2026-11-01',
            'narrative' => "A short fake narrative.\n\nSecond paragraph.",
            'requestedOutcome' => 'Advice on next steps',
            'website' => '',
        ];
    }
}
