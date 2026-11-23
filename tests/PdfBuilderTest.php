<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\PdfBuilder;

final class PdfBuilderTest extends TestCase
{
    public function testFilename(): void
    {
        self::assertSame('Legal-Request_20261123-1200-ABCD_ODonnell_Jose.pdf', PdfBuilder::filename('Legal-Request', '20261123-1200-ABCD', "O'Donnell", 'José'));
        self::assertSame('Legal-Request_20261123-1200-ABCD_X_X.pdf', PdfBuilder::filename('Legal-Request', '20261123-1200-ABCD', '../', '<>'));
    }

    public function testDompdfIsLockedDown(): void
    {
        $o = PdfBuilder::options('/run/vea-intake/pdf-test');
        self::assertFalse($o->getIsRemoteEnabled());
        self::assertFalse($o->getIsPhpEnabled());
        self::assertFalse($o->getIsJavascriptEnabled());
        self::assertSame(['data://'], array_keys($o->getAllowedProtocols()));
        self::assertSame('/run/vea-intake/pdf-test', $o->getTempDir());
    }

    public function testSharedLogoExists(): void
    {
        self::assertFileExists(PdfBuilder::LOGO);
    }
}
