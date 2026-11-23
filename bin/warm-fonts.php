<?php

/**
 * Pre-build dompdf's font metrics cache in app/storage/fonts.
 * Copied from vea-join's bin/warm-fonts.php (paths and namespace changed).
 *
 * The PHP-FPM pool can read but never write app/, so every font face the PDF
 * templates use must be cached here before deployment. Run after composer
 * install/update (and after changing fonts in app/forms/<form>/templates/pdf.php):
 *
 *   php bin/warm-fonts.php        (part of `composer build`)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use VeaIntake\Core\PdfBuilder;

$fontDir = PdfBuilder::FONT_DIR;
if (!is_dir($fontDir) && !mkdir($fontDir, 0777, true)) {
    fwrite(STDERR, "cannot create $fontDir\n");
    exit(2);
}
$tmp = sys_get_temp_dir() . '/vea-intake-warm-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);

$faces = '';
foreach (['DejaVu Sans', 'DejaVu Sans Mono'] as $family) {
    foreach (['normal', 'bold'] as $weight) {
        foreach (['normal', 'italic'] as $style) {
            $faces .= sprintf('<p style="font-family:\'%s\';font-weight:%s;font-style:%s">%s %s %s: AaZz 0123 &#x2714; &ndash; &#x2022; é ñ ü</p>', $family, $weight, $style, $family, $weight, $style);
        }
    }
}

try {
    $dompdf = new Dompdf(PdfBuilder::options($tmp));
    $dompdf->loadHtml("<!doctype html><html><body>$faces</body></html>", 'UTF-8');
    $dompdf->render();
    $out = $dompdf->output();
} finally {
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}

$files = array_map('basename', glob("$fontDir/*") ?: []);
printf("warm-fonts: OK (%d bytes test PDF); %d file(s) in app/storage/fonts:\n  %s\n", strlen($out), count($files), implode("\n  ", $files));
