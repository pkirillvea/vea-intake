<?php

/**
 * Build public/<form>/index.html from app/forms/<form>/templates/index.html
 * (docs/PLAN.md section 13). Modelled on vea-join's bin/build-site.php.
 *
 * Placeholders:
 *   {{t:path.to.key}}  content.json string (or number, e.g. a maxlength), HTML-escaped
 *   {{json:g1 g2 ...}} the named top-level content.json groups as JSON, HTML-escaped
 *                      (for a data- attribute read by the form's JS, as vea-join)
 *   {{asset:path}}     URL from app/data/assets-<form>.json (run build-assets first),
 *                      e.g. /legal/assets/css/app.css?v=<first 12 hex of SHA-256>
 *   {{dataurl}}        /<form>/data/form-data.json?v=<form-data version> (run build-data first)
 * Any unknown placeholder, missing key or missing asset is an error; nothing is written.
 *
 * Unlike vea-join's, this never reads app.env (developer cannot, by design): the
 * form version is in content.json, and there are no public keys to inject.
 *
 * Usage: php bin/build-site.php --form=legal [--quiet] [--root=DIR]
 * Exit: 0 OK, 1 template error, 2 cannot read/write.
 */

declare(strict_types=1);

$opt = getopt('', ['form:', 'quiet', 'root:']);
$form = (string) ($opt['form'] ?? '');
$quiet = isset($opt['quiet']);
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "build-site: $msg\n");
    exit($code);
}

function read_json(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fail("cannot read $path", 2);
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail("invalid JSON in $path: {$e->getMessage()}", 2);
    }

    return is_array($data) ? $data : fail("$path is not a JSON object", 2);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/', $form) || !is_dir("$root/app/forms/$form")) {
    fail('--form must name a folder in app/forms/');
}
$templateFile = "$root/app/forms/$form/templates/index.html";
$template = @file_get_contents($templateFile);
if ($template === false) {
    fail("cannot read $templateFile", 2);
}
$content = read_json("$root/app/forms/$form/content.json");
$assets = read_json("$root/app/data/assets-$form.json")['files'] ?? fail("app/data/assets-$form.json has no files (run build-assets)", 2);
$formData = read_json("$root/app/data/form-data.json");
if (!is_string($formData['version'] ?? null)) {
    fail('app/data/form-data.json has no version (run build-data)', 2);
}

$errors = [];
$used = [];
$html = preg_replace_callback('/\{\{([^{}]*)\}\}/', static function (array $m) use ($content, $assets, $formData, $form, &$errors, &$used): string {
    $ph = trim($m[1]);
    if (str_starts_with($ph, 't:')) {
        $value = $content;
        foreach (explode('.', substr($ph, 2)) as $k) {
            $value = is_array($value) && array_key_exists($k, $value) ? $value[$k] : null;
        }
        if (is_int($value) || is_float($value)) {
            return h((string) $value);
        }
        if (!is_string($value)) {
            $errors[] = "{{{$ph}}}: no such string in content.json";
            return '';
        }
        return h($value);
    }
    if (str_starts_with($ph, 'json:')) {
        $groups = preg_split('/\s+/', trim(substr($ph, 5)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($groups as $g) {
            if (!is_array($content[$g] ?? null)) {
                $errors[] = "{{{$ph}}}: no group \"$g\" in content.json";
                continue;
            }
            $out[$g] = $content[$g];
        }
        if ($groups === []) {
            $errors[] = "{{{$ph}}}: no groups named";
        }
        return h(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    if (str_starts_with($ph, 'asset:')) {
        $path = substr($ph, 6);
        if (!isset($assets[$path])) {
            $errors[] = "{{{$ph}}}: not a published asset (see bin/build-assets.php)";
            return '';
        }
        $used[] = $assets[$path];
        return h($assets[$path]);
    }
    if ($ph === 'dataurl') {
        return h("/$form/data/form-data.json?v=" . rawurlencode($formData['version']));
    }
    $errors[] = "{{{$ph}}}: unknown placeholder";
    return '';
}, $template);

if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "ERROR: $e\n");
    }
    fail(count($errors) . ' error(s). Nothing written.');
}

$target = "$root/public/$form/index.html";
$tmp = dirname($target) . '/.index.' . bin2hex(random_bytes(4)) . '.tmp';
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
    fail('cannot create ' . dirname($target), 2);
}
if (file_put_contents($tmp, $html) !== strlen((string) $html) || !rename($tmp, $target)) {
    @unlink($tmp);
    fail("cannot write $target", 2);
}

if (!$quiet) {
    printf("build-site: OK. wrote public/%s/index.html (form version %s, form-data %s)\n",
        $form, (string) ($content['_meta']['formVersion'] ?? '?'), $formData['version']);
    foreach (array_unique($used) as $u) {
        echo "  $u\n";
    }
}
exit(0);
