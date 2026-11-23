<?php

/**
 * Build form-data.json from the source data (docs/PLAN.md section 13).
 * Modelled on vea-join's bin/build-data.php (same error/warning style and exit codes).
 *
 * Reads:   app/data/source/locals.json     (list of local names)
 *          app/data/source/worksites.json  (list of {local, worksite})
 * Writes:  app/data/form-data.json and public/legal/data/form-data.json (identical)
 *
 * "OTHER / NOT LISTED" is stored in the data (PLAN 1.4): it is the last worksite
 * of every local, and the last local (with only itself as worksite). A real
 * source value equal to it is an error. Duplicates are removed with a warning.
 *
 * Prints every warning and error. Exits 1 on any error (nothing is written),
 * 2 if a file cannot be read or written, 0 otherwise (warnings allowed).
 *
 * Usage: php bin/build-data.php [--quiet] [--root=DIR]
 */

declare(strict_types=1);

const NOT_LISTED = 'OTHER / NOT LISTED';
const FORMS = ['legal'];

// US states + DC (Virginia is the form default). Same list as vea-join.
const STATES = [
    'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
    'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois',
    'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
    'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
    'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota',
    'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina',
    'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
    'West Virginia', 'Wisconsin', 'Wyoming',
];

$opt = getopt('', ['quiet', 'root:']);
$quiet = isset($opt['quiet']);
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');

$errors = [];
$warnings = [];
$error = static function (string $msg) use (&$errors): void { $errors[] = $msg; };
$warn = static function (string $msg) use (&$warnings): void { $warnings[] = $msg; };
$print = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

/** @return mixed */
function load_json(string $path)
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "FATAL: cannot read $path\n");
        exit(2);
    }
    try {
        return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "FATAL: invalid JSON in $path: {$e->getMessage()}\n");
        exit(2);
    }
}

function q(string $s): string
{
    return '"' . $s . '"';
}

function is_not_listed(string $s): bool
{
    return strcasecmp(trim($s), NOT_LISTED) === 0;
}

$src = "$root/app/data/source";
$localsRaw = load_json("$src/locals.json");
$worksitesRaw = load_json("$src/worksites.json");

// ------------------------------------------------------------------ locals
$locals = [];   // name => true, in source order
if (!is_array($localsRaw) || !array_is_list($localsRaw) || $localsRaw === []) {
    $error('locals.json: must be a non-empty list of names');
    $localsRaw = [];
}
foreach ($localsRaw as $i => $name) {
    if (!is_string($name) || trim($name) === '') {
        $error("locals.json[$i]: must be a non-empty string");
        continue;
    }
    if ($name !== trim($name)) {
        $warn('locals.json[' . $i . ']: ' . q($name) . ' has surrounding spaces (trimmed)');
        $name = trim($name);
    }
    if (is_not_listed($name)) {
        $error('locals.json[' . $i . ']: ' . q($name) . ' is reserved (' . NOT_LISTED . ' is added by the build)');
        continue;
    }
    if (isset($locals[$name])) {
        $warn('locals.json: duplicate local ' . q($name) . ' (removed)');
        continue;
    }
    $locals[$name] = true;
}

// --------------------------------------------------------------- worksites
/** @var array<string, list<string>> $worksites */
$worksites = [];
if (!is_array($worksitesRaw) || !array_is_list($worksitesRaw)) {
    $error('worksites.json: must be a list of rows');
    $worksitesRaw = [];
}
foreach ($worksitesRaw as $i => $row) {
    $local = is_array($row) ? ($row['local'] ?? null) : null;
    $site = is_array($row) ? ($row['worksite'] ?? null) : null;
    if (!is_string($local) || !is_string($site) || trim($site) === '') {
        $error("worksites.json row $i: needs string local and non-empty worksite");
        continue;
    }
    $local = trim($local);
    if ($site !== trim($site)) {
        $warn('worksites ' . q($local) . ': ' . q($site) . ' has surrounding spaces (trimmed)');
        $site = trim($site);
    }
    if (!isset($locals[$local])) {
        $error('worksites.json row ' . $i . ': local ' . q($local) . ' is not in locals.json');
        continue;
    }
    if (is_not_listed($site)) {
        $error('worksites.json row ' . $i . ': worksite ' . q($site) . ' is reserved (' . NOT_LISTED . ' is added by the build)');
        continue;
    }
    if (in_array($site, $worksites[$local] ?? [], true)) {
        $warn('worksites ' . q($local) . ': duplicate worksite ' . q($site) . ' (removed)');
        continue;
    }
    $worksites[$local][] = $site;
}

foreach (array_keys($locals) as $local) {
    if (!isset($worksites[$local])) {
        $warn('worksites ' . q($local) . ': no worksites (form offers only "' . NOT_LISTED . '")');
    }
}
foreach ($warnings as $w) {
    $print("WARNING: $w");
}
foreach ($errors as $e) {
    fwrite(STDERR, "ERROR: $e\n");
}
if ($errors !== []) {
    fwrite(STDERR, sprintf("build-data: %d error(s), %d warning(s). Nothing written.\n", count($errors), count($warnings)));
    exit(1);
}

// ------------------------------------------------------------------- build
$names = array_keys($locals);
usort($names, 'strcmp');
$out = [];
foreach ($names as $local) {
    $sites = $worksites[$local] ?? [];
    usort($sites, 'strnatcasecmp');
    $sites[] = NOT_LISTED;
    $out[] = ['name' => $local, 'worksites' => $sites];
}
$out[] = ['name' => NOT_LISTED, 'worksites' => [NOT_LISTED]];

$body = ['notListed' => NOT_LISTED, 'locals' => $out, 'states' => STATES];
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
// version = content hash, so client and server can detect a stale copy
$data = ['version' => substr(hash('sha256', json_encode($body, $flags)), 0, 16)] + $body;
$json = json_encode($data, $flags) . "\n";

$targets = ["$root/app/data/form-data.json"];
foreach (FORMS as $form) {
    $targets[] = "$root/public/$form/data/form-data.json";
}
foreach ($targets as $target) {
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fwrite(STDERR, "FATAL: cannot create $dir\n");
        exit(2);
    }
    // temp file + rename; no chmod: the default ACL gives 0640 (and www-data read under public/)
    $tmp = $dir . '/.form-data.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $target)) {
        @unlink($tmp);
        fwrite(STDERR, "FATAL: cannot write $target\n");
        exit(2);
    }
}

$siteCount = array_sum(array_map(static fn (array $l): int => count($l['worksites']) - 1, $out));
$print(sprintf(
    'build-data: OK. %d locals (+ "%s"), %d worksites, %d states, %d warning(s). version %s',
    count($names), NOT_LISTED, $siteCount, count(STATES), count($warnings), $data['version'],
));
foreach ($targets as $t) {
    $print('  wrote ' . substr($t, strlen($root) + 1));
}
exit(0);
