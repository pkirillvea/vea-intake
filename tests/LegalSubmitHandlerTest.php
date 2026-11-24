<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\Config;
use VeaIntake\Core\Deadline;
use VeaIntake\Core\GraphMailer;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Core\Log;
use VeaIntake\Core\PdfBuilder;
use VeaIntake\Core\SubmissionId;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Forms\Legal\LegalServices;
use VeaIntake\Forms\Legal\LegalSubmitHandler;
use VeaIntake\Tests\Support\FakeClock;
use VeaIntake\Tests\Support\LegalFixtures as Fx;
use VeaIntake\Tests\Support\MockHttpClient;
use VeaIntake\Tests\Support\TempTree;
use VeaIntake\Tests\Support\UploadFixtures;

/** The whole submit flow with every outbound call mocked (PLAN section 2 order, PAYLOAD.md responses). */
final class LegalSubmitHandlerTest extends TestCase
{
    private const TEAM = 'https://example-team.cloudflareaccess.com';
    private const CERTS = self::TEAM . '/cdn-cgi/access/certs';
    private const LOGIN = 'https://login.microsoftonline.com/';
    private const SEND = 'https://graph.microsoft.com/v1.0/users/no.reply%40example.org/sendMail';
    private const B = 'https://graph.microsoft.com/v1.0/users/no.reply%40example.org';

    private static string $pem = '';
    private static string $jwks = '';
    private TempTree $t;
    private UploadFixtures $fx;
    private FakeClock $clock;
    /** @var list<string> */
    private array $log = [];

