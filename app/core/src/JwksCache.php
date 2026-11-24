<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * The Cloudflare Access signing keys (JWKS) of the team, cached on disk
 * (docs/PLAN.md section 4). Public keys only.
 *
 * - Fresh for TTL seconds (1 h). Stored atomically (temp file + rename) as
 *   {"fetched": <unix>, "attempted": <unix>, "keys": [RSA keys: kid, kty, alg, use, n, e]}.
 * - refresh = true (an unknown kid) refetches, but at most once per MIN_REFETCH
 *   seconds across all requests, so made-up kids cannot cause a fetch storm.
 * - A failed fetch never extends an expired cache: keys() returns null and the
 *   caller fails closed. After a failure the next attempt waits MIN_REFETCH.
 */
final class JwksCache
{
    public const TTL = 3600;
    public const MIN_REFETCH = 60;
    public const TIMEOUT = 5;
    private const KEY_FIELDS = ['kid', 'kty', 'alg', 'use', 'n', 'e'];

    private readonly \Closure $clock;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly string $file,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @return list<array<string, string>>|null RSA public keys, or null when none can be had */
    public function keys(bool $refresh = false): ?array
    {
        $now = (int) ($this->clock)();
        $cache = $this->read();
        $fresh = $cache !== null && $now - $cache['fetched'] < self::TTL && $now >= $cache['fetched'] - 60;
        if ($fresh && !$refresh) {
            return $cache['keys'];
        }
        if ($cache !== null && $now - $cache['attempted'] < self::MIN_REFETCH && $now >= $cache['attempted']) {
            return $fresh ? $cache['keys'] : null;   // throttled
        }

        $keys = $this->fetch();
        if ($keys === null) {
            Log::event('jwks_fetch_failed');
            $this->write(['fetched' => $cache['fetched'] ?? 0, 'attempted' => $now, 'keys' => $cache['keys'] ?? []]);

            return $fresh ? $cache['keys'] : null;
        }
        $this->write(['fetched' => $now, 'attempted' => $now, 'keys' => $keys]);

        return $keys;
    }

    /** @return list<array<string, string>>|null */
    private function fetch(): ?array
    {
        $res = $this->http->request('GET', $this->url, ['Accept' => 'application/json'], null, self::TIMEOUT);
        $data = $res->status === 200 ? $res->json() : null;

        return self::rsaKeys($data['keys'] ?? null);
    }

    /** Keep only well-formed RS256 RSA signing keys, and only their public fields. @return list<array<string, string>>|null */
    private static function rsaKeys(mixed $keys): ?array
    {
        if (!is_array($keys)) {
            return null;
        }
        $out = [];
        foreach ($keys as $k) {
            if (!is_array($k) || ($k['kty'] ?? null) !== 'RSA' || ($k['alg'] ?? 'RS256') !== 'RS256' || ($k['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            if (!is_string($k['kid'] ?? null) || $k['kid'] === '' || !is_string($k['n'] ?? null) || !is_string($k['e'] ?? null)) {
                continue;
            }
            $out[] = ['kid' => $k['kid'], 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $k['n'], 'e' => $k['e']];
        }

        return $out === [] ? null : $out;
    }

    /** @return array{fetched: int, attempted: int, keys: list<array<string, string>>}|null */
    private function read(): ?array
    {
        $raw = @file_get_contents($this->file);
        $data = $raw === false ? null : json_decode($raw, true, 8);
        if (!is_array($data) || !is_int($data['fetched'] ?? null) || !is_int($data['attempted'] ?? null) || !is_array($data['keys'] ?? null)) {
            return null;
        }
        $keys = $data['keys'] === [] ? [] : self::rsaKeys($data['keys']);

        return ['fetched' => $data['fetched'], 'attempted' => $data['attempted'], 'keys' => $keys ?? []];
    }

    private function write(array $data): void
    {
        $dir = dirname($this->file);
        $tmp = @tempnam($dir, '.jwks-');
        if ($tmp === false || !str_starts_with($tmp, $dir . '/')) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            Log::event('jwks_cache_write_failed');

            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            Log::event('jwks_cache_write_failed');
        }
    }
}
