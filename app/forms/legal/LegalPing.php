<?php

declare(strict_types=1);

namespace VeaIntake\Forms\Legal;

use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Http;
use VeaIntake\Core\HttpError;
use VeaIntake\Core\Log;
use VeaIntake\Core\TmpfsGuard;

/**
 * GET /legal/ping.php (docs/PAYLOAD.md section 1): tmpfs guard, GET only, Access
 * JWT → 200 {"email": "...", "name": null}. The front end calls it at page load
 * ("Signed in as <email>") and right before every submission.
 */
final class LegalPing
{
    public function __construct(
        private readonly TmpfsGuard $guard,
        private readonly \Closure $auth,   // fn(): AccessAuth, built only after the guard and method checks
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(array $server): array
    {
        try {
            $this->guard->assertReady();
            Http::requireGet($server);
            /** @var AccessAuth $auth */
            $auth = ($this->auth)();
            $who = $auth->verify($server);

            return [200, ['email' => $who->email, 'name' => $who->name]];
        } catch (HttpError $e) {
            return [$e->status, $e->body()];
        } catch (\Throwable $e) {
            Log::event('server_error', ['form' => 'legal', 'stage' => 'ping', 'class' => (new \ReflectionClass($e))->getShortName(), 'status' => 500]);

            return [500, (new HttpError(500, HttpError::SERVER_ERROR))->body()];
        }
    }
}
