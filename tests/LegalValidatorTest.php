<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\Template;
use VeaIntake\Forms\Legal\LegalValidator;
use VeaIntake\Tests\Support\LegalFixtures as Fx;

/** Every rule of docs/PAYLOAD.md section 3, with boundaries. Today = 2026-11-23 (ET). */
final class LegalValidatorTest extends TestCase
{
    /** @return array{errors: array<string, string>, data: array<string, mixed>} */
    private static function check(array $payload): array
    {
        $c = Template::content('legal');

        return (new LegalValidator(Fx::formData(), $c, FormLimits::fromContent($c), Fx::now()))->validate($payload);
    }

    /** @return list<string> */
    private static function failing(array $over, array $unset = []): array
    {
        $p = Fx::payload($over);
        foreach ($unset as $k) {
            unset($p[$k]);
        }

        return array_keys(self::check($p)['errors']);
    }

    private static function words(int $n): string
    {
        return implode(' ', array_map(static fn (int $i): string => "w$i", range(1, $n)));
    }

    public function testValidPayload(): void
    {
        $r = self::check(Fx::payload(['firstName' => '  Testy  ']));
        self::assertSame([], $r['errors']);
        self::assertSame('Testy', $r['data']['firstName'], 'trimmed');
        self::assertSame('0001234567', $r['data']['membershipNumber'], 'leading zeros kept');
        self::assertArrayNotHasKey('website', $r['data']);
        self::assertSame(array_values(array_diff(LegalValidator::KEYS, ['website'])), array_keys($r['data']));
    }

    public function testUnknownKeyIsReportedAsPayloadOnly(): void
    {
        self::assertSame(['payload'], self::failing(['<script>' => 'x']));
    }

    public static function keys(): iterable
    {
        foreach (LegalValidator::KEYS as $k) {
            yield $k => [$k];
        }
    }

    #[DataProvider('keys')]
    public function testEveryKeyIsRequired(string $key): void
    {
        self::assertContains($key, self::failing([], [$key]));
    }

    public function testStaleVersions(): void
    {
        self::assertSame(['formVersion'], self::failing(['formVersion' => '2020.1-1']));
        self::assertSame(['dataVersion'], self::failing(['dataVersion' => 'deadbeef']));
    }

    public function testLengthsAndControlCharacters(): void
    {
        self::assertSame([], self::failing(['firstName' => str_repeat('é', 50), 'lastName' => str_repeat('b', 50)]));
        self::assertSame(['firstName', 'lastName'], self::failing(['firstName' => str_repeat('a', 51), 'lastName' => '   ']));
        self::assertSame([], self::failing(['street' => str_repeat('s', 100), 'city' => str_repeat('c', 50), 'jobTitle' => str_repeat('j', 100), 'timeInPosition' => str_repeat('t', 50)]));
        self::assertSame(['street', 'city', 'jobTitle', 'timeInPosition'], self::failing(['street' => str_repeat('s', 101), 'city' => str_repeat('c', 51), 'jobTitle' => str_repeat('j', 101), 'timeInPosition' => str_repeat('t', 51)]));
        self::assertSame(['firstName'], self::failing(['firstName' => "Tes\u{202E}ty"]), 'bidi override');
        self::assertSame(['lastName'], self::failing(['lastName' => "Mc\nTest"]), 'no newline in single-line fields');
        self::assertSame(['city'], self::failing(['city' => ['Richmond']]), 'not a string');
        self::assertSame(['city'], self::failing(['city' => "\xC3\x28"]), 'invalid UTF-8');
    }

    public function testMembershipNumberZipPhoneEmailState(): void
    {
        foreach (['000123456', '00012345678', '00012345a7', '0001 23456'] as $bad) {
            self::assertSame(['membershipNumber'], self::failing(['membershipNumber' => $bad]), $bad);
        }
        self::assertSame([], self::failing(['zip' => '23219-1234']));
        foreach (['2321', '23219-12', 'ABCDE'] as $bad) {
            self::assertSame(['zip'], self::failing(['zip' => $bad]), $bad);
        }
        foreach (['18045551234', '1045551234', '8041551234', '(804) 555-1234'] as $bad) {
            self::assertSame(['phone'], self::failing(['phone' => $bad]), $bad);
        }
        self::assertSame(['email'], self::failing(['email' => 'not-an-email']));
        self::assertSame(['email'], self::failing(['email' => str_repeat('a', 250) . '@x.org']));
        self::assertSame([], self::failing(['state' => 'District of Columbia']));
        self::assertSame(['state'], self::failing(['state' => 'Narnia']));
    }

    public function testDateOfBirthRange(): void
    {
        self::assertSame([], self::failing(['dateOfBirth' => '']), 'optional');
        self::assertSame([], self::failing(['dateOfBirth' => '2010-11-23']), 'exactly 16 today');
        self::assertSame(['dateOfBirth'], self::failing(['dateOfBirth' => '2010-11-24']), '15');
        self::assertSame([], self::failing(['dateOfBirth' => '1926-11-24']), '99');
        self::assertSame([], self::failing(['dateOfBirth' => '1926-11-23']), '100');
        self::assertSame(['dateOfBirth'], self::failing(['dateOfBirth' => '1925-11-22']), '101');
        self::assertSame(['dateOfBirth'], self::failing(['dateOfBirth' => '2026-11-24']), 'future');
        self::assertSame(['dateOfBirth'], self::failing(['dateOfBirth' => '1990-02-30']), 'not a date');
    }

