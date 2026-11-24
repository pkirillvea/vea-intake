<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Upload checks (docs/PLAN.md section 4; docs/PAYLOAD.md section 4). Every file is
 * checked; any failure rejects the whole submission: 400 invalid_input, field
 * "files", plus per-file detail [{index, reason}] with a public reason code
 * (PUBLIC_REASON; the browser maps it to text and knows the file by its index).
 * Never a file name. Logged as upload_rejected with the internal reason code.
 *
 * - count <= max; UPLOAD_ERR_OK and is_uploaded_file; size measured on disk,
 *   1 byte .. max per file, total <= max.
 * - extension allowlist (content.json limits.extensions) AND the finfo MIME type
 *   must be in that extension's family; then a structure check per type:
 *   - docx/xlsx: a ZIP ("PK\3\4") with [Content_Types].xml and the main part
 *     (word/document.xml or xl/workbook.xml); <= 2,000 entries and <= 100 MB
 *     uncompressed; no encrypted entries; no VBA (vbaProject.bin, vbaData.xml,
 *     macroEnabled / vbaProject content types) and no ActiveX parts. Embedded OLE
 *     objects are allowed. Encrypted Office files are OLE containers, not ZIPs,
 *     so finfo already rejects them.
 *   - pdf: "%PDF-" at offset 0. png/jpg: magic bytes + getimagesize type.
 *   - csv/txt: no NUL bytes; valid UTF-8, or Windows-1252 without its 5 unused bytes.
 * - SHA-256 of each file; attachment names 01_..05_ + [A-Za-z0-9._-], <= 100 chars.
 *
 * Files stay where PHP put them (/run/vea-intake); never move_uploaded_file.
 */
final class UploadValidator
{
    public const MAX_ZIP_ENTRIES = 2000;
    public const MAX_ZIP_UNCOMPRESSED = 100 * 1024 * 1024;
    private const MAX_CONTENT_TYPES = 1024 * 1024;

    /** finfo MIME types accepted per extension. */
    public const MIME = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    /** Content type sent with the attachment (by extension, never the browser's). */
    public const CONTENT_TYPE = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const OOXML_MAIN = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'];

    /** Internal reason (logged) => public reason code (in the 400 response; docs/PAYLOAD.md section 4). */
    public const PUBLIC_REASON = [
        'too_many' => 'too_many',
        'too_big' => 'too_large',
        'total_too_big' => 'total_too_large',
        'empty' => 'empty',
        'upload_error' => 'upload_failed',
        'not_uploaded' => 'upload_failed',
        'extension' => 'not_allowed',
        'mime_mismatch' => 'mime_mismatch',
        'pdf_magic' => 'mime_mismatch',
        'image_magic' => 'mime_mismatch',
        'image_invalid' => 'mime_mismatch',
        'text_encoding' => 'mime_mismatch',
        'office_encrypted' => 'encrypted',
        'zip_encrypted' => 'encrypted',
        'zip_magic' => 'not_office',
        'zip_invalid' => 'not_office',
        'ooxml_structure' => 'not_office',
        'zip_entries' => 'zip_limits',
        'zip_bomb' => 'zip_limits',
        'macro' => 'macro',
        'text_nul' => 'nul_bytes',
    ];

    /** finfo types of OLE compound files: encrypted (password-protected) Office files, or old .doc/.xls. */
    private const OLE_MIME = ['application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-encrypted', 'application/encrypted',
        'application/vnd.ms-office', 'application/msword', 'application/vnd.ms-excel'];

    private readonly \Closure $isUploaded;