    public static function setUpBeforeClass(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, self::$pem);
        $d = openssl_pkey_get_details($res)['rsa'];
        self::$jwks = json_encode(['keys' => [['kid' => 'k1', 'kty' => 'RSA', 'alg' => 'RS256', 'n' => JWT::urlsafeB64Encode($d['n']), 'e' => JWT::urlsafeB64Encode($d['e'])]]]);
    }

    protected function setUp(): void
    {
        $this->t = new TempTree();
        mkdir($this->t->root . '/tmpfs', 0700);
        mkdir($this->t->root . '/cache', 0700);
        $this->fx = new UploadFixtures($this->t);
        $this->clock = new FakeClock((float) time());
        $this->log = [];
        Log::setWriter(function (string $l): void { $this->log[] = $l; });
    }

    protected function tearDown(): void
    {
        Log::setWriter(null);
        $this->t->remove();
    }

    private function token(): string
    {
        return JWT::encode(['aud' => ['aud-tag-123'], 'iss' => self::TEAM, 'email' => 'JSmith1@veanea.org', 'exp' => time() + 600, 'type' => 'app', 'sub' => 'u'], self::$pem, 'RS256', 'k1');
    }

    /**
     * @param list<array{0: string, 1: HttpResponse, 2?: string}> $graph
     * @return array{0: int, 1: array<string, mixed>, 2: MockHttpClient}
     */
    private function submit(array $payload, array $files = [], array $graph = [], array $config = [], array $server = [], string $fs = 'tmpfs'): array
    {
        $dir = $this->t->root . '/tmpfs';
        $this->t->write('mountinfo', "1 0 0:1 / $dir rw - $fs tmpfs rw\n");
        $http = new MockHttpClient([[self::CERTS, new HttpResponse(200, self::$jwks)], ...$graph]);
        $cache = $this->t->root . '/cache';
        $clock = $this->clock;
        $handler = new LegalSubmitHandler(
            new TmpfsGuard($dir, $this->t->root . '/mountinfo'),
            static function (Deadline $deadline) use ($http, $config, $cache, $clock): LegalServices {
                $cfg = Config::fromArray(ConfigTest::valid($config));

                return new LegalServices($cfg, new AccessAuth($http, $cfg->accessTeamDomain(), $cfg->accessAud('legal'), $cache),
                    GraphMailer::fromConfig($http, $cfg, $deadline, $clock->sleeper()), Fx::formData());
            },
            new PdfBuilder('legal', $dir),
            static fn () => Fx::now(),
            $clock->clock(),
            static fn (): bool => true,
        );
        $server += ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_USER_AGENT' => 'phpunit', 'REQUEST_TIME_FLOAT' => $this->clock->now, AccessAuth::HEADER => $this->token()];
        $filesIn = $files === [] ? [] : ['files' => [
            'name' => array_column($files, 'name'), 'type' => array_column($files, 'type'), 'tmp_name' => array_column($files, 'tmp_name'),
            'error' => array_column($files, 'error'), 'size' => array_column($files, 'size'),
        ]];

        return [...$handler->handle($server, ['payload' => json_encode($payload)], $filesIn), $http];
    }

    private static function graphOk(int $sends = 2): array
    {
        $r = [[self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')]];
        for ($i = 0; $i < $sends; $i++) {
            $r[] = [self::SEND, new HttpResponse(202, '')];
        }

        return $r;
    }

    public function testSuccessSmallPath(): void
    {
        $files = [$this->fx->row($this->fx->pdf('Member Letter.pdf'))];
        [$status, $body, $http] = $this->submit(Fx::payload(), $files, self::graphOk());
        self::assertSame(200, $status, json_encode($body));
        self::assertTrue($body['ok']);
        self::assertTrue(SubmissionId::isValid($body['id']));

        [$staff, $confirm] = $http->jsonBodies(self::SEND);
        self::assertSame("Legal Assistance Request - [#{$body['id']}] - jsmith1", $staff['message']['subject']);
        self::assertSame('legal@example.org', $staff['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertFalse($staff['saveToSentItems']);
        self::assertSame(["Legal-Request_{$body['id']}_McTestface_Testy.pdf", '01_Member_Letter.pdf'], array_column($staff['message']['attachments'], 'name'));
        self::assertStringStartsWith('%PDF-', base64_decode($staff['message']['attachments'][0]['contentBytes']));
        $html = $staff['message']['body']['content'];
        self::assertStringContainsString('PRIVILEGED &amp; CONFIDENTIAL', $html);
        self::assertStringContainsString('Testy McTestface', $html);
        self::assertStringContainsString('jsmith1@veanea.org', $html);
        self::assertStringContainsString('plus 1 supporting file(s)', $html);
        self::assertStringNotContainsString('Dismissal', $html, 'no issue type in the body');
        self::assertStringNotContainsString('fake narrative', $html, 'no narrative in the body');
        self::assertStringNotContainsString('TEST MODE', $html);

        self::assertSame("Legal Assistance Request received - [#{$body['id']}]", $confirm['message']['subject']);
        self::assertSame('jsmith1@veanea.org', $confirm['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertArrayNotHasKey('attachments', $confirm['message']);
        self::assertStringContainsString("for Testy McTestface", $confirm['message']['body']['content']);
        self::assertStringNotContainsString('Dismissal', $confirm['message']['body']['content']);
        self::assertSame(['staff_sent', 'confirm_sent', 'submit_ok'], $this->events());
        self::assertSame([], glob($this->t->root . '/tmpfs/*'), 'no PDF temp files left');
    }

    public function testHandleOverrideAndTestMode(): void
    {
        $tok = JWT::encode(['aud' => ['aud-tag-123'], 'iss' => self::TEAM, 'email' => 'choward1@veanea.org', 'exp' => time() + 600], self::$pem, 'RS256', 'k1');
        [$status, $body, $http] = $this->submit(Fx::payload(), [], self::graphOk(), ['TEST_MODE' => '', 'TEST_MAIL_TO' => 'paul.test@example.org'], [AccessAuth::HEADER => $tok]);
        self::assertSame(200, $status);
        [$staff, $confirm] = $http->jsonBodies(self::SEND);
        self::assertSame("[TEST] Legal Assistance Request - [#{$body['id']}] - choward", $staff['message']['subject'], 'blank TEST_MODE = test mode; override map');
        self::assertSame('paul.test@example.org', $staff['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertStringContainsString('would have gone to legal@example.org', $staff['message']['body']['content']);
        self::assertSame('[TEST] Legal Assistance Request received - [#' . $body['id'] . ']', $confirm['message']['subject']);
        self::assertSame('paul.test@example.org', $confirm['message']['toRecipients'][0]['emailAddress']['address']);
        self::assertStringContainsString('would have gone to choward1@veanea.org', $confirm['message']['body']['content']);
    }

    public function testSuccessLargePath(): void
    {
        $files = [$this->fx->row($this->fx->bigPdf('Big Scan.pdf', 3_500_000))];
        $graph = [
            [self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')],
            [self::B . '/messages', new HttpResponse(201, '{"id":"MSG1"}'), 'POST'],
            [self::B . '/messages/MSG1/attachments/createUploadSession', new HttpResponse(201, '{"uploadUrl":"https://outlook.office.com/up?x=1"}')],
            [self::B . '/messages/MSG1/attachments', new HttpResponse(201, ''), 'POST'],   // the PDF (small: direct)
            ['https://outlook.office.com/', new HttpResponse(201, '')],
            [self::B . '/messages/MSG1/send', new HttpResponse(202, '')],
            [self::B . '/mailFolders/sentitems', new HttpResponse(200, '{"id":"SENT"}')],
            [self::B . '/messages/MSG1', new HttpResponse(200, '{"parentFolderId":"SENT"}'), 'GET'],
            [self::B . '/messages/MSG1/permanentDelete', new HttpResponse(204, '')],
            [self::SEND, new HttpResponse(202, '')],
        ];
        [$status, , $http] = $this->submit(Fx::payload(), $files, $graph);
        self::assertSame(200, $status);
        self::assertSame(0, $http->remaining(), 'every expected Graph call made');
        self::assertMatchesRegularExpression('/^vea-intake event=staff_sent id=\d{8}-\d{4}-\w{4} status=202 stage=large$/', $this->log[0]);
        self::assertNotContains('sent_copy_cleanup_failed', $this->events());
    }

    public function testHoneypotFakesSuccessAndSendsNothing(): void
    {
        [$status, $body, $http] = $this->submit(Fx::payload(['website' => 'http://spam.example']), [], self::graphOk());
        self::assertSame(200, $status);
        self::assertTrue($body['ok']);
        self::assertTrue(SubmissionId::isValid($body['id']));
        self::assertCount(0, $http->requestsTo(self::LOGIN));
        self::assertSame(['honeypot'], $this->events());
    }

    public function testFieldErrorsAre400WithNamesOnly(): void
    {
        [$status, $body, $http] = $this->submit(Fx::payload(['zip' => '1', 'email' => 'nope', 'extra' => 'x']), [], self::graphOk());
        self::assertSame([400, ['ok' => false, 'error' => 'invalid_input', 'fields' => ['payload', 'zip', 'email']]], [$status, $body]);
        self::assertCount(0, $http->requestsTo(self::LOGIN));
        self::assertSame(['vea-intake event=invalid_input form=legal status=400 fields=payload,zip,email'], $this->log);
    }

    public function testRejectedFileIs400Files(): void
    {
        $files = [$this->fx->row($this->fx->pdf('ok.pdf')), $this->fx->row($this->fx->exe('Secret Name.pdf'))];
        [$status, $body, $http] = $this->submit(Fx::payload(), $files, self::graphOk());
        self::assertSame([400, ['ok' => false, 'error' => 'invalid_input', 'fields' => ['files'], 'files' => [['index' => 1, 'reason' => 'mime_mismatch']]]], [$status, $body]);
        self::assertStringNotContainsString('Secret', (string) json_encode($body));
        self::assertCount(0, $http->requestsTo(self::LOGIN), 'nothing sent');
    }

    public function testUnauthorizedAndUnavailable(): void
    {
        [$status, $body, $http] = $this->submit(Fx::payload(), [], self::graphOk(), [], [AccessAuth::HEADER => '']);
        self::assertSame([401, ['ok' => false, 'error' => 'unauthorized']], [$status, $body]);
        self::assertCount(0, $http->requestsTo(self::LOGIN));

        [$status, $body, $http] = $this->submit(Fx::payload(), [], self::graphOk(), [], [], 'ext4');
        self::assertSame([503, ['ok' => false, 'error' => 'unavailable']], [$status, $body]);
        self::assertCount(0, $http->requests, 'guard first: no JWKS, no Graph');
    }

    public function testJwksDownIs503(): void
    {
        $dir = $this->t->root . '/tmpfs';
        $this->t->write('mountinfo', "1 0 0:1 / $dir rw - tmpfs tmpfs rw\n");
        $http = new MockHttpClient([[self::CERTS, new HttpResponse(502, '')]]);
        $cache = $this->t->root . '/cache';
        $h = new LegalSubmitHandler(new TmpfsGuard($dir, $this->t->root . '/mountinfo'), static function (Deadline $d) use ($http, $cache): LegalServices {
            $cfg = Config::fromArray(ConfigTest::valid());

            return new LegalServices($cfg, new AccessAuth($http, $cfg->accessTeamDomain(), $cfg->accessAud('legal'), $cache), GraphMailer::fromConfig($http, $cfg, $d), Fx::formData());
        }, new PdfBuilder('legal', $dir));
        self::assertSame([503, ['ok' => false, 'error' => 'unavailable']], $h->handle([AccessAuth::HEADER => $this->token(), 'REQUEST_METHOD' => 'POST'], [], []));
    }

    public function testNotPostIs405(): void
    {
        [$status] = $this->submit(Fx::payload(), [], [], [], ['REQUEST_METHOD' => 'GET']);
        self::assertSame(405, $status);
    }

    public function testStaffFailureIs502AndNothingElseIsSent(): void
    {
        $graph = [[self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')], [self::SEND, new HttpResponse(400, '{"error":{"message":"Testy McTestface"}}')], [self::SEND, new HttpResponse(202, '')]];
        [$status, $body, $http] = $this->submit(Fx::payload(), [], $graph);
        self::assertSame([502, ['ok' => false, 'error' => 'send_failed']], [$status, $body]);
        self::assertCount(1, $http->requestsTo(self::SEND), 'no confirmation');
        self::assertSame(['staff_send_failed'], $this->events());
        self::assertMatchesRegularExpression('/^vea-intake event=staff_send_failed id=\d{8}-\d{4}-\w{4} status=400 stage=send$/', $this->log[0]);
    }

    public function testDraftCleanupFailureIsLogged(): void
    {
        $graph = [[self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')], [self::B . '/messages', new HttpResponse(201, '{"id":"MSG1"}'), 'POST'],
            [self::B . '/messages/MSG1/attachments', new HttpResponse(500, ''), 'POST'], [self::B . '/messages/MSG1/attachments', new HttpResponse(500, ''), 'POST'],
            [self::B . '/messages/MSG1/attachments', new HttpResponse(500, ''), 'POST'], [self::B . '/messages/MSG1/permanentDelete', new HttpResponse(500, '')]];
        [$status] = $this->submit(Fx::payload(), [$this->fx->row($this->fx->bigPdf('a.pdf', 2_900_000))], $graph);
        self::assertSame(502, $status);
        self::assertSame(['staff_send_failed', 'draft_cleanup_failed'], $this->events());
    }

    public function testConfirmationFailureIsStillSuccess(): void
    {
        $graph = [[self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')], [self::SEND, new HttpResponse(202, '')], [self::SEND, new HttpResponse(403, '')]];
        [$status, $body] = $this->submit(Fx::payload(), [], $graph);
        self::assertSame(200, $status);
        self::assertTrue($body['ok']);
        self::assertSame(['staff_sent', 'confirm_send_failed', 'submit_ok'], $this->events());
    }

    public function testConfirmationSkippedWhenTheDeadlineIsNear(): void
    {
        [$status, , $http] = $this->submit(Fx::payload(), [], self::graphOk(), [], ['REQUEST_TIME_FLOAT' => $this->clock->now - 75]);
        self::assertSame(200, $status);
        self::assertCount(1, $http->requestsTo(self::SEND));
        self::assertSame(['staff_sent', 'confirm_skipped', 'submit_ok'], $this->events());
    }

    public function testNoPersonalDataInAnyLogLine(): void
    {
        $nl = Fx::formData()->notListed();
        $payload = Fx::payload(['local' => $nl, 'localOther' => 'Zebrafinch Local', 'worksite' => $nl, 'worksiteOther' => 'Quokka Annex',
            'issueType' => 'Other', 'issueTypeOther' => 'Marmoset matter', 'narrative' => 'Platypus narrative words', 'dateOfBirth' => '1980-01-02']);
        $file = [$this->fx->row($this->fx->pdf('Wombat Evidence.pdf'))];
        $this->submit($payload, $file, self::graphOk());                                           // success
        $this->submit($payload, $file, [[self::LOGIN, new HttpResponse(200, '{"access_token":"tok"}')], [self::SEND, new HttpResponse(500, 'Testy McTestface')]]);   // 502
        $this->submit(Fx::payload(['zip' => 'Narwhal']), [], []);                                    // 400
        $this->submit($payload, [$this->fx->row($this->fx->exe('Axolotl.pdf'))], []);               // 400 files
        $this->submit(Fx::payload(['website' => 'Capybara spam']), [], []);                          // honeypot
        self::assertNotEmpty($this->log);
        $all = implode("\n", $this->log);
        foreach (['Testy', 'McTestface', '0001234567', '8045551234', '804', 'testy.member', 'example.org', 'veanea', 'jsmith', 'Fake Street',
            'Richmond', '23219', 'Zebrafinch', 'Quokka', 'Marmoset', 'Platypus', 'Wombat', 'Axolotl', 'Narwhal', 'Capybara', '1980', 'Teacher',
            '198.51.100.7', 'phpunit', 'tok'] as $needle) {
            self::assertStringNotContainsString($needle, $all, "log contains '$needle'");
        }
        foreach ($this->log as $line) {
            self::assertMatchesRegularExpression('/^vea-intake event=[a-z_]+( (form|id|status|stage|reason|class|fields)=[A-Za-z0-9_.:,-]+)*$/', $line);
        }
    }

    /** @return list<string> */
    private function events(): array
    {
        return array_map(static fn (string $l): string => (string) preg_replace('/^vea-intake event=(\S+).*$/', '$1', $l), $this->log);
    }
}
