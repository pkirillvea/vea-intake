<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Verifies the Cloudflare Access JWT on every PHP request (docs/PLAN.md section 4):
 * header Cf-Access-Jwt-Assertion, RS256 signature against the team's JWKS
 * (https://<team>.cloudflareaccess.com/cdn-cgi/access/certs, cached <= 1 h in
 * /var/cache/vea-intake), aud = the form's Access app AUD tag, iss = team domain,
 * exp/nbf. Uses firebase/php-jwt. Implemented in Prompt 4.
 */
final class AccessAuth
{
    public const HEADER = 'HTTP_CF_ACCESS_JWT_ASSERTION';
    public const JWKS_PATH = '/cdn-cgi/access/certs';
    public const JWKS_TTL = 3600;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $teamDomain,
        private readonly string $aud,
        private readonly string $cacheDir = '/var/cache/vea-intake',
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @throws HttpError 401 (unauthorized) if the JWT is missing or invalid
     */
    public function verify(array $server): AccessIdentity
    {
        throw new \LogicException('Prompt 4');
    }

    /** Submitter handle for the email subject (first initial + last name, e.g. "jsmith"; PLAN section 14). */
    public static function handle(string $email, ?string $name): string
    {
        throw new \LogicException('Prompt 4');
    }
}