    /** @param list<string> $extensions content.json limits.extensions */
    public function __construct(
        private readonly int $maxFiles,
        private readonly int $maxFileBytes,
        private readonly int $maxTotalBytes,
        private readonly array $extensions = ['docx', 'xlsx', 'csv', 'pdf', 'png', 'jpg', 'jpeg', 'txt'],
        ?\Closure $isUploaded = null,
    ) {
        $this->isUploaded = $isUploaded ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    public static function fromLimits(FormLimits $l, ?\Closure $isUploaded = null): self
    {
        return new self($l->maxFiles(), $l->maxFileBytes(), $l->maxTotalBytes(), $l->extensions, $isUploaded);
    }

    /**
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files from Http::readMultipart
     * @return list<UploadedFile>
     * @throws HttpError 400 invalid_input, field "files"
     */
    public function validate(array $files): array
    {
        $errors = [];   // index => internal reason
        $ok = [];       // index => UploadedFile
        foreach (array_values($files) as $i => $f) {
            if ($i >= $this->maxFiles) {
                $errors[$i] = 'too_many';
                continue;
            }
            $result = $this->check($f, $i);
            if (is_string($result)) {
                $errors[$i] = $result;
            } else {
                $ok[$i] = $result;
            }
        }
        // Total: in order, a file that would take the accepted ones over the limit is flagged
        // (and left out of the running total, so removing it is enough).
        $total = 0;
        foreach ($ok as $i => $file) {
            if ($total + $file->size > $this->maxTotalBytes) {
                $errors[$i] = 'total_too_big';
                unset($ok[$i]);
                continue;
            }
            $total += $file->size;
        }
        if ($errors !== []) {
            ksort($errors);
            $detail = [];
            foreach ($errors as $i => $reason) {
                Log::event('upload_rejected', ['status' => 400, 'reason' => $reason, 'fields' => 'files']);
                $detail[] = ['index' => $i, 'reason' => self::PUBLIC_REASON[$reason]];
            }
            throw new HttpError(400, HttpError::INVALID_INPUT, ['files'], $detail);
        }

        return array_values($ok);
    }

    /** @return UploadedFile|string the file, or the internal reason it failed */
    private function check(array $f, int $i): UploadedFile|string
    {
        $error = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'too_big';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'upload_error';
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !($this->isUploaded)($tmp) || !is_file($tmp)) {
            return 'not_uploaded';
        }
        clearstatcache(true, $tmp);
        $size = (int) filesize($tmp);
        if ($size < 1) {
            return 'empty';
        }
        if ($size > $this->maxFileBytes) {
            return 'too_big';
        }

        $original = self::originalName((string) ($f['name'] ?? ''));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->extensions, true) || !isset(self::MIME[$ext])) {
            return 'extension';
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::MIME[$ext], true)) {
            return isset(self::OOXML_MAIN[$ext]) && in_array($mime, self::OLE_MIME, true) && self::isEncryptedOle($tmp)
                ? 'office_encrypted' : 'mime_mismatch';
        }
        $problem = match ($ext) {
            'docx', 'xlsx' => self::checkOoxml($tmp, self::OOXML_MAIN[$ext]),
            'pdf' => self::head($tmp, 5) === '%PDF-' ? null : 'pdf_magic',
            'png' => self::checkImage($tmp, "\x89PNG\r\n\x1a\n", IMAGETYPE_PNG),
            'jpg', 'jpeg' => self::checkImage($tmp, "\xFF\xD8\xFF", IMAGETYPE_JPEG),
            'csv', 'txt' => self::checkText($tmp),
        };
        if ($problem !== null) {
            return $problem;
        }

        return new UploadedFile(
            originalName: $original,
            safeName: self::safeName($original, $i + 1),
            size: $size,
            extension: $ext,
            mime: self::CONTENT_TYPE[$ext],
            sha256: (string) hash_file('sha256', $tmp),
            tmpPath: $tmp,
        );
    }

    /** A password-protected Office file is an OLE container with an "EncryptedPackage" stream. */
    private static function isEncryptedOle(string $path): bool
    {
        $s = (string) file_get_contents($path);

        return str_contains($s, mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'UTF-8'));
    }

    /** "01_" .. "05_" + the name reduced to [A-Za-z0-9._-], at most 100 characters, extension kept. */
    public static function safeName(string $name, int $index): string
    {
        $name = self::originalName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
        if (class_exists(\Transliterator::class)) {
            $base = (string) \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($base);
        }
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim((string) preg_replace('/[._-]{2,}/', '_', $base), '._-');
        $ext = (string) preg_replace('/[^a-z0-9]/', '', $ext);
        $prefix = sprintf('%02d_', $index);
        $suffix = $ext === '' ? '' : ".$ext";
        $base = substr($base === '' ? 'file' : $base, 0, max(1, 100 - strlen($prefix) - strlen($suffix)));

        return $prefix . rtrim($base, '._-') . $suffix;
    }

    /** The browser's file name, for display only: last path segment, valid UTF-8, no control characters, <= 255 chars. */
    public static function originalName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace(Validator::CONTROL, '', $name));
        $name = $name === '' ? 'file' : $name;
        if (mb_strlen($name, 'UTF-8') > 255) {   // shorten the base name, keep the extension
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $ext = $ext !== '' && strlen($ext) <= 10 ? ".$ext" : '';
            $name = mb_substr(mb_substr($name, 0, mb_strlen($name, 'UTF-8') - strlen($ext), 'UTF-8'), 0, 255 - strlen($ext), 'UTF-8') . $ext;
        }

        return $name;
    }

    private static function checkOoxml(string $path, string $mainPart): ?string
    {
        if (self::head($path, 4) !== "PK\x03\x04") {
            return 'zip_magic';
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return 'zip_invalid';
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                return 'zip_entries';
            }
            $uncompressed = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
                if ($st === false) {
                    return 'zip_invalid';
                }
                if (($st['encryption_method'] ?? 0) !== 0) {
                    return 'zip_encrypted';
                }
                $uncompressed += (int) $st['size'];
                if ($uncompressed > self::MAX_ZIP_UNCOMPRESSED) {
                    return 'zip_bomb';
                }
                $names[] = strtolower((string) $st['name']);
            }
            foreach ($names as $n) {
                if (preg_match('~(^|/)(vbaproject\.bin|vbadata\.xml)$~', $n) || preg_match('~(^|/)activex/~', $n)) {
                    return 'macro';
                }
            }
            if (!in_array('[content_types].xml', $names, true) || !in_array($mainPart, $names, true)) {
                return 'ooxml_structure';
            }
            $idx = $zip->locateName('[Content_Types].xml', \ZipArchive::FL_NOCASE);
            $st = $idx === false ? false : $zip->statIndex($idx);
            if ($st === false || $st['size'] > self::MAX_CONTENT_TYPES) {
                return 'ooxml_structure';
            }
            $types = $zip->getFromIndex($idx, self::MAX_CONTENT_TYPES);
            if (!is_string($types)) {
                return 'ooxml_structure';
            }
            if (preg_match('/macroEnabled|vbaProject|vnd\.ms-office\.activeX|vbaData/i', $types)) {
                return 'macro';
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private static function checkImage(string $path, string $magic, int $type): ?string
    {
        if (self::head($path, strlen($magic)) !== $magic) {
            return 'image_magic';
        }
        $info = @getimagesize($path);

        return is_array($info) && $info[2] === $type ? null : 'image_invalid';
    }

    private static function checkText(string $path): ?string
    {
        $s = (string) file_get_contents($path);
        if (str_contains($s, "\0")) {
            return 'text_nul';
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return null;
        }

        return preg_match('/[\x81\x8D\x8F\x90\x9D]/', $s) ? 'text_encoding' : null;   // bytes Windows-1252 leaves unused
    }

    private static function head(string $path, int $n): string
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return '';
        }
        $s = (string) fread($h, $n);
        fclose($h);

        return $s;
    }

}
