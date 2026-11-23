<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/** Copied from vea-join as-is (namespace only). */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $data = json_decode($this->body, true, 32);

        return is_array($data) ? $data : null;
    }
}
