<?php

declare(strict_types=1);

namespace VeaIntake\Tests\Support;

/** Builds real upload fixture files (FAKE content) in a TempTree; nothing binary is committed. */
final class UploadFixtures
{
    public function __construct(private readonly TempTree $t)
    {
    }

    public function path(string $name): string
    {
        return $this->t->root . '/up/' . $name;
    }

    /** One $_FILES row (Http::readMultipart shape). */
    public function row(string $file, ?string $clientName = null, int $error = UPLOAD_ERR_OK): array
    {
        $p = $this->path($file);

        return ['name' => $clientName ?? $file, 'type' => 'application/octet-stream', 'tmp_name' => $p, 'error' => $error, 'size' => is_file($p) ? (int) filesize($p) : 0];
    }

    public function write(string $name, string $bytes): string
    {
        $this->t->write("up/$name", $bytes);

        return $name;
    }

    /** A file of $bytes zero bytes with a valid PDF header (sparse: no disk or memory cost). */
    public function bigPdf(string $name, int $bytes): string
    {
        $this->write($name, "%PDF-1.4\n");
        $h = fopen($this->path($name), 'r+');
        ftruncate($h, $bytes);
        fclose($h);

        return $name;
    }

    public function pdf(string $name = 'evidence.pdf'): string
    {
        return $this->write($name, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    public function png(string $name = 'photo.png'): string
    {
        $i = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($i);

        return $this->write($name, (string) ob_get_clean());
    }

    public function jpg(string $name = 'photo.jpg'): string
    {
        $i = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($i);

        return $this->write($name, (string) ob_get_clean());
    }

    /**
     * A minimal OOXML package.
     * @param array<string, string> $extra more parts
     */
    public function ooxml(string $name, string $kind = 'docx', array $extra = [], ?string $mainType = null, ?\Closure $tweak = null): string
    {
        [$main, $type] = $kind === 'docx'
            ? ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml']
            : ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'];
        $this->write($name, '');
        $z = new \ZipArchive();
        $z->open($this->path($name), \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Override PartName="/' . $main . '" ContentType="' . ($mainType ?? $type) . '"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $z->addFromString($main, '<?xml version="1.0"?><root>fake content</root>');
        foreach ($extra as $part => $bytes) {
            $z->addFromString($part, $bytes);
        }
        if ($tweak !== null) {
            $tweak($z);
        }
        $z->close();

        return $name;
    }

    /** An encrypted Office document: an OLE compound file, whatever its extension says. */
    public function encryptedOffice(string $name = 'locked.docx'): string
    {
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16) . "\x3E\x00\x03\x00\xFE\xFF\x09\x00\x06\x00";

        return $this->write($name, str_pad($header, 512, "\0") . mb_convert_encoding('EncryptedPackage', 'UTF-16LE') . str_repeat("\0", 4096));
    }

    public function exe(string $name = 'invoice.pdf'): string
    {
        return $this->write($name, "MZ\x90\0\x03\0\0\0\x04\0\0\0\xFF\xFF\0\0\xB8" . str_repeat("\0", 47) . "\x80\0\0\0" . str_repeat("\0", 64) . "PE\0\0L\x01");
    }
}
