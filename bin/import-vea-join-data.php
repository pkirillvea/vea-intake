<?php

/**
 * Import the locals and worksites from vea-join's source data (docs/PLAN.md section 13).
 *
 * vea-join has no locals.json: its list of locals is the distinct `local` values
 * of app/data/source/dues.json. This script READS (never writes) vea-join's
 *   dues.json       → app/data/source/locals.json     (sorted unique names only; no dues figures)
 *   worksites.json  → app/data/source/worksites.json  (copied byte for byte)
 * and prints the SHA-256 of both vea-join files, for the record.
 *
 * Annual refresh: update vea-join's source data first, then run this, then
 * `composer build`, and commit app/data/source/.
 *
 * Usage: php bin/import-vea-join-data.php [--from=/var/www/vea-join/app/data/source] [--root=DIR]
 * Exit: 0 OK, 1 invalid source data (nothing written), 2 cannot read/write.
 */

declare(strict_types=1);

$opt = getopt('', ['from:', 'root:']);
$from = rtrim((string) ($opt['from'] ?? '/var/www/vea-join/app/data/source'), '/');
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');
$dest = "$root/app/data/source";

function fatal(string $msg, int $code): never
{
    fwrite(STDERR, "import-vea-join-data: $msg\n");
    exit($code);
}

function read_json(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fatal("cannot read $path", 2);
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fatal("invalid JSON in $path: {$e->getMessage()}", 1);
    }
    if (!is_array($data) || !array_is_list($data) || $data === []) {
        fatal("$path must be a non-empty JSON list", 1);
    }

    return [$raw, $data];
}

/** Write via a temp file in the same directory + rename (no chmod: the default ACL sets 0640). */
function write_atomic(string $target, string $content): void
{
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fatal("cannot create $dir", 2);
    }
    $tmp = $dir . '/.' . basename($target) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $content) !== strlen($content) || !rename($tmp, $target)) {
        @unlink($tmp);
        fatal("cannot write $target", 2);
    }
}

[$duesRaw, $dues] = read_json("$from/dues.json");
[$sitesRaw, $sites] = read_json("$from/worksites.json");

$locals = [];
foreach ($dues as $i => $row) {
    $name = is_array($row) ? ($row['local'] ?? null) : null;
    if (!is_string($name) || trim($name) === '') {
        fatal("dues.json row $i: missing local", 1);
    }
    $locals[$name] = true;
}
$locals = array_keys($locals);
usort($locals, 'strcmp');

foreach ($sites as $i => $row) {
    if (!is_array($row) || !is_string($row['local'] ?? null) || !is_string($row['worksite'] ?? null)) {
        fatal("worksites.json row $i: needs string local and worksite", 1);
    }
}

write_atomic("$dest/locals.json", json_encode($locals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
write_atomic("$dest/worksites.json", $sitesRaw);

printf("import-vea-join-data: OK. %d locals, %d worksite rows\n", count($locals), count($sites));
printf("  from %s (read-only)\n", $from);
printf("    dues.json       sha256 %s\n", hash('sha256', $duesRaw));
printf("    worksites.json  sha256 %s\n", hash('sha256', $sitesRaw));
printf("  wrote app/data/source/locals.json, app/data/source/worksites.json\n");
exit(0);
