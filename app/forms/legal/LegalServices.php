<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Config;
use VeaIntake\Core\FormData;
use VeaIntake\Core\GraphMailer;

/** What LegalSubmitHandler needs once the tmpfs guard has passed (real ones in handler.php, mocks in tests). */
final class LegalServices
{
    public function __construct(
        public readonly Config $config,
        public readonly AccessAuth $auth,
        public readonly GraphMailer $mailer,
        public readonly FormData $formData,
    ) {
    }
}
