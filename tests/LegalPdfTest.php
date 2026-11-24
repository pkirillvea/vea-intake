<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\AccessIdentity;
use VeaIntake\Core\Audit;
use VeaIntake\Core\FormLimits;
use VeaIntake\Core\PdfBuilder;
use VeaIntake\Core\Template;
use VeaIntake\Core\UploadedFile;
use VeaIntake\Forms\Legal\LegalAnswers;
use VeaIntake\Tests\Support\LegalFixtures as Fx;
use VeaIntake\Tests\Support\TempTree;

/** The PDF's rows (same rule as the review page), its template and a real dompdf render. */
final class LegalPdfTest extends TestCase
{
    /** @return array<string, array{label: string, lines: list<string>, long: bool}> label => row */
    private static function rows(array $over = []): array
    {
        $c = Template::content('legal');
        $out = [];
        foreach (LegalAnswers::sections(Fx::payload($over), $c, FormLimits::fromContent($c), Fx::formData()->notListed()) as $s) {
            foreach ($s['rows'] as $r) {
                $out[$r['label']] = $r;
            }
        }

        return $out;
    }

    public function testSectionsAndLabelsMatchTheReviewPage(): void
    {
        $c = Template::content('legal');
        $secs = LegalAnswers::sections(Fx::payload(), $c, FormLimits::fromContent($c), Fx::formData()->notListed());
        self::assertSame([$c['sections']['member'], $c['sections']['employment'], $c['sections']['issue']], array_column($secs, 'title'));
        $r = self::rows();
        self::assertSame(['Testy McTestface'], $r['First name / Last name']['lines']);
        self::assertSame(['123 Fake Street', 'Richmond, Virginia 23219'], $r[$c['fields']['address']['label']]['lines']);
        self::assertSame(['(804) 555-1234'], $r[$c['fields']['phone']['label']]['lines']);
        self::assertSame([$c['fields']['dateOfBirth']['notProvided']], $r[$c['fields']['dateOfBirth']['label']]['lines']);
        self::assertSame(['11/01/2026'], $r[$c['fields']['incidentDate']['label']]['lines']);
    }

    public function testLongAnswerRule(): void
    {
        $F = Template::content('legal')['fields'];
        $r = self::rows(['requestedOutcome' => 'Advice']);
        self::assertTrue($r[$F['narrative']['label']]['long'], 'narrative always');
        self::assertTrue($r[$F['requestedOutcome']['label']]['long'], 'requested outcome always, even short');
        self::assertFalse($r[$F['issueType']['label']]['long'], 'issue type inline unless Other');
        self::assertFalse($r[$F['address']['label']]['long'], 'address: two structured lines, inline');
        self::assertFalse($r[$F['jobTitle']['label']]['long']);

        $r = self::rows(['issueType' => 'Other', 'issueTypeOther' => 'X', 'jobTitle' => str_repeat('j', 41), 'timeInPosition' => str_repeat('t', 40)]);
        self::assertTrue($r[$F['issueType']['label']]['long'], 'Other');
        self::assertSame(['Other: X'], $r[$F['issueType']['label']]['lines']);
        self::assertTrue($r[$F['jobTitle']['label']]['long'], '41 > reviewInlineMaxChars');
        self::assertFalse($r[$F['timeInPosition']['label']]['long'], 'exactly 40 stays inline');
        self::assertTrue(LegalAnswers::isLongText("a\nb", 40));
        self::assertFalse(LegalAnswers::isLongText(str_repeat('é', 40), 40), 'characters, not bytes');
    }

    public function testNotListedShowsTheTypedValue(): void
    {
        $nl = Fx::formData()->notListed();
        $F = Template::content('legal')['fields'];
        $r = self::rows(['local' => $nl, 'localOther' => 'Fake Local', 'worksite' => $nl, 'worksiteOther' => 'Fake School']);
        self::assertSame(["$nl: Fake Local"], $r[$F['local']['label']]['lines']);
        self::assertSame(["$nl: Fake School"], $r[$F['worksite']['label']]['lines']);
    }

    /** @return array<string, mixed> */
    private static function vars(array $over = []): array
    {
        $c = Template::content('legal');
        $d = Fx::payload($over);
        $files = [new UploadedFile('<b>Bad</b> name.pdf', '01_b_Bad_b_name.pdf', 2048, 'pdf', 'application/pdf', str_repeat('f', 64), '/x')];
        $audit = Audit::build('20261123-1200-ABCD', new AccessIdentity('staff@example.org'), ['REMOTE_ADDR' => '198.51.100.1'], $d, $files, Fx::now(), 'America/New_York', 'not available');

        return ['data' => $d, 'id' => '20261123-1200-ABCD', 'audit' => $audit, 'logo' => 'data:image/png;base64,', 'c' => $c,
            'answers' => LegalAnswers::sections($d, $c, FormLimits::fromContent($c), Fx::formData()->notListed()), 'files' => $audit['files']];
    }

    public function testTemplateEscapesAndKeepsLineBreaks(): void
    {
        $html = Template::render('legal', 'pdf', self::vars(['narrative' => "<script>alert(1)</script>\n\nSecond", 'firstName' => 'A&B']));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;' . "\n\nSecond", $html, 'line breaks kept inside the pre-wrap value');
        self::assertStringContainsString('A&amp;B McTestface', $html);
        self::assertStringContainsString('&lt;b&gt;Bad&lt;/b&gt; name.pdf', $html);
        self::assertMatchesRegularExpression('~<td class="long" colspan="2"><div class="lbl">Brief narrative of the problem</div><div class="val">~', $html);
        self::assertStringContainsString('white-space: pre-wrap', $html);
        self::assertStringContainsString('not available', $html, 'no Cloudflare location headers');
        self::assertStringContainsString('staff@example.org', $html);
        self::assertStringNotContainsString('{', strip_tags(preg_replace('~<style>.*?</style>~s', '', $html)), 'no unreplaced placeholders');
    }

    public function testRealRenderInATempDirThatIsRemoved(): void
    {
        $t = new TempTree();
        try {
            $v = self::vars(['narrative' => "Long\n\n" . str_repeat('x', 400)]);
            $pdf = (new PdfBuilder('legal', $t->root))->render($v['data'], $v['id'], $v['audit'], ['answers' => $v['answers'], 'files' => $v['files']]);
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertSame([], glob($t->root . '/*'), 'per-render temp dir removed');
        } finally {
            $t->remove();
        }
    }

    public function testSampleScriptWritesOnlyFakeDataToItsOutDir(): void
    {
        $t = new TempTree();
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/render-sample-pdf.php') . ' ' . escapeshellarg('--out=' . $t->root) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $files = glob($t->root . '/*');
            self::assertCount(1, $files);
            self::assertMatchesRegularExpression('~/Legal-Request_\d{8}-\d{4}-[0-9A-Z]{4}_Fakeperson_Sample\.pdf$~', $files[0]);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($files[0]));
        } finally {
            $t->remove();
        }
    }
}
