<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Microsoft Graph mail with client credentials (docs/PLAN.md sections 1, 8, 12).
 * Copied from vea-join's GraphMailer and extended to several attachments.
 *
 * Small path (total attachments <= MAX_INLINE, 2.75 MiB raw; base64 + JSON stays
 * under Graph's ~4 MB request limit): POST /users/{from}/sendMail with
 * saveToSentItems: false. Nothing is stored in the mailbox.
 *
 * Large path (PLAN section 12), every call with Prefer: IdType="ImmutableId" so the
 * message ID survives the move to Sent Items:
 *   1 POST /users/{from}/messages                  draft
 *   2 per attachment: < DIRECT_MAX (3,000,000 B)   POST .../attachments (base64)
 *                     otherwise                    POST .../attachments/createUploadSession,
 *                                                  then PUT CHUNK-byte ranges to the upload URL
 *     The upload URL is pre-authenticated: it never gets our token, and it must be
 *     https on an Outlook host (UPLOAD_HOSTS).
 *   3 POST .../send
 *   4 wait until the message is in Sent Items (GET, once a second, <= SENT_POLLS),
 *     then POST .../permanentDelete. Deleting earlier could delete it before delivery.
 *   Any failure before (or an unconfirmed) send: permanentDelete the draft (a plain
 *   DELETE would move it, attachments and all, to Deleted Items).
 *
 * Time: every call's timeout is bounded by the request Deadline. 429/503/504 (and,
 * for GET and chunk PUTs, a network failure) are retried at most MAX_RETRIES times,
 * honouring Retry-After capped at MAX_WAIT seconds, only if the deadline allows.
 * A send with no clear answer (network failure or 5xx) is checked instead of being
 * re-sent: it counts as sent only if the message is no longer a draft.
 *
 * TEST_MODE: every recipient becomes TEST_MAIL_TO and the subject gets "[TEST] ".
 * Never logs or returns response bodies; callers log only event, ID, stage, status.
 */
final class GraphMailer
{
    public const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    public const GRAPH = 'https://graph.microsoft.com/v1.0';
    public const SEND_URL = self::GRAPH . '/users/%s/sendMail';
    public const SCOPE = 'https://graph.microsoft.com/.default';
    public const TIMEOUT = 15;            // JSON calls
    public const CHUNK_TIMEOUT = 30;      // one upload chunk
    public const MAX_INLINE = 2_883_584;  // 2.75 MiB of attachments for sendMail (decision B)
    public const DIRECT_MAX = 3_000_000;  // below: attach directly; from here: upload session (decision D)
    public const CHUNK = 3_932_160;       // 12 x 320 KiB, under the 4 MB per-request limit
    public const MAX_RETRIES = 2;
    public const MAX_WAIT = 10.0;
    public const SENT_POLLS = 10;
    public const UPLOAD_HOSTS = ['outlook.office.com', 'outlook.office365.com'];
    private const PREFER = ['Prefer' => 'IdType="ImmutableId"'];
    private const RETRY = [429, 503, 504];

