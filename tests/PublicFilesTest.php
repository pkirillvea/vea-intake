<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;

/** What the build publishes under public/ (served by nginx; www-data can read it). Modelled on vea-join's PublicSecretLeakTest. */
final class PublicFilesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return list<string> */
    private static function publicFiles(): array
    {
        $out = [];
        $dir = self::ROOT . '/public';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);

        return $out;
    }

    public function testOnlyExpectedFileTypes(): void
    {
        foreach (self::publicFiles() as $f) {
            self::assertMatchesRegularExpression('~^legal/(index\.html|data/form-data\.json|assets/[a-z0-9/._-]+\.(css|js|png|ico|svg|woff2)|submit\.php|ping\.php)$~', $f);
            self::assertDoesNotMatchRegularExpression('~(^|/)\.~', $f, 'no dotfiles');
        }
    }

    public function testNoSecretsOrServerPaths(): void
    {
        foreach (self::publicFiles() as $f) {
            $s = (string) file_get_contents(self::ROOT . "/public/$f");
            foreach (['GRAPH_CLIENT_SECRET', 'GRAPH_TENANT_ID', 'ACCESS_AUD', 'BEGIN PRIVATE KEY', '/etc/vea-intake', 'app.env'] as $needle) {
                self::assertStringNotContainsString($needle, $s, "$f contains $needle");
            }
        }
    }

    public function testNoSignatureOrTurnstileShipped(): void
    {
        foreach (self::publicFiles() as $f) {
            self::assertDoesNotMatchRegularExpression('/signature_pad|Dancing Script|turnstile/i', (string) file_get_contents(self::ROOT . "/public/$f"), $f);
        }
    }
}
