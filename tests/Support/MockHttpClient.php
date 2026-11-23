<?php

declare(strict_types=1);

namespace VeaIntake\Tests\Support;

use VeaIntake\Core\HttpClient;
use VeaIntake\Core\HttpResponse;

/**
 * Records requests and answers from a queue of [urlPrefix => HttpResponse] rules.
 * Copied from vea-join's MockHttpClient; request() replaces post().
 */
final class MockHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    public array $requests = [];

    /** @param list<array{0: string, 1: HttpResponse}> $responses matched in order by URL prefix */
    public function __construct(private array $responses = [])
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        foreach ($this->responses as $i => [$prefix, $response]) {
            if (str_starts_with($url, $prefix)) {
                unset($this->responses[$i]);
                $this->responses = array_values($this->responses);

                return $response;
            }
        }

        return new HttpResponse(599, '');
    }

    /** @return list<array<string, mixed>> decoded JSON bodies of requests to $prefix */
    public function jsonBodies(string $prefix): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if (str_starts_with($r['url'], $prefix)) {
                $out[] = json_decode((string) $r['body'], true);
            }
        }

        return $out;
    }

    public static function graphOk(int $sends = 2): self
    {
        $rules = [['https://login.microsoftonline.com/', new HttpResponse(200, '{"access_token":"tok-123","token_type":"Bearer"}')]];
        for ($i = 0; $i < $sends; $i++) {
            $rules[] = ['https://graph.microsoft.com/', new HttpResponse(202, '')];
        }

        return new self($rules);
    }
}
