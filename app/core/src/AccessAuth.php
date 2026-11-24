<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;

/**
 * Verifies the Cloudflare Access JWT on every PHP request (docs/PLAN.md section 4).
 *
 * - Header Cf-Access-Jwt-Assertion only (not the CF_Authorization cookie).
 * - RS256 only: every key from the team JWKS is pinned to RS256, so HS256/none/
 *   other algorithms fail. The token must name a kid; an unknown kid triggers one
 *   (throttled) JWKS refetch.
 * - aud must contain the form's Access app AUD tag; iss must equal the team
 *   domain; exp is required; exp/nbf/iat with 60 s leeway; type, when present,
 *   must be "app"; email is required.
 * - Any token failure: 401 unauthorized, logged as auth_failed with a reason code
 *   only. JWKS unavailable (no fresh keys and the fetch failed): 503 unavailable,
 *   logged as jwks_unavailable; signing in again would not help the user.
 */
final class AccessAuth
{
    public const HEADER = 'HTTP_CF_ACCESS_JWT_ASSERTION';
    public const JWKS_PATH = '/cdn-cgi/access/certs';
    public const CACHE_DIR = '/var/cache/vea-intake';
    public const LEEWAY = 60;
    private const MAX_TOKEN = 8192;

    private readonly JwksCache $jwks;
    private readonly \Closure $clock;

    public function __construct(
        HttpClient $http,
        private readonly string $teamDomain,
        private readonly string $aud,
        string $cacheDir = self::CACHE_DIR,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->jwks = new JwksCache($http, rtrim($teamDomain, '/') . self::JWKS_PATH, rtrim($cacheDir, '/') . '/access-jwks.json', $this->clock);
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @throws HttpError 401 unauthorized (token missing or invalid), 503 unavailable (no signing keys)
     */
    public function verify(array $server): AccessIdentity
    {
        $token = $server[self::HEADER] ?? null;
        if (!is_string($token) || $token === '') {
            $this->deny('missing');
        }
        if (strlen($token) > self::MAX_TOKEN || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            $this->deny('malformed');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 4);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            $this->deny('bad_alg');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            $this->deny('no_kid');
        }

        $keys = $this->keySet(false);
        if (!isset($keys[$kid])) {
            $keys = $this->keySet(true);
            if (!isset($keys[$kid])) {
                $this->deny('unknown_kid');
            }
        }

        [$oldLeeway, $oldTimestamp] = [JWT::$leeway, JWT::$timestamp];
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = (int) ($this->clock)();
        try {
            $claims = (array) JWT::decode($token, $keys);
        } catch (ExpiredException) {
            $this->deny('expired');
        } catch (BeforeValidException) {
            $this->deny('not_yet_valid');
        } catch (SignatureInvalidException) {
            $this->deny('bad_signature');
        } catch (\Throwable) {
            $this->deny('malformed');
        } finally {
            [JWT::$leeway, JWT::$timestamp] = [$oldLeeway, $oldTimestamp];
        }

        if (!isset($claims['exp'])) {
            $this->deny('no_exp');
        }
        if (!is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== rtrim($this->teamDomain, '/')) {
            $this->deny('bad_iss');
        }
        $aud = $claims['aud'] ?? null;
        $auds = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if ($this->aud === '' || !in_array($this->aud, $auds, true)) {
            $this->deny('bad_aud');
        }
        if (array_key_exists('type', $claims) && $claims['type'] !== 'app') {
            $this->deny('bad_type');
        }
        $email = $claims['email'] ?? null;
        if (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->deny('no_email');
        }
        $sub = $claims['sub'] ?? '';

        return new AccessIdentity(strtolower($email), null, is_string($sub) ? $sub : '');
    }

    /**
     * Submitter handle for the staff email subject (PLAN section 1): the local part
     * of the email, lowercased, then the form's override map (content.json
     * handles.overrides, e.g. choward1 → choward). Reduced to [a-z0-9._-].
     *
     * @param array<string, string> $overrides
     */
    public static function handle(string $email, array $overrides = []): string
    {
        $at = strrpos($email, '@');
        $local = strtolower($at === false ? $email : substr($email, 0, $at));
        $over = $overrides[$local] ?? null;
        $handle = is_string($over) && $over !== '' ? strtolower($over) : $local;
        $handle = substr((string) preg_replace('/[^a-z0-9._-]/', '', $handle), 0, 64);

        return $handle === '' ? 'unknown' : $handle;
    }

    /** @return array<string, \Firebase\JWT\Key> kid => key */
    private function keySet(bool $refresh): array
    {
        $jwks = $this->jwks->keys($refresh);
        if ($jwks === null) {
            Log::event('jwks_unavailable', ['status' => 503]);
            throw new HttpError(503, HttpError::UNAVAILABLE);
        }
        try {
            return JWK::parseKeySet(['keys' => $jwks], 'RS256');
        } catch (\Throwable) {
            Log::event('jwks_unavailable', ['status' => 503, 'reason' => 'parse']);
            throw new HttpError(503, HttpError::UNAVAILABLE);
        }
    }

    private function deny(string $reason): never
    {
        Log::event('auth_failed', ['status' => 401, 'reason' => $reason]);
        throw new HttpError(401, HttpError::UNAUTHORIZED);
    }
}
