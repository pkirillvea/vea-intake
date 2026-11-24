<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\Deadline;
use VeaIntake\Core\GraphMailer;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Tests\Support\FakeClock;
use VeaIntake\Tests\Support\MockHttpClient;
use VeaIntake\Tests\Support\TempTree;

/** Graph sendMail (small path) and draft + upload sessions + send + permanentDelete (large path). */
final class GraphMailerTest extends TestCase
{
    private const LOGIN = 'https://login.microsoftonline.com/';
    private const B = 'https://graph.microsoft.com/v1.0/users/no.reply%40example.org';
    private const ID = 'AAMkAD/x+y=';
    private const M = self::B . '/messages/AAMkAD%2Fx%2By%3D';
    private const UPLOAD = 'https://outlook.office.com/api/v2.0/Users(\'x\')/Messages(\'y\')/AttachmentSessions(\'z\')?authtoken=abc';

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1000.0);
    }

    private function mailer(MockHttpClient $http, bool $test = false, float $budget = 80.0): GraphMailer
    {
        return new GraphMailer($http, 'tenant', 'client', 'secret-xyz', 'no.reply@example.org', 'reply@example.org', $test, 'test@example.org',
            new Deadline($this->clock->now + $budget, $this->clock->clock()), $this->clock->sleeper());
    }

    private static function token(): array
    {
        return [self::LOGIN, new HttpResponse(200, '{"access_token":"tok-123","token_type":"Bearer"}')];
    }

    private static function r(int $status, array|string $body = '', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, is_array($body) ? json_encode($body) : $body, $headers);
    }

    /** @return list<array{0: string, 1: HttpResponse, 2?: string}> */
    private static function cleanupOk(): array
    {
        return [
            [self::B . '/mailFolders/sentitems', self::r(200, ['id' => 'SENT'])],
            [self::M, self::r(200, ['id' => self::ID, 'isDraft' => false, 'parentFolderId' => 'OUTBOX']), 'GET'],
            [self::M, self::r(200, ['id' => self::ID, 'isDraft' => false, 'parentFolderId' => 'SENT']), 'GET'],
            [self::M . '/permanentDelete', self::r(204)],
        ];
    }

    // ------------------------------------------------------------ small path
    public function testInlineSendWithSeveralAttachments(): void
    {
        $http = MockHttpClient::graphOk(1);
        $r = $this->mailer($http)->send('legal@example.org', 'Subject', '<p>Hi</p>', [
            ['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => '%PDF-1'],
            ['name' => '01_b.txt', 'contentType' => 'text/plain', 'bytes' => 'hello'],
        ]);
        self::assertSame(['ok' => true, 'stage' => 'send', 'status' => 202, 'path' => 'inline', 'cleanup' => null], $r);
        self::assertStringContainsString('client_secret=secret-xyz', (string) $http->requests[0]['body']);
        $msg = $http->jsonBodies('https://graph.microsoft.com/')[0];
        self::assertFalse($msg['saveToSentItems']);
        self::assertSame('legal@example.org', $msg['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertSame(['a.pdf', '01_b.txt'], array_column($msg['message']['attachments'], 'name'));
        self::assertSame(base64_encode('hello'), $msg['message']['attachments'][1]['contentBytes']);
        self::assertSame(self::B . '/sendMail', $http->requests[1]['url']);
    }

    public function testInlineReadsFilesFromDisk(): void
    {
        $t = new TempTree();
        try {
            $t->write('up/x.csv', "a,b\n");
            $http = MockHttpClient::graphOk(1);
            $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => '01_x.csv', 'contentType' => 'text/csv', 'path' => $t->root . '/up/x.csv']]);
            self::assertSame(base64_encode("a,b\n"), $http->jsonBodies(self::B)[0]['message']['attachments'][0]['contentBytes']);
        } finally {
            $t->remove();
        }
    }

    public function testInlineThresholdIsTwoPointSevenFiveMebibytes(): void
    {
        $http = MockHttpClient::graphOk(1);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', GraphMailer::MAX_INLINE)]]);
        self::assertSame('inline', $r['path']);
        self::assertSame(2_883_584, GraphMailer::MAX_INLINE);
        self::assertLessThan(4_000_000, strlen((string) $http->requests[1]['body']), 'base64 + JSON stays under ~4 MB');

        $http = new MockHttpClient([self::token(), [self::B . '/messages', self::r(500)]]);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', GraphMailer::MAX_INLINE + 1)]]);
        self::assertSame(['ok' => false, 'stage' => 'draft', 'status' => 500, 'path' => 'large', 'cleanup' => null], $r);
    }

    public function testTestModeRedirectsAndPrefixes(): void
    {
        $http = MockHttpClient::graphOk(1);
        $this->mailer($http, true)->send('legal@example.org', 'Subject', '<p>Hi</p>');
        $msg = $http->jsonBodies('https://graph.microsoft.com/')[0]['message'];
        self::assertSame('[TEST] Subject', $msg['subject']);
        self::assertSame('test@example.org', $msg['toRecipients'][0]['emailAddress']['address']);
    }

    public function testTokenFailure(): void
    {
        $http = new MockHttpClient([[self::LOGIN, self::r(401, '{"error":"invalid_client"}')]]);
        self::assertSame(['ok' => false, 'stage' => 'token', 'status' => 401, 'path' => 'inline', 'cleanup' => null], $this->mailer($http)->send('a@example.org', 'S', 'x'));
        self::assertCount(1, $http->requests, 'no retry on 401, no send without a token');
    }

    public function testThrottlingHonoursRetryAfter(): void
    {
        $http = new MockHttpClient([
            [self::LOGIN, self::r(503, '', ['retry-after' => '4'])],
            self::token(),
            [self::B . '/sendMail', self::r(429, '', ['retry-after' => '7'])],
            [self::B . '/sendMail', self::r(429, '', ['retry-after' => '120'])],
            [self::B . '/sendMail', self::r(202)],
        ]);
        $r = $this->mailer($http)->send('a@example.org', 'S', 'x');
        self::assertTrue($r['ok']);
        self::assertSame([4.0, 7.0, 10.0], $this->clock->sleeps, 'Retry-After honoured, capped at 10 s');
    }

    public function testRetriesAreBoundedAndNeverPassTheDeadline(): void
    {
        $http = new MockHttpClient([self::token(), [self::B . '/sendMail', self::r(429)], [self::B . '/sendMail', self::r(429)], [self::B . '/sendMail', self::r(429)], [self::B . '/sendMail', self::r(202)]]);
        $r = $this->mailer($http)->send('a@example.org', 'S', 'x');
        self::assertSame(['ok' => false, 'stage' => 'send', 'status' => 429, 'path' => 'inline', 'cleanup' => null], $r);
        self::assertSame([1.0, 2.0], $this->clock->sleeps, 'two retries with backoff when no Retry-After');

        $this->clock->sleeps = [];
        $http = new MockHttpClient([self::token(), [self::B . '/sendMail', self::r(429, '', ['retry-after' => '9'])], [self::B . '/sendMail', self::r(202)]]);
        $r = $this->mailer($http, false, 10.0)->send('a@example.org', 'S', 'x');
        self::assertFalse($r['ok'], 'waiting 9 s would leave under 2 s: give up');
        self::assertSame([], $this->clock->sleeps);

        $http = new MockHttpClient([self::token()]);
        $m = $this->mailer($http, false, 20.0);
        $this->clock->now += 25;
        self::assertSame('token', $m->send('a@example.org', 'S', 'x')['stage'], 'no call at all once the deadline has passed');
        self::assertCount(0, $http->requests);
    }

    public function testTimeoutsAreBoundedByTheDeadline(): void
    {
        $http = MockHttpClient::graphOk(1);
        $this->mailer($http, false, 12.4)->send('a@example.org', 'S', 'x');
        self::assertSame([12, 12], array_column($http->requests, 'timeout'));
    }

    // ------------------------------------------------------------ large path
    public function testLargePathDraftDirectAndSessionUploadsSendAndDeleteSentCopy(): void
    {
        $t = new TempTree();
        try {
            $big = 5_000_000;
            $t->write('up/big.pdf', str_repeat('B', $big));
            $http = new MockHttpClient([
                self::token(),
                [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'],
                [self::M . '/attachments', self::r(201), 'POST'],                                     // the PDF, 1 MB: direct
                [self::M . '/attachments/createUploadSession', self::r(201, ['uploadUrl' => self::UPLOAD, 'nextExpectedRanges' => ['0-']])],
                ['https://outlook.office.com/', self::r(200, ['nextExpectedRanges' => ['3932160-']])],
                ['https://outlook.office.com/', self::r(503, '', ['retry-after' => '2'])],
                ['https://outlook.office.com/', self::r(201)],
                [self::M . '/send', self::r(202)],
                ...self::cleanupOk(),
            ]);
            $r = $this->mailer($http)->send('legal@example.org', 'Subject', '<p>Hi</p>', [
                ['name' => 'Legal-Request.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('P', 1_000_000)],
                ['name' => '01_big.pdf', 'contentType' => 'application/pdf', 'path' => $t->root . '/up/big.pdf'],
            ]);
            self::assertSame(['ok' => true, 'stage' => 'send', 'status' => 202, 'path' => 'large', 'cleanup' => true], $r);
            self::assertSame(0, $http->remaining());

            $draft = $http->requestsTo(self::B . '/messages', 'POST')[0];
            self::assertSame('IdType="ImmutableId"', $draft['headers']['Prefer']);
            self::assertArrayNotHasKey('saveToSentItems', json_decode((string) $draft['body'], true));
            $session = json_decode((string) $http->requestsTo(self::M . '/attachments/createUploadSession')[0]['body'], true);
            self::assertSame(['attachmentType' => 'file', 'name' => '01_big.pdf', 'size' => $big, 'contentType' => 'application/pdf'], $session['AttachmentItem']);

            $puts = $http->requestsTo('https://outlook.office.com/', 'PUT');
            self::assertSame(['bytes 0-3932159/5000000', 'bytes 3932160-4999999/5000000', 'bytes 3932160-4999999/5000000'], array_map(static fn ($p) => $p['headers']['Content-Range'], $puts));
            foreach ($puts as $p) {
                self::assertArrayNotHasKey('Authorization', $p['headers'], 'the upload URL never gets our token');
            }
            self::assertSame(GraphMailer::CHUNK, strlen((string) $puts[0]['body']));
            self::assertSame([2.0, 1.0], $this->clock->sleeps, 'chunk retry (Retry-After 2), then one Sent Items poll');
            self::assertCount(1, $http->requestsTo(self::M . '/permanentDelete'));
        } finally {
            $t->remove();
        }
    }

    public function testLargePathTestMode(): void
    {
        $http = new MockHttpClient([self::token(), [self::B . '/messages', self::r(500), 'POST']]);
        $this->mailer($http, true)->send('legal@example.org', 'Subject', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 3_500_000)]]);
        $draft = json_decode((string) $http->requestsTo(self::B . '/messages')[0]['body'], true);
        self::assertSame('[TEST] Subject', $draft['subject']);
        self::assertSame('test@example.org', $draft['toRecipients'][0]['emailAddress']['address']);
    }

    public function testForeignUploadUrlIsRefusedAndTheDraftDeleted(): void
    {
        foreach (['https://evil.example.com/upload', 'http://outlook.office.com/x', 'https://outlook.office.com.evil.net/x', 'https://u:p@outlook.office.com/x'] as $url) {
            $http = new MockHttpClient([
                self::token(),
                [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'],
                [self::M . '/attachments/createUploadSession', self::r(201, ['uploadUrl' => $url])],
                [self::M . '/permanentDelete', self::r(204)],
            ]);
            $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 3_000_000)]]);
            self::assertSame(['ok' => false, 'stage' => 'upload_session', 'status' => 201, 'path' => 'large', 'cleanup' => true], $r, $url);
            self::assertCount(0, $http->requestsTo('https://evil.example.com/'));
            self::assertCount(0, $http->requestsTo('http://'));
        }
    }

    public function testDraftIsDeletedWhenAnAttachmentFailsAndCleanupFailureIsReported(): void
    {
        $http = new MockHttpClient([
            self::token(),
            [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'],
            [self::M . '/attachments', self::r(400), 'POST'],
            [self::M . '/permanentDelete', self::r(500)],
        ]);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 2_000_000)], ['name' => 'b.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('y', 2_000_000)]]);
        self::assertSame(['ok' => false, 'stage' => 'attach', 'status' => 400, 'path' => 'large', 'cleanup' => false], $r);
        self::assertCount(0, $http->requestsTo(self::M . '/send'), 'never sent');
        self::assertCount(0, $http->requestsTo(self::M, 'DELETE'), 'never a plain DELETE (Deleted Items)');
    }

    public function testSentCopyCleanupFailureStillCountsAsSent(): void
    {
        $rules = [self::token(), [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'], [self::M . '/attachments', self::r(201), 'POST'],
            [self::M . '/send', self::r(202)], [self::B . '/mailFolders/sentitems', self::r(200, ['id' => 'SENT'])]];
        for ($i = 0; $i < 20; $i++) {
            $rules[] = [self::M, self::r(200, ['id' => self::ID, 'parentFolderId' => 'OUTBOX']), 'GET'];
        }
        $http = new MockHttpClient($rules);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 2_900_000)]]);
        self::assertSame(['ok' => true, 'stage' => 'send', 'status' => 202, 'path' => 'large', 'cleanup' => false], $r);
        self::assertCount(GraphMailer::SENT_POLLS, $http->requestsTo(self::M, 'GET'), 'polling is bounded');
        self::assertCount(0, $http->requestsTo(self::M . '/permanentDelete'), 'never deleted before it reached Sent Items');
    }

    public function testUnclearSendIsCheckedNotResent(): void
    {
        $base = [self::token(), [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'], [self::M . '/attachments', self::r(201), 'POST'], [self::M . '/send', self::r(0)]];
        $att = [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 2_900_000)]];

        // it went out: no longer a draft → success, then the usual cleanup
        $http = new MockHttpClient([...$base, [self::M, self::r(200, ['id' => self::ID, 'isDraft' => false]), 'GET'], ...self::cleanupOk()]);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', $att);
        self::assertTrue($r['ok']);
        self::assertTrue($r['cleanup']);
        self::assertCount(1, $http->requestsTo(self::M . '/send'));

        // still a draft → not sent: permanently delete the draft, report failure
        $http = new MockHttpClient([...$base, [self::M, self::r(200, ['id' => self::ID, 'isDraft' => true]), 'GET'], [self::M . '/permanentDelete', self::r(204)]]);
        self::assertSame(['ok' => false, 'stage' => 'send', 'status' => 0, 'path' => 'large', 'cleanup' => true], $this->mailer($http)->send('l@example.org', 'S', 'x', $att));
        self::assertCount(1, $http->requestsTo(self::M . '/send'), 'never re-sent');
    }

    public function testThrottledSendAfterRetriesDeletesTheDraft(): void
    {
        $http = new MockHttpClient([self::token(), [self::B . '/messages', self::r(201, ['id' => self::ID]), 'POST'], [self::M . '/attachments', self::r(201), 'POST'],
            [self::M . '/send', self::r(429)], [self::M . '/send', self::r(429)], [self::M . '/send', self::r(429)], [self::M . '/permanentDelete', self::r(204)]]);
        $r = $this->mailer($http)->send('l@example.org', 'S', 'x', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'bytes' => str_repeat('x', 2_900_000)]]);
        self::assertSame(['ok' => false, 'stage' => 'send', 'status' => 429, 'path' => 'large', 'cleanup' => true], $r);
    }

    public function testCredentialsAreRedacted(): void
    {
        self::assertStringNotContainsString('secret-xyz', print_r($this->mailer(new MockHttpClient()), true));
    }
}
