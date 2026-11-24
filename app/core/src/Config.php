<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use LogicException;
use RuntimeException;

/**
 * Runtime configuration from /etc/vea-intake/app.env (docs/PLAN.md section 9).
 * Copied from vea-join's Config and generalized.
 *
 * Secret values live only in this object's private closure. They are never put
 * into the environment (putenv/$_ENV/getenv), never included in exception
 * messages, and redacted from var_dump/print_r/var_export/serialize.
 *
 * Differences from vea-join:
 *  - keys of PLAN section 9: per-form ACCESS_AUD_<FORM> and MAIL_TO_<FORM>; no Turnstile,
 *    no age range
 *  - no limits here: upload and narrative limits come from the form's content.json
 *    (FormLimits), the same file the browser uses (PLAN 1.9, decision F); the old
 *    UPLOAD_* and NARRATIVE_MAX_WORDS keys are ignored (OBSOLETE)
 *  - a blank or missing TEST_MODE means test mode: never production by accident
 *  - no form version here: it lives in the repo (app/forms/<form>/content.json)
 */
final class Config
{
    public const SECRET_KEYS = ['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET'];

    /** Forms served by this deployment; each needs ACCESS_AUD_<FORM> and MAIL_TO_<FORM>. */
    public const FORMS = ['legal'];

    private const REQUIRED = [
        'APP_ENV', 'APP_BASE_URL', 'DISPLAY_TZ', 'ACCESS_TEAM_DOMAIN',
        'GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET',
        'MAIL_FROM', 'MAIL_REPLY_TO',
    ];

    /** Keys that may still be in app.env but are ignored (limits live in content.json; form version too). */
    public const OBSOLETE = ['UPLOAD_MAX_FILES', 'UPLOAD_MAX_FILE_MB', 'UPLOAD_MAX_TOTAL_MB', 'NARRATIVE_MAX_WORDS', 'FORM_VERSION_LEGAL'];

    /** @var array<string, string> non-secret values only */
    private readonly array $values;

    /** Secrets are captured in a closure: var_export/var_dump/print_r/json_encode cannot reach them. */
    private readonly \Closure $secret;

    /** @param array<string, string> $values */
    private function __construct(#[\SensitiveParameter] array $values)
    {
        $secrets = array_intersect_key($values, array_flip(self::SECRET_KEYS));
        $this->values = array_diff_key($values, $secrets);
        $this->secret = static fn (string $key): string => $secrets[$key];
    }

    public static function load(string $path = '/etc/vea-intake/app.env'): self
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('app.env is not readable');
        }
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) {
                $values[$m[1]] = self::parseValue($m[2]);
            }
        }

        return self::fromArray($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(#[\SensitiveParameter] array $values): self
    {
        $required = self::REQUIRED;
        foreach (self::FORMS as $form) {
            $required[] = 'ACCESS_AUD_' . strtoupper($form);
            $required[] = 'MAIL_TO_' . strtoupper($form);
        }
        foreach ($required as $key) {
            if (!isset($values[$key]) || $values[$key] === '') {
                throw new RuntimeException("app.env: $key is missing or empty");
            }
        }
        if (self::testModeOf($values) && ($values['TEST_MAIL_TO'] ?? '') === '') {
            throw new RuntimeException('app.env: TEST_MAIL_TO is required in test mode (TEST_MODE blank or true)');
        }
        $emails = ['MAIL_FROM', 'MAIL_REPLY_TO'];
        foreach (self::FORMS as $form) {
            $emails[] = 'MAIL_TO_' . strtoupper($form);
        }
        foreach ($emails as $key) {
            if (filter_var($values[$key], FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException("app.env: $key is not an email address");
            }
        }
        if (($values['TEST_MAIL_TO'] ?? '') !== '' && filter_var($values['TEST_MAIL_TO'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('app.env: TEST_MAIL_TO is not an email address');
        }
        if (!in_array($values['DISPLAY_TZ'], timezone_identifiers_list(), true)) {
            throw new RuntimeException('app.env: DISPLAY_TZ is not a valid time zone');
        }
        if (!preg_match('~^https://[a-z0-9-]+\.cloudflareaccess\.com$~', rtrim($values['ACCESS_TEAM_DOMAIN'], '/'))) {
            throw new RuntimeException('app.env: ACCESS_TEAM_DOMAIN must be https://<team>.cloudflareaccess.com');
        }
        return new self($values);
    }

    // ---------------------------------------------------------- public
    public function appEnv(): string { return $this->values['APP_ENV']; }
    public function baseUrl(): string { return rtrim($this->values['APP_BASE_URL'], '/'); }
    public function hostname(): string { return (string) parse_url($this->values['APP_BASE_URL'], PHP_URL_HOST); }
    public function displayTz(): string { return $this->values['DISPLAY_TZ']; }
    public function accessTeamDomain(): string { return rtrim($this->values['ACCESS_TEAM_DOMAIN'], '/'); }
    public function accessAud(string $form): string { return $this->values['ACCESS_AUD_' . self::formKey($form)]; }
    public function mailFrom(): string { return $this->values['MAIL_FROM']; }
    public function mailTo(string $form): string { return $this->values['MAIL_TO_' . self::formKey($form)]; }
    public function mailReplyTo(): string { return $this->values['MAIL_REPLY_TO']; }
    public function testMode(): bool { return self::testModeOf($this->values); }
    public function testMailTo(): string { return $this->values['TEST_MAIL_TO'] ?? ''; }

    // --------------------------------------------------------- secrets
    public function graphTenantId(): string { return ($this->secret)('GRAPH_TENANT_ID'); }
    public function graphClientId(): string { return ($this->secret)('GRAPH_CLIENT_ID'); }
    public function graphClientSecret(): string { return ($this->secret)('GRAPH_CLIENT_SECRET'); }

    // -------------------------------------------------------- redaction
    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return $this->values + array_fill_keys(self::SECRET_KEYS, '[redacted]');
    }

    public function __serialize(): array
    {
        throw new LogicException('Config cannot be serialized');
    }

    public static function __set_state(array $state): never
    {
        throw new LogicException('Config cannot be exported');
    }

    // --------------------------------------------------------- private
    private static function formKey(string $form): string
    {
        if (!in_array($form, self::FORMS, true)) {
            throw new LogicException('unknown form');
        }

        return strtoupper($form);
    }

    /** Blank or missing TEST_MODE = test mode; only an explicit false value means production. */
    private static function testModeOf(array $values): bool
    {
        $v = strtolower(trim($values['TEST_MODE'] ?? ''));

        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }

    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $end = strpos($raw, $raw[0], 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }
}
