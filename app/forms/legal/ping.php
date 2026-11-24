<?php
/**
 * Entry for GET /legal/ping.php (public/legal/ping.php requires this file).
 * Wires the real dependencies; the logic and its tests are in LegalPing.
 */
declare(strict_types=1);

use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Config;
use VeaIntake\Core\CurlHttpClient;
use VeaIntake\Core\Http;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Forms\Legal\LegalPing;

require __DIR__ . '/../../../vendor/autoload.php';

$ping = new LegalPing(new TmpfsGuard(), static function (): AccessAuth {
    $config = Config::load();

    return new AccessAuth(new CurlHttpClient(), $config->accessTeamDomain(), $config->accessAud('legal'));
});
[$status, $body] = $ping->handle($_SERVER);
Http::send($status, $body);
