<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessAuth;
use VeaIntake\Core\HttpError;
use VeaIntake\Core\HttpResponse;
use VeaIntake\Core\Log;
use VeaIntake\Tests\Support\FakeClock;
use VeaIntake\Tests\Support\MockHttpClient;
use VeaIntake\Tests\Support\TempTree;

/** Cloudflare Access JWT verification with locally generated RSA keys and a mocked JWKS endpoint. */
final class AccessAuthTest extends TestCase
{
    private const TEAM = 'https://vea-test.cloudflareaccess.com';
    private const AUD = 'aud-legal-0123456789abcdef';
    private const CERTS = self::TEAM . '/cdn-cgi/access/certs';

    /** @var array<string, array{private: string, jwk: array<string, string>}> */
    private static array $keys = [];
    private TempTree $t;
    private FakeClock $clock;
    /** @var list<string> */
    private array $log = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['k1', 'k2', 'evil'] as $kid) {
            $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($res, $pem);
            $d = openssl_pkey_get_details($res)['rsa'];
            self::$keys[$kid] = ['private' => $pem, 'jwk' => [
                'kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
                'n' => JWT::urlsafeB64Encode($d['n']), 'e' => JWT::urlsafeB64Encode($d['e']),
            ]];
        }
    }

    protected function setUp(): void
    {
        $this->t = new TempTree();
        $this->clock = new FakeClock(1_800_000_000.0);
        $this->log = [];
        Log::setWriter(function (string $l): void { $this->log[] = $l; });
    }

    protected function tearDown(): void
    {
        Log::setWriter(null);
        $this->t->remove();
    }

    private static function jwks(string ...$kids): HttpResponse
    {
        $keys = array_map(static fn (string $k): array => self::$keys[$k]['jwk'], $kids);

        // Cloudflare also returns public_cert(s); only "keys" is used.
        return new HttpResponse(200, json_encode(['keys' => $keys, 'public_cert' => ['kid' => 'x', 'cert' => '...']]));
    }

    private function auth(MockHttpClient $http): AccessAuth
    {
        return new AccessAuth($http, self::TEAM, self::AUD, $this->t->root, $this->clock->clock());
    }

    /** @param array<string, mixed> $over */
    private function token(array $over = [], string $kid = 'k1', ?string $signWith = null): string
    {
        $now = (int) $this->clock->now;
        $claims = array_filter($over + [
            'aud' => [self::AUD], 'email' => 'JSmith@VEAnea.org', 'exp' => $now + 3600, 'iat' => $now - 10,
            'nbf' => $now - 10, 'iss' => self::TEAM, 'type' => 'app', 'identity_nonce' => 'n', 'sub' => 'uuid-1', 'country' => 'US',
        ], static fn ($v) => $v !== null);

        return JWT::encode($claims, self::$keys[$signWith ?? $kid]['private'], 'RS256', $kid);
    }

    private function denied(AccessAuth $auth, string $token, string $reason, int $status = 401): void
    {
        try {
            $auth->verify([AccessAuth::HEADER => $token]);
            self::fail("accepted ($reason)");
        } catch (HttpError $e) {
            self::assertSame($status, $e->status, $reason);
            self::assertSame($status === 401 ? 'unauthorized' : 'unavailable', $e->errorCode);
        }
        self::assertStringContainsString($status === 401 ? "event=auth_failed status=401 reason=$reason" : 'event=jwks_unavailable', (string) end($this->log));
    }

    public function testValidTokenGivesLowercasedEmailAndNoName(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1', 'k2')]]);
        $who = $this->auth($http)->verify([AccessAuth::HEADER => $this->token()]);
        self::assertSame('jsmith@veanea.org', $who->email);
        self::assertNull($who->name);
        self::assertSame('uuid-1', $who->sub);
        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame([], $this->log);
    }

    public function testAudienceMayBeAStringAndIssMayHaveATrailingSlash(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1')]]);
        $who = $this->auth($http)->verify([AccessAuth::HEADER => $this->token(['aud' => self::AUD, 'iss' => self::TEAM . '/'])]);
        self::assertSame('jsmith@veanea.org', $who->email);
    }

    public function testClaimFailures(): void
    {
        $now = (int) $this->clock->now;
        $auth = $this->auth(new MockHttpClient([[self::CERTS, self::jwks('k1')]]));
        $this->denied($auth, $this->token(['aud' => ['another-app']]), 'bad_aud');
        $this->denied($auth, $this->token(['iss' => 'https://other.cloudflareaccess.com']), 'bad_iss');
        $this->denied($auth, $this->token(['exp' => $now - 61]), 'expired');
        $this->denied($auth, $this->token(['nbf' => $now + 61]), 'not_yet_valid');
        $this->denied($auth, $this->token(['nbf' => null, 'iat' => $now + 61]), 'not_yet_valid');
        $this->denied($auth, $this->token(['exp' => null]), 'no_exp');
        $this->denied($auth, $this->token(['type' => 'org']), 'bad_type');
        $this->denied($auth, $this->token(['email' => null, 'common_name' => 'service-token.access']), 'no_email');
        $this->denied($auth, $this->token(['email' => 'not-an-email']), 'no_email');
    }

    public function testClockLeewayIsSixtySeconds(): void
    {
        $now = (int) $this->clock->now;
        $auth = $this->auth(new MockHttpClient([[self::CERTS, self::jwks('k1')]]));
        self::assertSame('jsmith@veanea.org', $auth->verify([AccessAuth::HEADER => $this->token(['exp' => $now - 59])])->email);
        self::assertSame('jsmith@veanea.org', $auth->verify([AccessAuth::HEADER => $this->token(['nbf' => $now + 59])])->email);
    }

    public function testSignatureAndAlgorithmFailures(): void
    {
        $auth = $this->auth(new MockHttpClient([[self::CERTS, self::jwks('k1')]]));
        $this->denied($auth, $this->token([], 'k1', 'evil'), 'bad_signature');

        // HS256 signed with the RSA public key as the HMAC secret (classic confusion attack)
        $pub = openssl_pkey_get_details(openssl_pkey_get_private(self::$keys['k1']['private']))['key'];
        $hs = JWT::encode(['aud' => [self::AUD], 'email' => 'a@veanea.org', 'iss' => self::TEAM, 'exp' => (int) $this->clock->now + 60], $pub, 'HS256', 'k1');
        $this->denied($auth, $hs, 'bad_alg');

        // alg "none", no signature
        $b64 = static fn (array $a): string => JWT::urlsafeB64Encode(json_encode($a));
        $none = $b64(['alg' => 'none', 'kid' => 'k1']) . '.' . $b64(['aud' => [self::AUD], 'email' => 'a@veanea.org']) . '.';
        $this->denied($auth, $none, 'malformed');
        $this->denied($auth, $b64(['alg' => 'none', 'kid' => 'k1']) . '.' . $b64(['email' => 'a@veanea.org']) . '.x', 'bad_alg');

        // RS256 header without a kid
        $this->denied($auth, $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64(['email' => 'a']) . '.sig', 'no_kid');
    }

    public function testMissingOrMalformedToken(): void
    {
        $auth = $this->auth(new MockHttpClient());
        $this->denied($auth, '', 'missing');
        $this->denied($auth, 'abc', 'malformed');
        $this->denied($auth, str_repeat('a', 9000) . '.b.c', 'malformed');
        try {
            $auth->verify([]);
            self::fail('accepted');
        } catch (HttpError $e) {
            self::assertSame(401, $e->status);
        }
    }

    public function testUnknownKidRefetchesOnceThenAccepts(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1')], [self::CERTS, self::jwks('k1', 'k2')]]);
        $auth = $this->auth($http);
        self::assertSame('jsmith@veanea.org', $auth->verify([AccessAuth::HEADER => $this->token()])->email);
        $this->clock->now += 120;   // past the refetch throttle
        self::assertSame('jsmith@veanea.org', $auth->verify([AccessAuth::HEADER => $this->token([], 'k2')])->email, 'rotated key picked up');
        self::assertCount(2, $http->requests);
    }

    public function testUnknownKidRefetchIsThrottled(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1')], [self::CERTS, self::jwks('k1')], [self::CERTS, self::jwks('k1')]]);
        $auth = $this->auth($http);
        $auth->verify([AccessAuth::HEADER => $this->token()]);
        $this->clock->now += 120;
        $this->denied($auth, $this->token([], 'k2'), 'unknown_kid');   // one refetch
        $this->denied($auth, $this->token([], 'k2'), 'unknown_kid');   // throttled: no fetch
        $this->denied($auth, $this->token([], 'k2'), 'unknown_kid');
        self::assertCount(2, $http->requests);
    }

    public function testJwksCachedForAnHourThenRefetched(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1')], [self::CERTS, self::jwks('k1')]]);
        $auth = $this->auth($http);
        $auth->verify([AccessAuth::HEADER => $this->token()]);
        $this->clock->now += 3599;
        $auth->verify([AccessAuth::HEADER => $this->token()]);
        self::assertCount(1, $http->requests);
        $this->clock->now += 2;
        $auth->verify([AccessAuth::HEADER => $this->token()]);
        self::assertCount(2, $http->requests);
    }

    public function testCacheFileHoldsPublicKeyFieldsOnly(): void
    {
        $this->auth(new MockHttpClient([[self::CERTS, self::jwks('k1')]]))->verify([AccessAuth::HEADER => $this->token()]);
        $cache = json_decode($this->t->read('access-jwks.json'), true);
        self::assertSame(['fetched', 'attempted', 'keys'], array_keys($cache));
        self::assertSame(['kid', 'kty', 'alg', 'use', 'n', 'e'], array_keys($cache['keys'][0]));
        self::assertSame([], glob($this->t->root . '/.jwks-*'), 'no temp files left');
    }

    public function testJwksDownWithoutCacheIs503(): void
    {
        $auth = $this->auth(new MockHttpClient([[self::CERTS, new HttpResponse(0, '')]]));
        $this->denied($auth, $this->token(), 'jwks', 503);
        self::assertContains('vea-intake event=jwks_fetch_failed', $this->log);
    }

    public function testJwksDownWithExpiredCacheIs503ButFreshCacheStillWorks(): void
    {
        $http = new MockHttpClient([[self::CERTS, self::jwks('k1')], [self::CERTS, new HttpResponse(503, '')], [self::CERTS, new HttpResponse(503, '')]]);
        $auth = $this->auth($http);
        $auth->verify([AccessAuth::HEADER => $this->token()]);
        $this->clock->now += 1800;
        self::assertSame('jsmith@veanea.org', $auth->verify([AccessAuth::HEADER => $this->token()])->email);
        $this->clock->now += 1900;   // cache expired
        $this->denied($auth, $this->token(), 'jwks', 503);
        $this->denied($auth, $this->token(), 'jwks', 503);   // throttled: no second fetch within 60 s
        self::assertCount(2, $http->requests);
    }

    public function testJwksWithoutUsableRsaKeysIsUnavailable(): void
    {
        $bad = new HttpResponse(200, json_encode(['keys' => [['kid' => 'k1', 'kty' => 'EC', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b'], ['kid' => 'h', 'kty' => 'oct', 'k' => 'c2VjcmV0']]]));
        $this->denied($this->auth(new MockHttpClient([[self::CERTS, $bad]])), $this->token(), 'jwks', 503);
    }

    public function testHandle(): void
    {
        self::assertSame('jsmith', AccessAuth::handle('JSmith@veanea.org'));
        self::assertSame('choward', AccessAuth::handle('choward1@veanea.org', ['choward1' => 'choward']));
        self::assertSame('a.b-c_dtag', AccessAuth::handle('a.b-c_d+tag@veanea.org'), 'reduced to [a-z0-9._-]');
        self::assertSame('unknown', AccessAuth::handle('@veanea.org'));
    }
}
