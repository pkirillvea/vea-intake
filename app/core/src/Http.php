<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Request parsing and JSON responses for /<form>/submit.php and /<form>/ping.php.
 * Copied from vea-join's Http and generalized.
 *
 * Differences from vea-join: submissions carry files, so the request is
 * multipart/form-data with one JSON field `payload` (decoded and checked exactly
 * like vea-join's JSON body) plus the uploaded files, which are returned as-is
 * for UploadValidator (Prompt 4). Body limit 27 MB, matching nginx and php.ini.
 */
final class Http
{
    public const MAX_BODY = 27 * 1024 * 1024;   // nginx client_max_body_size 27m, PHP post_max_size 27M
    public const MAX_PAYLOAD = 65_536;          // the JSON field alone
    public const PAYLOAD_FIELD = 'payload';
    public const FILES_FIELD = 'files';

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $post   $_POST
     * @param array<string, mixed> $files  $_FILES
     * @return array{payload: array<string, mixed>, files: list<array{name: string, type: string, tmp_name: string, error: int, size: int}>}
     */
    public static function readMultipart(array $server, array $post, array $files): array
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new HttpError(405, HttpError::INVALID_INPUT);
        }
        $type = strtolower(trim(explode(';', (string) ($server['CONTENT_TYPE'] ?? ''))[0]));
        if ($type !== 'multipart/form-data') {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }
        $length = $server['CONTENT_LENGTH'] ?? null;
        if ($length !== null && $length !== '' && (!ctype_digit((string) $length) || (int) $length > self::MAX_BODY)) {
            throw new HttpError(413, HttpError::INVALID_INPUT);
        }

        $raw = $post[self::PAYLOAD_FIELD] ?? null;
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_PAYLOAD) {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }
        try {
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }
        if (array_diff(array_keys($post), [self::PAYLOAD_FIELD]) !== [] || array_diff(array_keys($files), [self::FILES_FIELD]) !== []) {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }

        return ['payload' => $data, 'files' => self::normalizeFiles($files[self::FILES_FIELD] ?? null)];
    }

    /** GET only (ping.php). @param array<string, mixed> $server */
    public static function requireGet(array $server): void
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'GET') {
            throw new HttpError(405, HttpError::INVALID_INPUT);
        }
    }

    /** @param array<string, mixed> $body */
    public static function send(int $status, array $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');   // nginx also adds it for /legal/ (and nosniff)
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** 204 No Content (ping.php). */
    public static function sendNoContent(): void
    {
        if (!headers_sent()) {
            http_response_code(204);
            header('Cache-Control: no-store');
        }
    }

    /**
     * PHP's $_FILES['files'] for `files[]` is column-wise; turn it into a list of rows.
     * No file field at all is an empty list. Anything malformed is a 400.
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    private static function normalizeFiles(mixed $f): array
    {
        if ($f === null) {
            return [];
        }
        if (!is_array($f) || !isset($f['name'], $f['type'], $f['tmp_name'], $f['error'], $f['size'])
            || !is_array($f['name']) || !array_is_list($f['name'])) {
            throw new HttpError(400, HttpError::INVALID_INPUT);
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                if (!array_key_exists($i, $f[$k]) || is_array($f[$k][$i])) {
                    throw new HttpError(400, HttpError::INVALID_INPUT);
                }
            }
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;   // an empty file input
            }
            $out[] = [
                'name' => (string) $f['name'][$i],
                'type' => (string) $f['type'][$i],
                'tmp_name' => (string) $f['tmp_name'][$i],
                'error' => (int) $f['error'][$i],
                'size' => (int) $f['size'][$i],
            ];
        }

        return $out;
    }
}
