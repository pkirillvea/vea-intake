<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeaIntake\Core\Config;

final class ConfigTest extends TestCase
{
    /** @return array<string, string> */
    private static function valid(array $override = []): array
    {
        return array_merge([
            'APP_ENV' => 'production',
            'APP_BASE_URL' => 'https://intake.veanea.net/',
            'DISPLAY_TZ' => 'America/New_York',
            'ACCESS_TEAM_DOMAIN' => 'https://example-team.cloudflareaccess.com',
            'ACCESS_AUD_LEGAL' => 'aud-tag-123',
            'GRAPH_TENANT_ID' => 'tenant-secret-value',
            'GRAPH_CLIENT_ID' => 'client-secret-value',
            'GRAPH_CLIENT_SECRET' => 'very-secret-value',
            'MAIL_FROM' => 'no.reply@example.org',
            'MAIL_TO_LEGAL' => 'legal@example.org',
            'MAIL_REPLY_TO' => 'no.reply@example.org',
            'TEST_MODE' => 'false',
        ], $override);
    }

    public function testValues(): void
    {
        $c = Config::fromArray(self::valid());
        self::assertSame('https://intake.veanea.net', $c->baseUrl());
        self::assertSame('intake.veanea.net', $c->hostname());
        self::assertSame('aud-tag-123', $c->accessAud('legal'));
        self::assertSame('legal@example.org', $c->mailTo('legal'));
        self::assertFalse($c->testMode());
        self::assertSame('very-secret-value', $c->graphClientSecret());
    }

    public function testBlankOrMissingTestModeMeansTestMode(): void
    {
        foreach (['', '  ', 'true', 'maybe'] as $v) {
            $c = Config::fromArray(self::valid(['TEST_MODE' => $v, 'TEST_MAIL_TO' => 'test@example.org']));
            self::assertTrue($c->testMode(), "TEST_MODE='$v'");
        }
        $values = self::valid(['TEST_MAIL_TO' => 'test@example.org']);
        unset($values['TEST_MODE']);
        self::assertTrue(Config::fromArray($values)->testMode());
    }

    public function testTestModeNeedsTestMailTo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_MAIL_TO is required in test mode');
        Config::fromArray(self::valid(['TEST_MODE' => '']));
    }

    public function testSkeletonWithBlankValuesIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Config::fromArray(array_fill_keys(array_keys(self::valid()), ''));
    }

    public function testFormVersionIsNotAConfigKey(): void
    {
        $c = Config::fromArray(self::valid(['FORM_VERSION_LEGAL' => '']));   // the old skeleton line is ignored
        self::assertFalse(method_exists($c, 'formVersion'));
    }

    public function testValidation(): void
    {
        foreach ([
            ['ACCESS_TEAM_DOMAIN' => 'https://evil.example.com'],
            ['MAIL_TO_LEGAL' => 'not-an-email'],
            ['DISPLAY_TZ' => 'Mars/Base'],
            ['ACCESS_AUD_LEGAL' => ''],
        ] as $bad) {
            try {
                Config::fromArray(self::valid($bad));
                self::fail('accepted ' . json_encode($bad));
            } catch (RuntimeException $e) {
                self::assertStringStartsWith('app.env: ', $e->getMessage());
            }
        }
    }

    public function testObsoleteLimitKeysAreIgnored(): void
    {
        // Limits live in content.json (FormLimits); leftover app.env lines must not break anything.
        $c = Config::fromArray(self::valid(['UPLOAD_MAX_FILES' => '0', 'NARRATIVE_MAX_WORDS' => 'lots', 'FORM_VERSION_LEGAL' => '']));
        self::assertSame('legal@example.org', $c->mailTo('legal'));
        self::assertFalse(method_exists($c, 'uploadMaxFiles'));
        self::assertFalse(method_exists($c, 'narrativeMaxWords'));
    }

    public function testErrorsNeverContainSecretValues(): void
    {
        try {
            Config::fromArray(self::valid(['MAIL_FROM' => 'x']));
            self::fail('expected an exception');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('very-secret-value', $e->getMessage());
        }
    }

    public function testSecretsAreRedactedFromDumps(): void
    {
        $c = Config::fromArray(self::valid());
        self::assertStringNotContainsString('very-secret-value', print_r($c, true));
        ob_start();
        var_dump($c);
        self::assertStringNotContainsString('very-secret-value', (string) ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($c);
    }

    public function testLoadParsesQuotesAndComments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        try {
            $lines = [];
            foreach (self::valid() as $k => $v) {
                $lines[] = "$k=$v";
            }
            $lines[] = 'TEST_MAIL_TO="test@example.org"   # quoted';
            $lines[] = '# FORM_VERSION_LEGAL=ignored';
            file_put_contents($file, implode("\n", $lines));
            self::assertSame('test@example.org', Config::load($file)->testMailTo());
        } finally {
            unlink($file);
        }
    }
}
