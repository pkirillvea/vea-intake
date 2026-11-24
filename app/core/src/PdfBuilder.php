<?php

declare(strict_types=1);

namespace VeaIntake\Core;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Builds a form's PDF in memory (docs/PLAN.md sections 3, 11).
 * Copied from vea-join's PdfBuilder and generalized: the form (templates and
 * texts), the logo and the temp base are constructor arguments. The header AND
 * the footer of every page carry the form's banner (content.json pdf.banner, the
 * privilege marking); the footer also has the submission ID and "Page X of Y".
 *
 * dompdf decodes each data: image into a temporary file in tempDir and deletes
 * it at the end of render(). Each call gets its own directory under
 * /run/vea-intake (tmpfs), which is removed in `finally` whatever happens.
 */
final class PdfBuilder
{
    public const APP_DIR = __DIR__ . '/../..';
    public const FONT_DIR = self::APP_DIR . '/storage/fonts';
    public const LOGO = self::APP_DIR . '/shared-assets/img/vea-logo-white.png';
    public const BANNER_RGB = [0.008, 0.243, 0.369];   // --banner-fg #023E5E

    public function __construct(
        private readonly string $form,
        private readonly string $tempBase = '/run/vea-intake',
        private readonly string $logo = self::LOGO,
    ) {
    }

    /**
     * @param array<string, mixed> $data  validated data from the form's validator
     * @param array<string, mixed> $audit the audit block (Audit::build)
     * @param array<string, mixed> $extra more template variables (e.g. answers, files)
     * @return string PDF bytes
     */
    public function render(array $data, string $id, array $audit, array $extra = []): string
    {
        $c = Template::content($this->form);
        $html = Template::render($this->form, 'pdf', [
            'data' => $data,
            'id' => $id,
            'audit' => $audit,
            'logo' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->logo)),
            'c' => $c,
        ] + $extra);

        $tmp = $this->tempBase . '/pdf-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700)) {
            throw new RuntimeException('cannot create PDF temp directory');
        }
        try {
            $dompdf = new Dompdf(self::options($tmp));
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();

            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $bold = $metrics->getFont('DejaVu Sans', 'bold');
            $font = $metrics->getFont('DejaVu Sans');
            [$w, $h] = [$canvas->get_width(), $canvas->get_height()];
            $banner = (string) ($c['pdf']['banner'] ?? '');
            $page = (string) ($c['pdf']['page'] ?? 'Page {page} of {pages}');
            $label = Template::fmt($page, ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
            $sample = Template::fmt($page, ['page' => '88', 'pages' => '88']);
            if ($banner !== '') {
                $bw = $metrics->getTextWidth($banner, $bold, 7.5);
                $canvas->page_text(($w - $bw) / 2, 20, $banner, $bold, 7.5, self::BANNER_RGB);       // header
                $canvas->page_text(($w - $bw) / 2, $h - 40, $banner, $bold, 7.5, self::BANNER_RGB);  // footer
            }
            $canvas->page_text(40, $h - 28, $id, $font, 7.5, [0.33, 0.33, 0.33]);
            $canvas->page_text($w - 40 - $metrics->getTextWidth($sample, $font, 7.5), $h - 28, $label, $font, 7.5, [0.33, 0.33, 0.33]);

            $pdf = (string) $dompdf->output();
        } finally {
            self::removeDir($tmp);
        }
        if (!str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('dompdf produced no PDF');
        }

        return $pdf;
    }

    public static function options(string $tempDir): Options
    {
        $o = new Options();
        $o->setIsRemoteEnabled(false);
        $o->setIsPhpEnabled(false);
        $o->setIsJavascriptEnabled(false);
        $o->setAllowedProtocols(['data://']);           // images are data: URIs only
        $o->setChroot([(string) realpath(self::APP_DIR)]);
        $o->setTempDir($tempDir);
        $o->setFontDir((string) realpath(self::FONT_DIR));
        $o->setFontCache((string) realpath(self::FONT_DIR));
        $o->setDefaultFont('DejaVu Sans');
        $o->setDefaultPaperSize('letter');
        $o->setIsFontSubsettingEnabled(true);
        $o->setDebugKeepTemp(false);

        return $o;
    }

    /** <Prefix>_<ID>_<Last>_<First>.pdf, sanitized to [A-Za-z0-9-] (Legal: prefix "Legal-Request"). */
    public static function filename(string $prefix, string $id, string $last, string $first): string
    {
        $clean = static function (string $s): string {
            $ascii = class_exists(\Transliterator::class)
                ? (string) \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($s)
                : $s;

            return substr((string) preg_replace('/[^A-Za-z0-9-]+/', '', str_replace(' ', '-', $ascii)), 0, 40);
        };

        return sprintf('%s_%s_%s_%s.pdf', $clean($prefix) ?: 'Request', $clean($id), $clean($last) ?: 'X', $clean($first) ?: 'X');
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_merge(glob($dir . '/*') ?: [], glob($dir . '/.[!.]*') ?: []) as $f) {
            is_dir($f) ? self::removeDir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
