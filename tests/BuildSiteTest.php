<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/** bin/build-site.php: placeholders, errors, no app.env. */
final class BuildSiteTest extends TestCase
{
    private TempTree $t;

    protected function setUp(): void
    {
        $this->t = new TempTree();
        $this->t->json('app/forms/legal/content.json', ['_meta' => ['formVersion' => '9.9'], 'page' => ['title' => 'Title & <b>']]);
        $this->t->json('app/data/assets-legal.json', ['form' => 'legal', 'files' => ['css/app.css' => '/legal/assets/css/app.css?v=abc123abc123']]);
        $this->t->json('app/data/form-data.json', ['version' => 'feedfacefeedface']);
    }

    protected function tearDown(): void
    {
        $this->t->remove();
    }

    public function testRendersPlaceholdersEscaped(): void
    {
        $this->t->write('app/forms/legal/templates/index.html', '<title>{{t:page.title}}</title><link href="{{asset:css/app.css}}"><a href="{{dataurl}}">');
        [$code, $out] = $this->t->run('build-site.php', '--form=legal');
        self::assertSame(0, $code, $out);
        self::assertSame(
            '<title>Title &amp; &lt;b&gt;</title><link href="/legal/assets/css/app.css?v=abc123abc123"><a href="/legal/data/form-data.json?v=feedfacefeedface">',
            $this->t->read('public/legal/index.html'),
        );
    }

    public function testUnknownPlaceholderMissingKeyAndUnpublishedAssetAreErrors(): void
    {
        $this->t->write('app/forms/legal/templates/index.html', '{{cfg:TURNSTILE_SITE_KEY}} {{t:page.nope}} {{asset:js/app.js}}');
        [$code, $out] = $this->t->run('build-site.php', '--form=legal');
        self::assertSame(1, $code);
        self::assertStringContainsString('unknown placeholder', $out);
        self::assertStringContainsString('no such string in content.json', $out);
        self::assertStringContainsString('not a published asset', $out);
        self::assertFileDoesNotExist($this->t->root . '/public/legal/index.html');
    }
}
