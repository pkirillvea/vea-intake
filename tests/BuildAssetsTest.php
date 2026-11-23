<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/** bin/build-assets.php: allowlist, exact publication, manifest. */
final class BuildAssetsTest extends TestCase
{
    private TempTree $t;

    protected function setUp(): void
    {
        $this->t = new TempTree();
        // the shared allowlist
        $this->t->write('app/shared-assets/css/app.css', 'body{}');
        $this->t->write('app/shared-assets/img/grain.png', 'png0');
        $this->t->write('app/shared-assets/img/vea-logo-white.png', 'png1');
        $this->t->write('app/shared-assets/img/icons/favicon-32.png', 'png2');
        $this->t->write('app/shared-assets/img/icons/apple-touch-icon.png', 'png3');
        // the Legal form's own allowlist
        $this->t->write('app/forms/legal/assets/js/legal.js', '/* js */');
    }

    protected function tearDown(): void
    {
        $this->t->remove();
    }

    /** @return list<string> */
    private function published(): array
    {
        $dir = $this->t->root . '/public/legal/assets';
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);

        return $out;
    }

    public function testPublishesExactlyTheAllowlistWithHashedUrls(): void
    {
        [$code, $out] = $this->t->run('build-assets.php', '--form=legal');
        self::assertSame(0, $code, $out);
        self::assertSame(['css/app.css', 'img/grain.png', 'img/icons/apple-touch-icon.png', 'img/icons/favicon-32.png', 'img/vea-logo-white.png', 'js/legal.js'], $this->published());

        $m = json_decode($this->t->read('app/data/assets-legal.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('/legal/assets/css/app.css?v=' . substr(hash('sha256', 'body{}'), 0, 12), $m['files']['css/app.css']);
    }

    public function testStaleFilesInTheTargetAreRemoved(): void
    {
        $this->t->write('public/legal/assets/old.js', 'stale');
        [$code] = $this->t->run('build-assets.php', '--form=legal');
        self::assertSame(0, $code);
        self::assertNotContains('old.js', $this->published());
    }

    public function testFileOutsideTheAllowlistFailsAndPublishesNothing(): void
    {
        $this->t->write('app/shared-assets/secret-notes.txt', 'x');
        $this->t->write('app/shared-assets/.env', 'X=1');
        [$code, $out] = $this->t->run('build-assets.php', '--form=legal');
        self::assertSame(1, $code);
        self::assertStringContainsString('app/shared-assets/secret-notes.txt is not on the allowlist', $out);
        self::assertStringContainsString('app/shared-assets/.env is not on the allowlist', $out);
        self::assertDirectoryDoesNotExist($this->t->root . '/public/legal/assets');
    }

    public function testMissingAllowlistedFileFails(): void
    {
        unlink($this->t->root . '/app/shared-assets/css/app.css');
        [$code, $out] = $this->t->run('build-assets.php', '--form=legal');
        self::assertSame(1, $code);
        self::assertStringContainsString('css/app.css is on the allowlist but missing', $out);
    }

    public function testFormFolderAlsoNeedsAllowlisting(): void
    {
        $this->t->write('app/forms/legal/assets/js/extra.js', '1');
        [$code, $out] = $this->t->run('build-assets.php', '--form=legal');
        self::assertSame(1, $code);
        self::assertStringContainsString('app/forms/legal/assets/js/extra.js is not on the allowlist', $out);
    }

    public function testUnknownFormFails(): void
    {
        [$code, $out] = $this->t->run('build-assets.php', '--form=nope');
        self::assertSame(1, $code);
        self::assertStringContainsString('--form must be one of', $out);
    }
}