    public function testIncidentDate(): void
    {
        self::assertSame([], self::failing(['incidentDate' => '2026-11-23']), 'today');
        self::assertSame(['incidentDate'], self::failing(['incidentDate' => '2026-11-24']), 'future');
        self::assertSame(['incidentDate'], self::failing(['incidentDate' => '11/01/2026']));
        self::assertSame(['incidentDate'], self::failing(['incidentDate' => '']));
    }

    public function testLocalWorksiteAndOtherRules(): void
    {
        $fx = Fx::local();
        $fd = Fx::formData();
        $nl = $fd->notListed();
        $otherLocal = '';
        foreach ($fd->localNames() as $n) {
            if ($n !== $fx['name'] && $n !== $nl && !in_array($fx['worksites'][0], $fd->worksites($n) ?? [], true)) {
                $otherLocal = $n;
                break;
            }
        }
        self::assertSame(['local'], self::failing(['local' => 'Local 999 of Nowhere']));
        self::assertSame(['worksite'], self::failing(['local' => $otherLocal]), 'worksite of another local');
        self::assertSame(['localOther'], self::failing(['local' => $nl, 'worksite' => $nl, 'worksiteOther' => 'Somewhere']));
        self::assertSame([], self::failing(['local' => $nl, 'localOther' => 'Fake Local', 'worksite' => $nl, 'worksiteOther' => 'Fake School']));
        self::assertSame(['worksiteOther'], self::failing(['worksite' => $nl]));
        self::assertSame([], self::failing(['worksite' => $nl, 'worksiteOther' => 'Fake Annex']));
        self::assertSame(['localOther'], self::failing(['localOther' => 'should be empty']));
        self::assertSame(['worksiteOther'], self::failing(['worksiteOther' => 'should be empty']));
        self::assertSame(['localOther'], self::failing(['local' => $nl, 'localOther' => str_repeat('x', 101), 'worksite' => $nl, 'worksiteOther' => 'x']));
    }

    public function testIssueTypeOther(): void
    {
        self::assertSame(['issueType'], self::failing(['issueType' => 'Parking']));
        self::assertSame(['issueTypeOther'], self::failing(['issueType' => 'Other']));
        self::assertSame([], self::failing(['issueType' => 'Other', 'issueTypeOther' => str_repeat('o', 100)]));
        self::assertSame(['issueTypeOther'], self::failing(['issueType' => 'Other', 'issueTypeOther' => str_repeat('o', 101)]));
        self::assertSame(['issueTypeOther'], self::failing(['issueTypeOther' => 'only with Other']));
    }

    public function testNarrativeWordsCharactersAndLineBreaks(): void
    {
        self::assertSame([], self::failing(['narrative' => self::words(300)]));
        self::assertSame(['narrative'], self::failing(['narrative' => self::words(301)]));
        self::assertSame([], self::failing(['narrative' => "Line one\twith tab\r\nLine two\n\nAfter a blank line"]));
        self::assertSame([], self::failing(['narrative' => str_repeat('a', 3000)]));
        self::assertSame(['narrative'], self::failing(['narrative' => str_repeat('a', 3001)]));
        self::assertSame(['narrative'], self::failing(['narrative' => "bell\x07"]));
        self::assertSame(['narrative'], self::failing(['narrative' => "  \n  "]));
    }

    public function testRequestedOutcome(): void
    {
        self::assertSame([], self::failing(['requestedOutcome' => str_repeat('r', 1000)]));
        self::assertSame(['requestedOutcome'], self::failing(['requestedOutcome' => str_repeat('r', 1001)]));
        self::assertSame(['requestedOutcome'], self::failing(['requestedOutcome' => "two\nlines"]));
    }

    public function testHoneypotMustBeAString(): void
    {
        self::assertSame(['website'], self::failing(['website' => ['x']]));
    }

    public function testFormLimitsComeFromContentJson(): void
    {
        $l = FormLimits::fromContent(Template::content('legal'));
        self::assertSame([5, 10 * 1024 * 1024, 25 * 1024 * 1024, 300, 3000, 1000, 16, 100, 40],
            [$l->maxFiles(), $l->maxFileBytes(), $l->maxTotalBytes(), $l->narrativeMaxWords(), $l->narrativeMaxChars(), $l->requestedOutcomeMaxChars(), $l->dobAgeMin(), $l->dobAgeMax(), $l->reviewInlineMaxChars()]);
        self::assertSame(['docx', 'xlsx', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'txt'], $l->extensions);
        $this->expectException(\RuntimeException::class);
        FormLimits::fromContent(['limits' => ['maxFiles' => 0]]);
    }
}
