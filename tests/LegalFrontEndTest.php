<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Tests\Support\TempTree;

/**
 * The Legal form's page template and legal.js against content.json: every key
 * they use exists, the real template builds, and no visible text is hard-coded.
 */
final class LegalFrontEndTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';
    private const TEMPLATE = self::ROOT . '/app/forms/legal/templates/index.html';
    private const JS = self::ROOT . '/app/forms/legal/assets/js/legal.js';

    /** @return array<string, mixed> */
    private static function content(): array
    {
        return json_decode((string) file_get_contents(self::ROOT . '/app/forms/legal/content.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function testRealTemplateBuilds(): void
    {
        $t = new TempTree();
        try {
            $t->write('app/forms/legal/templates/index.html', (string) file_get_contents(self::TEMPLATE));
            $t->write('app/forms/legal/content.json', (string) file_get_contents(self::ROOT . '/app/forms/legal/content.json'));
            $files = [];
            foreach (['css/app.css', 'js/legal.js', 'img/vea-logo-white.png', 'img/icons/favicon-32.png', 'img/icons/apple-touch-icon.png'] as $p) {
                $files[$p] = "/legal/assets/$p?v=000000000000";
            }
            $t->json('app/data/assets-legal.json', ['form' => 'legal', 'files' => $files]);
            $t->json('app/data/form-data.json', ['version' => '0123456789abcdef']);
            [$code, $out] = $t->run('build-site.php', '--form=legal');
            self::assertSame(0, $code, $out);

            $html = $t->read('public/legal/index.html');
            self::assertStringNotContainsString('{{', $html, 'every placeholder replaced');
            self::assertStringContainsString('PRIVILEGED &amp; CONFIDENTIAL – PREPARED AT THE DIRECTION OF COUNSEL', $html);
            self::assertStringContainsString('data-form-version="2026.11-1"', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $html, 'no inline scripts (CSP)');
            self::assertDoesNotMatchRegularExpression('/<style|\sstyle=/i', $html, 'no inline styles (CSP)');
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html, 'no inline event handlers (CSP)');

            // the #strings JSON carries every group legal.js reads
            preg_match('/id="strings" hidden data-json="([^"]*)"/', $html, $m);
            $strings = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 32, JSON_THROW_ON_ERROR);
            foreach (self::jsKeyPaths() as $path) {
                if ($path[0] === 'STR') {
                    self::assertArrayHasKey($path[1], $strings, 'STR.' . $path[1] . ' is not in the #strings groups');
                }
            }
        } finally {
            $t->remove();
        }
    }

    /** @return list<list<string>> e.g. ['MSG', 'required'], ['F', 'narrative', 'counter'] */
    private static function jsKeyPaths(): array
    {
        preg_match_all('/\b(STR|MSG|F|U|LIM)((?:\.[A-Za-z_]\w*)+)/', (string) file_get_contents(self::JS), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $out[] = array_merge([$hit[1]], explode('.', substr($hit[2], 1)));
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    public function testEveryContentKeyUsedByLegalJsExists(): void
    {
        $c = self::content();
        $roots = ['STR' => $c, 'MSG' => $c['messages'], 'F' => $c['fields'], 'U' => $c['uploads'], 'LIM' => $c['limits']];
        $paths = self::jsKeyPaths();
        self::assertGreaterThan(50, count($paths), 'sanity: legal.js reads many strings');
        foreach ($paths as $path) {
            $node = $roots[$path[0]];
            foreach (array_slice($path, 1) as $key) {
                if (!is_array($node) || array_is_list($node)) {
                    break;   // a list or a value: what follows is a JS method/property (e.g. .includes)
                }
                self::assertArrayHasKey($key, $node, implode('.', $path) . ' is used by legal.js but missing in content.json');
                $node = $node[$key];
            }
        }
    }

    public function testEveryTemplatePlaceholderKeyExists(): void
    {
        $c = self::content();
        preg_match_all('/\{\{t:([^}]+)\}\}/', (string) file_get_contents(self::TEMPLATE), $m);
        foreach (array_unique($m[1]) as $path) {
            $node = $c;
            foreach (explode('.', $path) as $k) {
                self::assertIsArray($node, $path);
                self::assertArrayHasKey($k, $node, "{{t:$path}} missing in content.json");
                $node = $node[$k];
            }
            self::assertTrue(is_string($node) || is_int($node), "{{t:$path}} is not a string or number");
        }
    }

    public function testNoVisibleTextHardCodedInLegalJs(): void
    {
        // String literals with letters and a space are visible text; legal.js must take them from content.json.
        $js = (string) file_get_contents(self::JS);
        $js = (string) preg_replace('~/\*.*?\*/|^\s*//.*$~ms', '', $js);   // drop comments
        preg_match_all("/'([^'\\\\\\n]*)'|`([^`]*)`/", $js, $m);
        $offenders = [];
        foreach (array_merge($m[1], $m[2]) as $s) {
            if ($s !== 'use strict' && preg_match('/[A-Za-z]{2,}\s+[A-Za-z]{2,}/', $s) && !str_contains($s, '${')) {
                $offenders[] = $s;
            }
        }
        self::assertSame([], $offenders, 'visible-looking text literals in legal.js');
    }

    public function testNoStorageApisInLegalJs(): void
    {
        $js = (string) file_get_contents(self::JS);
        foreach (['localStorage', 'sessionStorage', 'document.cookie', 'indexedDB'] as $api) {
            self::assertStringNotContainsString($api, (string) preg_replace('~/\*.*?\*/~s', '', $js), "PLAN 3: no $api");
        }
    }
}
