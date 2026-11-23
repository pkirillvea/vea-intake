<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Minimal outbound HTTP (Microsoft Graph, the Access JWKS). Mocked in tests.
 * vea-join's interface had post() only; Graph's large-attachment path and the
 * JWKS need GET, PUT and DELETE too, so there is one request() method.
 */
interface HttpClient
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, string> $headers
     * @return HttpResponse status 0 when the request could not be completed
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
