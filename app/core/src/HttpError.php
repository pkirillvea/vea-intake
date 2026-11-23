<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use RuntimeException;

/**
 * A client-facing error: HTTP status + one of the generic error codes.
 * Copied from vea-join; adds `unauthorized` (401, Access JWT) and `unavailable`
 * (503, tmpfs not mounted).
 */
final class HttpError extends RuntimeException
{
    public const INVALID_INPUT = 'invalid_input';
    public const VERIFICATION_FAILED = 'verification_failed';
    public const UNAUTHORIZED = 'unauthorized';
    public const UNAVAILABLE = 'unavailable';
    public const SEND_FAILED = 'send_failed';
    public const SERVER_ERROR = 'server_error';

    /** @param list<string> $fields field names only (never values) */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $fields = [],
    ) {
        parent::__construct($errorCode, $status);
    }

    /** @return array{ok: false, error: string, fields?: list<string>} */
    public function body(): array
    {
        $body = ['ok' => false, 'error' => $this->errorCode];
        if ($this->errorCode === self::INVALID_INPUT) {
            $body['fields'] = $this->fields;
        }

        return $body;
    }
}
