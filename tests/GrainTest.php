<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/**
 * The page texture (PLAN 1.7): app/shared-assets/img/grain.png is exactly what
 * bin/make-grain.php produces, small, a subtle grain around #7B7B7B, and app.css
 * only references files the asset build publishes.
 */
final class GrainTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';
    private const PNG = self::ROOT . '/app/shared-assets/img/grain.png';

    public function testCommittedFileIsReproducible(): void
    {
        $t = new TempTree();
        try {
            $t->write('app/shared-assets/img/.keep', '');
            [$code, $out] = $t->run('make-grain.php');
            self::assertSame(0, $code, $out);
            self::assertSame(hash_file('sha256', self::PNG), hash('sha256', $t->read('app/shared-assets/img/grain.png')),
                'grain.png differs from bin/make-grain.php output: regenerate with php bin/make-grain.php');
        } finally {
            $t->remove();
        }
    }

    public function testSmallSubtleGrainAroundPageBg(): void
    {
        self::assertLessThan(10 * 1024, filesize(self::PNG));
        $info = getimagesize(self::PNG);
        self::assertSame([128, 128, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);

        if (!function_exists('imagecreatefrompng')) {
            self::markTestSkipped('GD not available');
        }
        $img = imagecreatefrompng(self::PNG);
        $sum = 0;
        $min = 255;
        $max = 0;
        for ($y = 0; $y < 128; $y++) {
            for ($x = 0; $x < 128; $x++) {
                $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                self::assertSame($c['red'], $c['green']);
                self::assertSame($c['red'], $c['blue']);
                $sum += $c['red'];
                $min = min($min, $c['red']);
                $max = max($max, $c['red']);
            }
        }
        self::assertEqualsWithDelta(0x7B, $sum / (128 * 128), 0.5);   // averages to --page-bg
        self::assertGreaterThanOrEqual(0x7B - 4, $min);
        self::assertLessThanOrEqual(0x7B + 4, $max);
        self::assertGreaterThan($min, $max);                           // it is a texture, not flat
    }

    public function testCssUrlsPointAtPublishedAssetsAndPageBgIsSet(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/app/shared-assets/css/app.css');
        self::assertSame(1, preg_match_all('/url\(\s*"?([^")]+)"?\s*\)/', $css, $m));
        self::assertSame('../img/grain.png', $m[1][0]);
        self::assertStringContainsString("'img/grain.png'", (string) file_get_contents(self::ROOT . '/bin/build-assets.php'));
        self::assertMatchesRegularExpression('/--page-bg:\s*#7B7B7B;/', $css);
        self::assertMatchesRegularExpression('/background:\s*var\(--page-bg\)\s+url\("\.\.\/img\/grain\.png"\)/', $css);
    }
}
