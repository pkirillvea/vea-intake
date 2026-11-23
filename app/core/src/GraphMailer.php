<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Microsoft Graph mail with client credentials (docs/PLAN.md sections 1, 8, 12).
 * Copied from vea-join's GraphMailer and generalized to several attachments.
 *
 * - Attachments totalling <= MAX_INLINE: POST /users/{MAIL_FROM}/sendMail with
 *   saveToSentItems: false (vea-join's path, unchanged).
 * - Larger: draft + upload sessions + send + permanentDelete of the sent copy
 *   (PLAN section 12). Implemented in Prompt 4; sendLarge() is a stub until then.
 * - TEST_MODE: every recipient becomes TEST_MAIL_TO and the subject gets "[TEST] ".
 * - Never logs or returns response bodies; callers log only event, ID, status.
 */
final class GraphMailer
{
    public const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    public const SEND_URL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';
    public const SCOPE = 'https://graph.microsoft.com/.default';
    public const TIMEOUT = 10;
    public const MAX_INLINE = 3 * 1024 * 1024 - 1;   // total attachment bytes for the sendMail path

    private ?string $token = null;
    private int $lastTokenStatus = 0;
    private readonly \Closure $credentials;

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter] string $tenantId,
        #[\SensitiveParameter] string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        private readonly string $mailFrom,
        private readonly string $replyTo,
        private readonly bool $testMode,
        private readonly string $testMailTo,
    ) {
        $this->credentials = static fn (): array => [$tenantId, $clientId, $clientSecret];
    }

    public static function fromConfig(HttpClient $http, Config $c): self
    {
        return new self($http, $c->graphTenantId(), $c->graphClientId(), $c->graphClientSecret(),
            $c->mailFrom(), $c->mailReplyTo(), $c->testMode(), $c->testMailTo());
    }

    /**
     * @param list<array{name: string, contentType: string, bytes: string}> $attachments
     * @return array{ok: bool, stage: string, status: int} stage is "token", "send" or a large-path stage
     */
    public function send(string $to, string $subject, string $html, array $attachments = []): array
    {
        $total = array_sum(array_map(static fn (array $a): int => strlen($a['bytes']), $attachments));
        if ($total > self::MAX_INLINE) {
            return $this->sendLarge($to, $subject, $html, $attachments);
        }

        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'stage' => 'token', 'status' => $this->lastTokenStatus];
        }
        $message = $this->message($to, $subject, $html);
        if ($attachments !== []) {
            $message['attachments'] = array_map(static fn (array $a): array => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $a['name'],
                'contentType' => $a['contentType'],
                'contentBytes' => base64_encode($a['bytes']),
            ], $attachments);
        }
        $body = json_encode(['message' => $message, 'saveToSentItems' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $res = $this->http->request(
            'POST',
            sprintf(self::SEND_URL, rawurlencode($this->mailFrom)),
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            $body,
            self::TIMEOUT,
        );

        return ['ok' => $res->status === 202, 'stage' => 'send', 'status' => $res->status];
    }

    /**
     * Large-attachment path (PLAN section 12): create a draft in MAIL_FROM
     * (Prefer: IdType="ImmutableId"), add the attachments with upload sessions,
     * send, then permanentDelete the sent copy; log sent_copy_cleanup_failed if
     * that delete fails.
     *
     * @param list<array{name: string, contentType: string, bytes: string}> $attachments
     * @return array{ok: bool, stage: string, status: int}
     */
    public function sendLarge(string $to, string $subject, string $html, array $attachments): array
    {
        throw new \LogicException('Prompt 4');
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
        $res = $this->http->request(
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
        );
        $this->lastTokenStatus = $res->status;
        $token = $res->status === 200 ? ($res->json()['access_token'] ?? null) : null;

        return $this->token = is_string($token) && $token !== '' ? $token : null;
    }
}
