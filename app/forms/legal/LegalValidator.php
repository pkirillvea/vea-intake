<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use VeaIntake\Core\FormData;
use VeaIntake\Core\Validator;

/**
 * Authoritative validation of the Legal Assistance Request payload
 * (docs/PLAN.md sections 4 and 10). Implemented in Prompt 4.
 */
final class LegalValidator extends Validator
{
    public function __construct(
        private readonly FormData $formData,
        private readonly string $formVersion,
        private readonly int $narrativeMaxWords,
    ) {
    }

    public function validate(array $in): array
    {
        throw new \LogicException('Prompt 4');
    }
}
