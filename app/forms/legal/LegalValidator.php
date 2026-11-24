<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use DateTimeImmutable;
use VeaIntake\Core\Birthdate;
use VeaIntake\Core\FormData;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\Validator;

/**
 * Authoritative validation of the Legal Assistance Request payload
 * (docs/PAYLOAD.md section 3; docs/PLAN.md sections 4 and 10).
 *
 * - Every key must be present (empty string when not applicable); unknown keys
 *   are rejected and reported as the single field "payload" (an attacker-chosen
 *   key name is never echoed back).
 * - Strings are trimmed and length-capped; control characters are rejected
 *   (tab/LF/CR allowed in the narrative only).
 * - Choices are whitelisted against form-data.json (locals, worksites, states)
 *   and content.json (issue types); limits come from content.json (FormLimits).
 * - "OTHER / NOT LISTED" / "Other" require their text; otherwise the text must be empty.
 * - Dates: real calendar dates; incident date not after today and date of birth
 *   in the plausible age range, both on today's date in America/New_York.
 *
 * The honeypot ("website") is handled by the handler before validation; here it
 * only has to be a string, and it is not part of the returned data.
 */
final class LegalValidator extends Validator
{
    public const KEYS = [
        'formVersion', 'dataVersion', 'firstName', 'lastName', 'membershipNumber', 'street', 'city', 'state', 'zip',
        'phone', 'email', 'dateOfBirth', 'local', 'localOther', 'worksite', 'worksiteOther', 'jobTitle',
        'timeInPosition', 'issueType', 'issueTypeOther', 'incidentDate', 'narrative', 'requestedOutcome', 'website',
    ];
    public const OTHER_MAX = 100;   // localOther, worksiteOther (PAYLOAD.md)

    /** @param array<string, mixed> $content the form's content.json */
    public function __construct(
        private readonly FormData $formData,
        private readonly array $content,
        private readonly FormLimits $limits,
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function validate(array $in): array
    {
        $this->errors = [];
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::KEYS, true)) {
                $this->fail('payload', 'unknown_key');
                break;
            }
        }
        $F = $this->content['fields'];
        $today = Birthdate::today($this->now);
        $d = [];

        $d['formVersion'] = $this->str($in, 'formVersion', 1, 64);
        if ($d['formVersion'] !== null && $d['formVersion'] !== (string) ($this->content['_meta']['formVersion'] ?? '')) {
            $this->fail('formVersion', 'stale');
        }
        $d['dataVersion'] = $this->str($in, 'dataVersion', 1, 64);
        if ($d['dataVersion'] !== null && $d['dataVersion'] !== $this->formData->version()) {
            $this->fail('dataVersion', 'stale');
        }

        $d['firstName'] = $this->str($in, 'firstName', 1, (int) $F['firstName']['maxLength']);
        $d['lastName'] = $this->str($in, 'lastName', 1, (int) $F['lastName']['maxLength']);
        $d['membershipNumber'] = $this->match($in, 'membershipNumber', '/^\d{10}$/', 10);
        $d['street'] = $this->str($in, 'street', 1, (int) $F['street']['maxLength']);
        $d['city'] = $this->str($in, 'city', 1, (int) $F['city']['maxLength']);
        $d['state'] = $this->enum($in, 'state', $this->formData->states(), 64);
        $d['zip'] = $this->match($in, 'zip', '/^\d{5}(-\d{4})?$/', 10);
        $d['phone'] = $this->match($in, 'phone', '/^[2-9]\d{2}[2-9]\d{6}$/', 10);
        $d['email'] = $this->str($in, 'email', 3, 254);
        if ($d['email'] !== null && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('email', 'invalid');
        }

        $d['dateOfBirth'] = $this->str($in, 'dateOfBirth', 0, 10);
        if ($d['dateOfBirth'] !== null && $d['dateOfBirth'] !== ''
            && Birthdate::check($d['dateOfBirth'], $today, $this->limits->dobAgeMin(), $this->limits->dobAgeMax()) !== null) {
            $this->fail('dateOfBirth', 'invalid');
        }

        $notListed = $this->formData->notListed();
        $d['local'] = $this->enum($in, 'local', $this->formData->localNames());
        $d['localOther'] = $this->other($in, 'localOther', $d['local'] === $notListed, self::OTHER_MAX);
        if ($d['local'] !== null) {
            $d['worksite'] = $this->enum($in, 'worksite', $this->formData->worksites($d['local']) ?? []);
        } else {
            $d['worksite'] = $this->str($in, 'worksite', 1, 200);   // reported via "local"
        }
        $d['worksiteOther'] = $this->other($in, 'worksiteOther', $d['worksite'] === $notListed, self::OTHER_MAX);

        $d['jobTitle'] = $this->str($in, 'jobTitle', 1, (int) $F['jobTitle']['maxLength']);
        $d['timeInPosition'] = $this->str($in, 'timeInPosition', 1, (int) $F['timeInPosition']['maxLength']);

        $d['issueType'] = $this->enum($in, 'issueType', array_values($this->content['issueTypes']));
        $d['issueTypeOther'] = $this->other($in, 'issueTypeOther', $d['issueType'] === $F['issueType']['otherValue'], (int) $F['issueType']['otherMaxLength']);

        $d['incidentDate'] = $this->str($in, 'incidentDate', 1, 10);
        if ($d['incidentDate'] !== null && (Birthdate::parse($d['incidentDate']) === null || strcmp($d['incidentDate'], $today) > 0)) {
            $this->fail('incidentDate', 'invalid');
        }

        $d['narrative'] = $this->str($in, 'narrative', 1, $this->limits->narrativeMaxChars(), true);
        if ($d['narrative'] !== null && self::wordCount($d['narrative']) > $this->limits->narrativeMaxWords()) {
            $this->fail('narrative', 'too_many_words');
        }
        $d['requestedOutcome'] = $this->str($in, 'requestedOutcome', 1, $this->limits->requestedOutcomeMaxChars());

        if (!array_key_exists('website', $in) || !is_string($in['website'])) {
            $this->fail('website', 'invalid');
        }

        return ['errors' => $this->errors, 'data' => $this->errors === [] ? $d : []];
    }

    /** Text that is required when $required, and must be empty otherwise. */
    private function other(array $in, string $key, bool $required, int $max): ?string
    {
        if ($required) {
            return $this->str($in, $key, 1, $max);
        }
        $v = $this->str($in, $key, 0, $max);
        if ($v !== null && $v !== '') {
            $this->fail($key, 'not_applicable');

            return null;
        }

        return $v;
    }
}
