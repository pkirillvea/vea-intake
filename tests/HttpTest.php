<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Http;
use VeaIntake\Core\HttpError;

final class HttpTest extends TestCase
{
    private const SERVER = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '1000'];

    private static function statusOf(callable $f): int
    {
        try {
            $f();
        } catch (HttpError $e) {
            return $e->status;
        }

        return 0;
    }

    public function testReadsPayloadAndNormalizesFiles(): void
    {
        $files = ['files' => [
            'name' => ['a.pdf', ''],
            'type' => ['application/pdf', ''],
            'tmp_name' => ['/run/vea-intake/phpA', ''],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
            'size' => [123, 0],
        ]];
        $r = Http::readMultipart(self::SERVER, ['payload' => '{"firstName":"Ann"}'], $files);
        self::assertSame(['firstName' => 'Ann'], $r['payload']);
        self::assertSame([['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/run/vea-intake/phpA', 'error' => 0, 'size' => 123]], $r['files']);
    }

    public function testNoFilesIsAnEmptyList(): void
    {
        self::assertSame([], Http::readMultipart(self::SERVER, ['payload' => '{}'], [])['files']);
    }

    public function testRejections(): void
    {
        $ok = ['payload' => '{"a":1}'];
        self::assertSame(405, self::statusOf(fn () => Http::readMultipart(['REQUEST_METHOD' => 'GET'] + self::SERVER, $ok, [])));
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(['CONTENT_TYPE' => 'application/json'] + self::SERVER, $ok, [])));
        self::assertSame(413, self::statusOf(fn () => Http::readMultipart(['CONTENT_LENGTH' => (string) (Http::MAX_BODY + 1)] + self::SERVER, $ok, [])));
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, [], [])), 'missing payload');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, ['payload' => '[1,2]'], [])), 'list payload');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, ['payload' => '{bad'], [])), 'invalid JSON');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, ['payload' => str_repeat(' ', Http::MAX_PAYLOAD) . '{}'], [])), 'payload too big');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, $ok + ['extra' => 'x'], [])), 'unknown form field');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, $ok, ['other' => []])), 'unknown file field');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, $ok, ['files' => ['name' => 'single']])), 'not files[]');
        self::assertSame(400, self::statusOf(fn () => Http::readMultipart(self::SERVER, ['payload' => '{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}'], [])), 'too deep');
    }

    public function testRequireGet(): void
    {
        Http::requireGet(['REQUEST_METHOD' => 'GET']);
        self::assertSame(405, self::statusOf(fn () => Http::requireGet(['REQUEST_METHOD' => 'POST'])));
    }

    public function testErrorBodyListsFieldNamesOnly(): void
    {
        self::assertSame(['ok' => false, 'error' => 'invalid_input', 'fields' => ['zip']], (new HttpError(422, HttpError::INVALID_INPUT, ['zip']))->body());
        self::assertSame(['ok' => false, 'error' => 'unavailable'], (new HttpError(503, HttpError::UNAVAILABLE))->body());
        self::assertSame(['ok' => false, 'error' => 'unauthorized'], (new HttpError(401, HttpError::UNAUTHORIZED))->body());
    }
}
