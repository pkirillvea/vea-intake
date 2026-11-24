<?php
/**
 * Entry for POST /legal/submit.php (public/legal/submit.php requires this file).
 * Wires the real dependencies; the logic and its tests are in LegalSubmitHandler.
 * Config, JWT and Graph are only set up after the tmpfs guard has passed.
 */
declare(strict_types=1);

use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Config;
use VeaIntake\Core\CurlHttpClient;
use VeaIntake\Core\Deadline;
use VeaIntake\Core\FormData;
use VeaIntake\Core\GraphMailer;
use VeaIntake\Core\Http;
use VeaIntake\Core\PdfBuilder;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Forms\Legal\LegalServices;
use VeaIntake\Forms\Legal\LegalSubmitHandler;

require __DIR__ . '/../../../vendor/autoload.php';

$handler = new LegalSubmitHandler(
    new TmpfsGuard(),
    static function (Deadline $deadline): LegalServices {
        $config = Config::load();
        $http = new CurlHttpClient();

        return new LegalServices(
            $config,
            new AccessAuth($http, $config->accessTeamDomain(), $config->accessAud('legal')),
            GraphMailer::fromConfig($http, $config, $deadline),
            FormData::load(),
        );
    },
    new PdfBuilder('legal'),
);
[$status, $body] = $handler->handle($_SERVER, $_POST, $_FILES);
Http::send($status, $body);
