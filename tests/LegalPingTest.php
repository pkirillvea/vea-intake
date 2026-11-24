<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Core\Log;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Forms\Legal\LegalPing;
use VeaIntake\Tests\Support\MockHttpClient;
use VeaIntake\Tests\Support\TempTree;

/** GET /legal/ping.php: guard first, GET only, JWT → {email, name: null}. */
final class LegalPingTest extends TestCase
{
    private TempTree $t;
    private string $pem = '';
    private string $jwks;

    protected function setUp(): void
    {
        $this->t = new TempTree();
        mkdir($this->t->root . '/tmpfs', 0700);
        Log::setWriter(static function (): void {});
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $this->pem);
        $d = openssl_pkey_get_details($res)['rsa'];
        $this->jwks = json_encode(['keys' => [['kid' => 'k', 'kty' => 'RSA', 'alg' => 'RS256', 'n' => JWT::urlsafeB64Encode($d['n']), 'e' => JWT::urlsafeB64Encode($d['e'])]]]);
    }

    protected function tearDown(): void
    {
        Log::setWriter(null);
        $this->t->remove();
    }

    private function ping(array $server, string $fs = 'tmpfs', ?int &$authBuilt = null): array
    {
        $dir = $this->t->root . '/tmpfs';
        $this->t->write('mountinfo', "1 0 0:1 / $dir rw - $fs tmpfs rw\n");
        $authBuilt = 0;
        $ping = new LegalPing(new TmpfsGuard($dir, $this->t->root . '/mountinfo'), function () use (&$authBuilt): AccessAuth {
            $authBuilt++;

            return new AccessAuth(new MockHttpClient([['https://t.cloudflareaccess.com/', new HttpResponse(200, $this->jwks)]]), 'https://t.cloudflareaccess.com', 'aud1', $this->t->root);
        });

        return $ping->handle($server);
    }

    private function token(): string
    {
        return JWT::encode(['aud' => ['aud1'], 'iss' => 'https://t.cloudflareaccess.com', 'email' => 'Staff@VEAnea.org', 'exp' => time() + 600, 'type' => 'app'], $this->pem, 'RS256', 'k');
    }

    public function testOkReturnsEmailAndNullName(): void
    {
        self::assertSame([200, ['email' => 'staff@veanea.org', 'name' => null]], $this->ping(['REQUEST_METHOD' => 'GET', AccessAuth::HEADER => $this->token()]));
    }

    public function testNoTokenIs401(): void
    {
        self::assertSame([401, ['ok' => false, 'error' => 'unauthorized']], $this->ping(['REQUEST_METHOD' => 'GET']));
    }

    public function testNotGetIs405(): void
    {
        self::assertSame(405, $this->ping(['REQUEST_METHOD' => 'POST', AccessAuth::HEADER => $this->token()])[0]);
    }

    public function testGuardComesFirst(): void
    {
        $r = $this->ping(['REQUEST_METHOD' => 'POST'], 'ext4', $built);
        self::assertSame([503, ['ok' => false, 'error' => 'unavailable']], $r);
        self::assertSame(0, $built, 'no config or JWT work without the tmpfs');
    }

    public function testConfigFailureIs500(): void
    {
        $dir = $this->t->root . '/tmpfs';
        $this->t->write('mountinfo', "1 0 0:1 / $dir rw - tmpfs tmpfs rw\n");
        $ping = new LegalPing(new TmpfsGuard($dir, $this->t->root . '/mountinfo'), static fn () => throw new \RuntimeException('app.env is not readable'));
        self::assertSame([500, ['ok' => false, 'error' => 'server_error']], $ping->handle(['REQUEST_METHOD' => 'GET']));
    }
}
