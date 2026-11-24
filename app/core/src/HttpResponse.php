<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Copied from vea-join; adds the response headers (lower-cased names), which
 * Graph's Retry-After and upload sessions need.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased name => value (last one wins) */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $data = json_decode($this->body, true, 32);

        return is_array($data) ? $data : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
