<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\HttpError;
use VeaIntake\Core\Log;
use VeaIntake\Core\UploadValidator;
use VeaIntake\Tests\Support\TempTree;
use VeaIntake\Tests\Support\UploadFixtures;

/** PLAN section 4 upload rules with real fixture files. */
final class UploadValidatorTest extends TestCase
{
    private const MB = 1024 * 1024;

    private TempTree $t;
    private UploadFixtures $fx;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->t = new TempTree();
        $this->fx = new UploadFixtures($this->t);
        $this->log = [];
        Log::setWriter(function (string $l): void { $this->log[] = $l; });
    }

    protected function tearDown(): void
    {
        Log::setWriter(null);
        $this->t->remove();
    }

    private static function validator(?\Closure $isUploaded = null): UploadValidator
    {
        return new UploadValidator(5, 10 * self::MB, 25 * self::MB, ['docx', 'xlsx', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'txt'], $isUploaded ?? static fn (): bool => true);
    }

    /** @param list<array<string, mixed>> $rows */
    /**
     * One rejected file at $index: 400 with field "files" and per-file detail whose public code
     * matches the internal reason(s) (libmagic may catch a file first), logged with the internal code.
     * @param string|list<string> $reason internal reason code(s)
     */
    private function rejected(array $rows, string|array $reason, ?UploadValidator $v = null, int $index = 0): void
    {
        try {
            ($v ?? self::validator())->validate($rows);
            self::fail('accepted (' . implode('|', (array) $reason) . ')');
        } catch (HttpError $e) {
            self::assertSame(400, $e->status);
            $body = $e->body();
            self::assertSame(['ok', 'error', 'fields', 'files'], array_keys($body));
            self::assertSame(['invalid_input', ['files']], [$body['error'], $body['fields']]);
            self::assertCount(1, $body['files']);
            self::assertSame($index, $body['files'][0]['index']);
            self::assertContains($body['files'][0]['reason'], array_map(static fn (string $r): string => UploadValidator::PUBLIC_REASON[$r], (array) $reason));
        }
        $lines = array_map(static fn (string $r): string => "vea-intake event=upload_rejected status=400 reason=$r fields=files", (array) $reason);
        self::assertContains(end($this->log), $lines);
    }

    /** @return list<array{index: int, reason: string}> */
    private function detail(array $rows): array
    {
        try {
            self::validator()->validate($rows);
        } catch (HttpError $e) {
            return $e->body()['files'] ?? [];
        }
        self::fail('accepted');
    }

    public function testAllowedTypesPass(): void
    {
        $fx = $this->fx;
        $rows = [
            $fx->row($fx->pdf()),
            $fx->row($fx->png()),
            $fx->row($fx->jpg('scan.JPEG')),
            $fx->row($fx->ooxml('letter.docx')),
            $fx->row($fx->ooxml('pay.xlsx', 'xlsx')),
        ];
        $files = self::validator()->validate($rows);
        self::assertSame(['01_evidence.pdf', '02_photo.png', '03_scan.jpeg', '04_letter.docx', '05_pay.xlsx'], array_map(static fn ($f) => $f->safeName, $files));
        self::assertSame(['application/pdf', 'image/png', 'image/jpeg'], array_map(static fn ($f) => $f->mime, array_slice($files, 0, 3)));
        self::assertSame(hash_file('sha256', $fx->path('evidence.pdf')), $files[0]->sha256);
        self::assertSame(filesize($fx->path('photo.png')), $files[1]->size);
        self::assertSame($fx->path('evidence.pdf'), $files[0]->tmpPath, 'never moved');

        $text = self::validator()->validate([
            $fx->row($fx->write('notes.txt', "Plain UTF-8 notes – ok.\n")),
            $fx->row($fx->write('cp1252.txt', "Caf\xE9 \x93quoted\x94\n")),
            $fx->row($fx->write('data.csv', "name,amount\nFake Person,12.50\n")),
        ]);
        self::assertSame(['text/plain', 'text/plain', 'text/csv'], array_map(static fn ($f) => $f->mime, $text));
        self::assertSame([], self::validator()->validate([]));
    }

    public function testOleEmbeddingsAreAllowed(): void
    {
        $fx = $this->fx;
        self::assertCount(1, self::validator()->validate([$fx->row($fx->ooxml('embed.docx', 'docx', ['word/embeddings/oleObject1.bin' => 'fake ole']))]));
    }

    public function testPasswordProtectedPdfIsAcceptedAndFlagged(): void
    {
        $fx = $this->fx;
        $files = self::validator()->validate([$fx->row($fx->encryptedPdf('locked.pdf')), $fx->row($fx->pdf('open.pdf'))]);
        self::assertStringContainsString('/Encrypt', (string) file_get_contents($fx->path('locked.pdf')), 'fixture really is encrypted');
        self::assertSame([true, false], [$files[0]->passwordProtected, $files[1]->passwordProtected]);

        $fx->write('xref-stream.pdf', "%PDF-1.7\n5 0 obj\n<< /Type /XRef /Size 6 /Root 1 0 R /Encrypt << /Filter /Standard /V 2 >> >>\nstream\nendstream\nendobj\n%%EOF\n");
        self::assertTrue(UploadValidator::isEncryptedPdf($fx->path('xref-stream.pdf')), 'inline dictionary in an xref stream');
        $fx->write('mentions.pdf', "%PDF-1.4\n% the word Encrypt in a comment, no dictionary\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        self::assertFalse(UploadValidator::isEncryptedPdf($fx->path('mentions.pdf')));
    }

    public function testRenamedExeAsPdf(): void
    {
        $this->rejected([$this->fx->row($this->fx->exe('invoice.pdf'))], 'mime_mismatch');
    }

    public function testDocmRenamedDocx(): void
    {
        $fx = $this->fx;
        $docm = $fx->ooxml('macro.docx', 'docx', ['word/vbaProject.bin' => 'fake vba'], 'application/vnd.ms-word.document.macroEnabled.main+xml');
        $this->rejected([$fx->row($docm)], 'macro');
    }

    public function testDocxContainingVbaProjectBin(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->ooxml('sneaky.docx', 'docx', ['word/vbaProject.bin' => 'fake vba']))], 'macro');
        $this->rejected([$fx->row($fx->ooxml('sneaky2.xlsx', 'xlsx', ['xl/vbaProject.bin' => 'fake vba']))], 'macro');
    }

    public function testMacroContentTypeAloneAndActiveX(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->ooxml('m.xlsx', 'xlsx', [], 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'))], 'macro');
        $this->rejected([$fx->row($fx->ooxml('ax.docx', 'docx', ['word/activeX/activeX1.xml' => '<ax/>']))], 'macro');
    }

    public function testEncryptedOfficeFile(): void
    {
        $this->rejected([$this->fx->row($this->fx->encryptedOffice('locked.docx'))], 'office_encrypted');
        self::assertSame([['index' => 0, 'reason' => 'encrypted']], $this->detail([$this->fx->row('locked.docx')]));
        // an old-format OLE file without an EncryptedPackage stream is just the wrong type
        $this->fx->write('old.docx', str_pad("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 4096, "\0"));
        $this->rejected([$this->fx->row('old.docx')], 'mime_mismatch');
    }

    public function testZipWithEncryptedEntries(): void
    {
        $fx = $this->fx;
        $name = $fx->ooxml('enc.docx', 'docx', [], null, static function (\ZipArchive $z): void {
            $z->setEncryptionName('word/document.xml', \ZipArchive::EM_AES_256, 'fake-password');
        });
        $this->rejected([$fx->row($name)], 'zip_encrypted');
        self::assertSame('encrypted', UploadValidator::PUBLIC_REASON['zip_encrypted']);
    }

    public function testTinyZipBomb(): void
    {
        $fx = $this->fx;
        $sparse = $this->t->root . '/zeros.bin';
        $h = fopen($sparse, 'w');
        ftruncate($h, 101 * self::MB);
        fclose($h);
        $bomb = $fx->ooxml('bomb.docx', 'docx', [], null, static function (\ZipArchive $z) use ($sparse): void {
            $z->addFile($sparse, 'word/media/zeros.bin');
        });
        self::assertLessThan(self::MB, filesize($fx->path($bomb)), 'small on disk');
        $this->rejected([$fx->row($bomb)], 'zip_bomb');
    }

    public function testTooManyZipEntries(): void
    {
        $fx = $this->fx;
        $many = $fx->ooxml('many.xlsx', 'xlsx', [], null, static function (\ZipArchive $z): void {
            for ($i = 0; $i < 2000; $i++) {
                $z->addFromString("xl/media/p$i.txt", 'x');
            }
        });
        $this->rejected([$fx->row($many)], 'zip_entries');
    }

    public function testOfficeStructure(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->ooxml('wrong.docx', 'xlsx'))], 'mime_mismatch');   // a workbook named .docx: finfo says spreadsheet
        $plainZip = $fx->write('archive.docx', '');
        $z = new \ZipArchive();
        $z->open($fx->path($plainZip), \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $z->addFromString('readme.txt', 'not office');
        $z->close();
        $this->rejected([$fx->row($plainZip)], 'ooxml_structure');
    }

    public function testSixFiles(): void
    {
        $fx = $this->fx;
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = $fx->row($fx->pdf("f$i.pdf"));
        }
        $this->rejected($rows, 'too_many', null, 5);
        self::assertSame([['index' => 5, 'reason' => 'too_many']], $this->detail($rows), 'only the 6th file is flagged');
    }

    public function testOverTenMegabytes(): void
    {
        $fx = $this->fx;
        self::assertCount(1, self::validator()->validate([$fx->row($fx->bigPdf('ten.pdf', 10 * self::MB))]), 'exactly 10 MB is fine');
        $this->rejected([$fx->row($fx->bigPdf('big.pdf', 10 * self::MB + 1))], 'too_big');
        $this->rejected([$fx->row('x.pdf', 'x.pdf', UPLOAD_ERR_INI_SIZE)], 'too_big');
    }

    public function testOverTwentyFiveMegabytesTotal(): void
    {
        $fx = $this->fx;
        $rows = [$fx->row($fx->bigPdf('a.pdf', 9 * self::MB)), $fx->row($fx->bigPdf('b.pdf', 9 * self::MB)), $fx->row($fx->bigPdf('c.pdf', 7 * self::MB))];
        self::assertCount(3, self::validator()->validate($rows), 'exactly 25 MB is fine');
        $rows[] = $fx->row($fx->pdf('d.pdf'));
        $this->rejected($rows, 'total_too_big', null, 3);
    }

    public function testNulInCsvAndBadEncoding(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->write('nul.csv', "a,b\n1,\0\n"))], ['mime_mismatch', 'text_nul']);   // libmagic: binary
        $this->rejected([$fx->row($fx->write('bad.txt', "abc \x81\x8D def\n"))], 'text_encoding');
    }

    public function testMagicAndTypeMismatches(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->png('photo.jpg'))], 'mime_mismatch');
        $this->rejected([$fx->row($fx->write('fake.png', "\x89PNG\r\n\x1a\nnot really"))], ['mime_mismatch', 'image_invalid']);
        $this->rejected([$fx->row($fx->write('notes.pdf', "Just text, not a PDF.\n"))], 'mime_mismatch');
        $this->rejected([$fx->row($fx->pdf('script.exe'))], 'extension');
        $this->rejected([$fx->row($fx->pdf('noext'))], 'extension');
        $this->rejected([$fx->row($fx->write('empty.txt', ''))], 'empty');
    }

    public function testMustBeAnUploadedFile(): void
    {
        $fx = $this->fx;
        $this->rejected([$fx->row($fx->pdf())], 'not_uploaded', self::validator(static fn (): bool => false));
        $this->rejected([$fx->row($fx->pdf())], 'not_uploaded', new UploadValidator(5, 10 * self::MB, 25 * self::MB));   // real is_uploaded_file
        $this->rejected([$fx->row('evidence.pdf', null, UPLOAD_ERR_PARTIAL)], 'upload_error');
    }

    public function testNamesNeverReachTheLog(): void
    {
        $this->rejected([$this->fx->row($this->fx->exe('Secret Member Name.pdf'))], 'mime_mismatch');
        self::assertStringNotContainsString('Secret', implode("\n", $this->log));
    }

    public function testEveryFileIsCheckedAndReportedByIndexOnly(): void
    {
        $fx = $this->fx;
        $rows = [
            $fx->row($fx->pdf('fine.pdf')),
            $fx->row($fx->exe('Secret Member Name.pdf')),
            $fx->row($fx->ooxml('Macro Plan.docx', 'docx', ['word/vbaProject.bin' => 'x'])),
            $fx->row($fx->png()),
            $fx->row($fx->write('blank.txt', '')),
        ];
        try {
            self::validator()->validate($rows);
            self::fail('accepted');
        } catch (HttpError $e) {
            self::assertSame(['ok' => false, 'error' => 'invalid_input', 'fields' => ['files'], 'files' => [
                ['index' => 1, 'reason' => 'mime_mismatch'],
                ['index' => 2, 'reason' => 'macro'],
                ['index' => 4, 'reason' => 'empty'],
            ]], $e->body());
            $json = json_encode($e->body());
            foreach (['Secret', 'Macro Plan', 'fine', 'blank', '.pdf', '.docx'] as $needle) {
                self::assertStringNotContainsString($needle, $json, 'never a file name');
            }
        }
        self::assertCount(3, $this->log, 'one log line per rejected file');
    }

    public function testPublicReasonCodes(): void
    {
        $codes = array_values(array_unique(UploadValidator::PUBLIC_REASON));
        sort($codes);
        self::assertSame(['empty', 'encrypted', 'macro', 'mime_mismatch', 'not_allowed', 'not_office', 'nul_bytes', 'too_large', 'too_many',
            'total_too_large', 'upload_failed', 'zip_limits'], $codes);
    }

    public function testSafeNames(): void
    {
        self::assertSame('01_Resume_final.pdf', UploadValidator::safeName('Résumé (final).PDF', 1));
        self::assertSame('02_evil.pdf', UploadValidator::safeName('../../etc/evil.pdf', 2));
        self::assertSame('03_file.txt', UploadValidator::safeName('???.txt', 3));
        self::assertSame('04_a_b.csv', UploadValidator::safeName("a\u{202E}--b.csv", 4));
        $long = UploadValidator::safeName(str_repeat('x', 300) . '.docx', 5);
        self::assertSame(100, strlen($long));
        self::assertStringStartsWith('05_xxx', $long);
        self::assertStringEndsWith('.docx', $long);
        self::assertSame('photo.png', UploadValidator::originalName('C:\\Users\\fake\\photo.png'));
    }
}
