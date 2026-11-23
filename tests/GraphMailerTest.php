<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\GraphMailer;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Tests\Support\MockHttpClient;

final class GraphMailerTest extends TestCase
{
    private static function mailer(MockHttpClient $http, bool $test = false): GraphMailer
    {
        return new GraphMailer($http, 'tenant', 'client', 'secret-xyz', 'no.reply@example.org', 'reply@example.org', $test, 'test@example.org');
    }

    public function testInlineSendWithSeveralAttachments(): void
    {
        $http = MockHttpClient::graphOk(1);
        $r = self::mailer($http)->send('legal@example.org', 'Subject', '<p>Hi</p>', [
            ['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => '%PDF-1'],
            ['name' => '01_b.txt', 'contentType' => 'text/plain', 'bytes' => 'hello'],
        ]);
        self::assertSame(['ok' => true, 'stage' => 'send', 'status' => 202], $r);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertStringContainsString('client_secret=secret-xyz', (string) $http->requests[0]['body']);
        $msg = $http->jsonBodies('https://graph.microsoft.com/')[0];
        self::assertFalse($msg['saveToSentItems']);
        self::assertSame('legal@example.org', $msg['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertSame(['a.pdf', '01_b.txt'], array_column($msg['message']['attachments'], 'name'));
        self::assertSame(base64_encode('hello'), $msg['message']['attachments'][1]['contentBytes']);
        self::assertSame('https://graph.microsoft.com/v1.0/users/no.reply%40example.org/sendMail', $http->requests[1]['url']);
    }

    public function testTestModeRedirectsAndPrefixes(): void
    {
        $http = MockHttpClient::graphOk(1);
        self::mailer($http, true)->send('legal@example.org', 'Subject', '<p>Hi</p>');
        $msg = $http->jsonBodies('https://graph.microsoft.com/')[0]['message'];
        self::assertSame('[TEST] Subject', $msg['subject']);
        self::assertSame('test@example.org', $msg['toRecipients'][0]['emailAddress']['address']);
    }

    public function testTokenFailure(): void
    {
        $http = new MockHttpClient([['https://login.microsoftonline.com/', new HttpResponse(401, '{"error":"invalid_client"}')]]);
        self::assertSame(['ok' => false, 'stage' => 'token', 'status' => 401], self::mailer($http)->send('a@example.org', 'S', 'x'));
        self::assertCount(1, $http->requests, 'no send without a token');
    }

    public function testOverThreeMegabytesTakesTheLargePath(): void
    {
        $http = new MockHttpClient();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Prompt 4');
        self::mailer($http)->send('a@example.org', 'S', 'x', [['name' => 'big.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', GraphMailer::MAX_INLINE + 1)]]);
    }

    public function testCredentialsAreRedacted(): void
    {
        self::assertStringNotContainsString('secret-xyz', print_r(self::mailer(new MockHttpClient()), true));
    }
}
