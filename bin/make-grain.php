<?php
/**
 * Writes app/shared-assets/img/grain.png: the subtle tiling grain of the page
 * background (Legal theme, --page-bg #7B7B7B). The CSP is img-src 'self', so the
 * texture must be a real file, not a data: URI.
 *
 * 128x128, 8-bit grayscale, each pixel 0x7B plus noise in -3..+3 (uniform, so the
 * average stays #7B7B7B). Reproducible: its own xorshift32 PRNG with a fixed seed
 * and a minimal PNG writer (no GD, no mt_rand), so the bytes depend only on this
 * script and zlib. tests/GrainTest.php checks the committed file equals the output.
 *
 * Usage: php bin/make-grain.php [--root=DIR] [--out=FILE]
 * Exit: 0 OK, 2 cannot write.
 */

declare(strict_types=1);

const SIZE = 128;
const BASE = 0x7B;
const AMPLITUDE = 3;
const SEED = 0x5EA1C0DE;

$opt = getopt('', ['root:', 'out:']);
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');
$out = (string) ($opt['out'] ?? "$root/app/shared-assets/img/grain.png");

$state = SEED;
$next = static function () use (&$state): int {   // xorshift32
    $state ^= ($state << 13) & 0xFFFFFFFF;
    $state ^= $state >> 17;
    $state ^= ($state << 5) & 0xFFFFFFFF;
    return $state;
};

$raw = '';
for ($y = 0; $y < SIZE; $y++) {
    $raw .= "\0";   // filter type 0 (none)
    for ($x = 0; $x < SIZE; $x++) {
        $raw .= chr(BASE + ($next() % (2 * AMPLITUDE + 1)) - AMPLITUDE);
    }
}

$chunk = static fn (string $type, string $data): string =>
    pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

$png = "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', SIZE, SIZE, 8, 0, 0, 0, 0))   // 8-bit grayscale
    . $chunk('IDAT', gzcompress($raw, 9))
    . $chunk('IEND', '');

if (file_put_contents($out, $png) !== strlen($png)) {
    fwrite(STDERR, "make-grain: cannot write $out\n");
    exit(2);
}
printf("make-grain: %s (%d bytes, %dx%d, #%02X%02X%02X ±%d)\n", $out, strlen($png), SIZE, SIZE, BASE, BASE, BASE, AMPLITUDE);