    private ?string $token = null;
    private int $lastTokenStatus = 0;
    private readonly \Closure $credentials;
    private readonly Deadline $deadline;
    private readonly \Closure $sleep;

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter] string $tenantId,
        #[\SensitiveParameter] string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        private readonly string $mailFrom,
        private readonly string $replyTo,
        private readonly bool $testMode,
        private readonly string $testMailTo,
        ?Deadline $deadline = null,
        ?\Closure $sleep = null,
    ) {
        $this->credentials = static fn (): array => [$tenantId, $clientId, $clientSecret];
        $this->deadline = $deadline ?? new Deadline(microtime(true) + Deadline::SUBMIT_BUDGET);
        $this->sleep = $sleep ?? static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
    }

    public static function fromConfig(HttpClient $http, Config $c, ?Deadline $deadline = null, ?\Closure $sleep = null): self
    {
        return new self($http, $c->graphTenantId(), $c->graphClientId(), $c->graphClientSecret(),
            $c->mailFrom(), $c->mailReplyTo(), $c->testMode(), $c->testMailTo(), $deadline, $sleep);
    }

    /**
     * @param list<array{name: string, contentType: string, bytes?: string, path?: string}> $attachments
     * @return array{ok: bool, stage: string, status: int, path: string, cleanup: bool|null}
     *   path "inline" or "large"; cleanup (large path): sent copy deleted (ok) or draft deleted (failure)
     */
    public function send(string $to, string $subject, string $html, array $attachments = []): array
    {
        $total = 0;
        foreach ($attachments as $a) {
            $total += self::size($a);
        }
        if ($this->token() === null) {
            return $this->result(false, 'token', $this->lastTokenStatus, $total > self::MAX_INLINE ? 'large' : 'inline');
        }

        return $total > self::MAX_INLINE
            ? $this->sendLarge($to, $subject, $html, $attachments)
            : $this->sendInline($to, $subject, $html, $attachments);
    }

    /** @return array{ok: bool, stage: string, status: int, path: string, cleanup: bool|null} */
    private function sendInline(string $to, string $subject, string $html, array $attachments): array
    {
        $message = $this->message($to, $subject, $html);
        if ($attachments !== []) {
            $message['attachments'] = array_map(static fn (array $a): array => self::fileAttachment($a), $attachments);
        }
        $body = json_encode(['message' => $message, 'saveToSentItems' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        unset($message);
        $res = $this->call('POST', sprintf(self::SEND_URL, rawurlencode($this->mailFrom)), $this->json(), $body, self::TIMEOUT, [429, 503]);

        return $this->result($res->status === 202, 'send', $res->status, 'inline');
    }

    /** @return array{ok: bool, stage: string, status: int, path: string, cleanup: bool|null} */
    private function sendLarge(string $to, string $subject, string $html, array $attachments): array
    {
        $base = self::GRAPH . '/users/' . rawurlencode($this->mailFrom);
        $res = $this->call('POST', "$base/messages", $this->json(self::PREFER), json_encode($this->message($to, $subject, $html), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), self::TIMEOUT, self::RETRY);
        $id = $res->status === 201 ? ($res->json()['id'] ?? null) : null;
        if (!is_string($id) || !preg_match('~^[A-Za-z0-9=_+/-]{1,512}$~', $id)) {
            return $this->result(false, 'draft', $res->status, 'large');
        }
        $msg = "$base/messages/" . rawurlencode($id);

        foreach ($attachments as $a) {
            [$ok, $stage, $status] = self::size($a) < self::DIRECT_MAX ? $this->attachDirect($msg, $a) : $this->attachSession($msg, $a);
            if (!$ok) {
                return $this->result(false, $stage, $status, 'large', $this->permanentDelete($msg));
            }
        }

        $res = $this->call('POST', "$msg/send", $this->json(self::PREFER), '', self::TIMEOUT, [429, 503]);
        if ($res->status !== 202) {
            $unclear = $res->status === 0 || ($res->status >= 500 && $res->status !== 503);
            if (!$unclear || !$this->wasSent($msg)) {
                return $this->result(false, 'send', $res->status, 'large', $this->permanentDelete($msg));
            }
        }

        return $this->result(true, 'send', 202, 'large', $this->deleteSentCopy($base, $msg));
    }

    /** @return array{0: bool, 1: string, 2: int} */
    private function attachDirect(string $msg, array $a): array
    {
        $body = json_encode(self::fileAttachment($a), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $res = $this->call('POST', "$msg/attachments", $this->json(self::PREFER), $body, self::TIMEOUT, self::RETRY);

        return [$res->status === 201, 'attach', $res->status];
    }

    /** @return array{0: bool, 1: string, 2: int} */
    private function attachSession(string $msg, array $a): array
    {
        $size = self::size($a);
        $body = json_encode(['AttachmentItem' => [
            'attachmentType' => 'file', 'name' => $a['name'], 'size' => $size, 'contentType' => $a['contentType'],
        ]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $res = $this->call('POST', "$msg/attachments/createUploadSession", $this->json(self::PREFER), $body, self::TIMEOUT, self::RETRY);
        $url = $res->status === 201 ? ($res->json()['uploadUrl'] ?? null) : null;
        if (!is_string($url) || !self::isUploadUrl($url)) {
            return [false, 'upload_session', $res->status];
        }

        $offset = 0;
        for ($guard = 0; $offset < $size && $guard < 1000; $guard++) {
            $chunk = self::read($a, $offset, self::CHUNK);
            $end = $offset + strlen($chunk) - 1;
            $headers = ['Content-Type' => 'application/octet-stream', 'Content-Range' => "bytes $offset-$end/$size"];   // no Authorization
            $res = $this->call('PUT', $url, $headers, $chunk, self::CHUNK_TIMEOUT, [...self::RETRY, 0]);
            if ($res->status === 201) {
                return [true, 'upload', 201];
            }
            if ($res->status !== 200) {
                return [false, 'upload', $res->status];
            }
            $next = $res->json()['nextExpectedRanges'][0] ?? null;
            $start = is_string($next) && preg_match('/^(\d+)-/', $next, $m) ? (int) $m[1] : $end + 1;
            if ($start <= $offset && $start !== $end + 1) {
                $start = $end + 1;
            }
            $offset = $start;
        }

        return [false, 'upload', 0];
    }

    /** An unclear send: sent only if the message exists and is no longer a draft. */
    private function wasSent(string $msg): bool
    {
        $res = $this->call('GET', "$msg?\$select=id,isDraft", $this->auth(self::PREFER), null, self::TIMEOUT, [...self::RETRY, 0]);

        return $res->status === 200 && ($res->json()['isDraft'] ?? null) === false;
    }

    /** Wait for the sent copy to reach Sent Items, then permanently delete it. */
    private function deleteSentCopy(string $base, string $msg): bool
    {
        $res = $this->call('GET', "$base/mailFolders/sentitems?\$select=id", $this->auth(self::PREFER), null, self::TIMEOUT, [...self::RETRY, 0]);
        $sentId = $res->status === 200 ? ($res->json()['id'] ?? null) : null;
        if (!is_string($sentId) || $sentId === '') {
            return false;
        }
        for ($i = 0; $i < self::SENT_POLLS; $i++) {
            if ($i > 0) {
                if ($this->deadline->remaining() < 3.0) {
                    return false;
                }
                ($this->sleep)(1.0);
            }
            $res = $this->call('GET', "$msg?\$select=id,isDraft,parentFolderId", $this->auth(self::PREFER), null, self::TIMEOUT, [...self::RETRY, 0]);
            if ($res->status === 200 && ($res->json()['parentFolderId'] ?? null) === $sentId) {
                return $this->permanentDelete($msg);
            }
        }

        return false;
    }

    private function permanentDelete(string $msg): bool
    {
        $res = $this->call('POST', "$msg/permanentDelete", $this->json(self::PREFER), '', self::TIMEOUT, self::RETRY);

        return $res->status === 204 || $res->status === 200;
    }

    /**
     * One Graph call, bounded by the deadline, with bounded retries.
     * @param array<string, string> $headers
     * @param list<int> $retryOn statuses worth retrying (0 = network failure)
     */
    private function call(string $method, string $url, array $headers, ?string $body, int $maxTimeout, array $retryOn): HttpResponse
    {
        for ($attempt = 0; ; $attempt++) {
            $timeout = $this->deadline->timeout($maxTimeout);
            if ($timeout < 1) {
                return new HttpResponse(0, '');
            }
            $res = $this->http->request($method, $url, $headers, $body, $timeout);
            if (!in_array($res->status, $retryOn, true) || $attempt >= self::MAX_RETRIES) {
                return $res;
            }
            $wait = min(self::retryAfter($res) ?? (float) (1 << $attempt), self::MAX_WAIT);
            if ($this->deadline->remaining() < $wait + 2.0) {
                return $res;
            }
            ($this->sleep)($wait);
        }
    }

    /** Retry-After: seconds or an HTTP date. */
    private static function retryAfter(HttpResponse $res): ?float
    {
        $v = trim((string) $res->header('Retry-After'));
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (float) $v;
        }
        $t = strtotime($v);

        return $t === false ? null : (float) max(0, $t - time());
    }

    private static function isUploadUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        foreach (self::UPLOAD_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, ".$h")) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: bool, stage: string, status: int, path: string, cleanup: bool|null} */
    private function result(bool $ok, string $stage, int $status, string $path, ?bool $cleanup = null): array
    {
        return ['ok' => $ok, 'stage' => $stage, 'status' => $status, 'path' => $path, 'cleanup' => $cleanup];
    }

    private static function size(array $a): int
    {
        return isset($a['bytes']) ? strlen($a['bytes']) : (int) @filesize((string) ($a['path'] ?? ''));
    }

    private static function read(array $a, int $offset, ?int $length = null): string
    {
        if (isset($a['bytes'])) {
            return $length === null ? substr($a['bytes'], $offset) : substr($a['bytes'], $offset, $length);
        }
        $h = @fopen((string) ($a['path'] ?? ''), 'rb');
        if ($h === false) {
            throw new \RuntimeException('attachment not readable');
        }
        try {
            fseek($h, $offset);
            $s = $length === null ? stream_get_contents($h) : fread($h, $length);

            return (string) $s;
        } finally {
            fclose($h);
        }
    }

    /** @return array<string, string> */
    private static function fileAttachment(array $a): array
    {
        return [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $a['name'],
            'contentType' => $a['contentType'],
            'contentBytes' => base64_encode(self::read($a, 0)),
        ];
    }

    /** @return array<string, string> */
    private function auth(array $extra = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->token] + $extra;
    }

    /** @return array<string, string> */
    private function json(array $extra = []): array
    {
        return $this->auth($extra) + ['Content-Type' => 'application/json'];
    }

    /** print_r/var_dump would show the closure's captured credentials; show safe fields only. */
    public function __debugInfo(): array
    {
        return ['mailFrom' => $this->mailFrom, 'replyTo' => $this->replyTo, 'testMode' => $this->testMode, 'credentials' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('GraphMailer cannot be serialized');
    }

    /** @return array<string, mixed> */
    private function message(string $to, string $subject, string $html): array
    {
        return [
            'subject' => ($this->testMode ? '[TEST] ' : '') . $subject,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => [['emailAddress' => ['address' => $this->testMode ? $this->testMailTo : $to]]],
            'replyTo' => [['emailAddress' => ['address' => $this->replyTo]]],
        ];
    }

    private function token(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        [$tenant, $clientId, $secret] = ($this->credentials)();
        $res = $this->call(
            'POST',
            sprintf(self::TOKEN_URL, rawurlencode($tenant)),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => self::SCOPE,
                'grant_type' => 'client_credentials',
            ], '', '&', PHP_QUERY_RFC3986),
            self::TIMEOUT,
            [...self::RETRY, 0],
        );
        $this->lastTokenStatus = $res->status;
        $token = $res->status === 200 ? ($res->json()['access_token'] ?? null) : null;

        return $this->token = is_string($token) && $token !== '' ? $token : null;
    }
}
