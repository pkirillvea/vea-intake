<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use VeaIntake\Core\Birthdate;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\Template;

/**
 * The answers as label/value rows per section, for the PDF: the same rows, labels,
 * formats and long-answer rule as the review page (legal.js renderReview(),
 * row(), isLongText(); docs/PLAN.md section 11).
 *
 * A row is "long" (value below its label at full width, line breaks kept) when it
 * is the narrative, the requested outcome, or the issue type when it is "Other";
 * or when its text has a line break or is longer than limits.reviewInlineMaxChars.
 * The address is two structured lines and stays inline, as on the review page.
 */
final class LegalAnswers
{
    /**
     * @param array<string, mixed> $d validated data (LegalValidator)
     * @param array<string, mixed> $c content.json
     * @return list<array{title: string, rows: list<array{label: string, lines: list<string>, long: bool}>}>
     */
    public static function sections(array $d, array $c, FormLimits $limits, string $notListed): array
    {
        $F = $c['fields'];
        $S = $c['sections'];
        $max = $limits->reviewInlineMaxChars();
        $row = static function (string $label, string $value, bool $alwaysLong = false) use ($max, $c): array {
            $text = $value === '' ? (string) $c['review']['notProvided'] : $value;

            return ['label' => $label, 'lines' => [$text], 'long' => $alwaysLong || self::isLongText($text, $max)];
        };
        $withOther = static fn (string $value, string $other): string => $other !== '' ? "$value: $other" : $value;
        $issueOther = $d['issueType'] === $F['issueType']['otherValue'];

        return [
            ['title' => $S['member'], 'rows' => [
                $row($F['firstName']['label'] . ' / ' . $F['lastName']['label'], $d['firstName'] . ' ' . $d['lastName']),
                $row($F['membershipNumber']['label'], $d['membershipNumber']),
                ['label' => $F['address']['label'], 'lines' => [$d['street'], "{$d['city']}, {$d['state']} {$d['zip']}"], 'long' => false],
                $row($F['phone']['label'], self::phone($d['phone'])),
                $row($F['email']['label'], $d['email']),
                $row($F['dateOfBirth']['label'], $d['dateOfBirth'] !== '' ? Birthdate::toUs($d['dateOfBirth']) : $F['dateOfBirth']['notProvided']),
            ]],
            ['title' => $S['employment'], 'rows' => [
                $row($F['local']['label'], $withOther($d['local'], $d['local'] === $notListed ? $d['localOther'] : '')),
                $row($F['worksite']['label'], $withOther($d['worksite'], $d['worksite'] === $notListed ? $d['worksiteOther'] : '')),
                $row($F['jobTitle']['label'], $d['jobTitle']),
                $row($F['timeInPosition']['label'], $d['timeInPosition']),
            ]],
            ['title' => $S['issue'], 'rows' => [
                $row($F['issueType']['label'], $withOther($d['issueType'], $issueOther ? $d['issueTypeOther'] : ''), $issueOther),
                $row($F['incidentDate']['label'], Birthdate::toUs($d['incidentDate'])),
                $row($F['narrative']['label'], $d['narrative'], true),
                $row($F['requestedOutcome']['label'], $d['requestedOutcome'], true),
            ]],
        ];
    }

    /** legal.js isLongText(): a line break, or more than $max characters. */
    public static function isLongText(string $text, int $max): bool
    {
        return (bool) preg_match('/[\r\n]/', $text) || mb_strlen($text, 'UTF-8') > $max;
    }

    /** (804) 555-1234, as legal.js formatPhone(). */
    public static function phone(string $digits): string
    {
        return strlen($digits) === 10 ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6)) : $digits;
    }

    /** "34 KB" / "1.2 MB" from content.json uploads.sizeKb/sizeMb, as legal.js sizeText(). */
    public static function size(int $bytes, array $c): string
    {
        return $bytes < 1024 * 1024
            ? Template::fmt((string) $c['uploads']['sizeKb'], ['n' => max(1, (int) ceil($bytes / 1024))])
            : Template::fmt((string) $c['uploads']['sizeMb'], ['n' => number_format($bytes / (1024 * 1024), 1)]);
    }
}
