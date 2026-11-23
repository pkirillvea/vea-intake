<?php

/**
 * Publish a form's static assets (docs/PLAN.md section 13).
 *
 * Copies an ALLOWLIST of files from app/shared-assets/ (shared by every form) and
 * app/forms/<form>/assets/ (that form only) into public/<form>/assets/, and writes
 * app/data/assets-<form>.json: logical path => "/<form>/assets/<path>?v=<first 12
 * hex of the file's SHA-256>" for bin/build-site.php (cache busting as vea-join).
 *
 * Fails (exit 1, nothing published) if:
 *   - a source folder contains any file that is not on the allowlist (it would be
 *     unclear whether it should be public), including dotfiles;
 *   - an allowlisted file is missing, or has an extension outside EXTENSIONS.
 * public/<form>/assets/ is rebuilt from scratch and swapped in, so it contains
 * exactly the allowlisted files and nothing else.
 *
 * Usage: php bin/build-assets.php --form=legal [--quiet] [--root=DIR]
 * Exit: 0 OK, 1 allowlist/validation error, 2 cannot read/write.
 */

declare(strict_types=1);

/** Shared files every form publishes (path under app/shared-assets/). */
const SHARED = [
    'css/app.css',
    'img/vea-logo-white.png',
    'img/icons/favicon-32.png',
    'img/icons/apple-touch-icon.png',
];

/** Form-only files (path under app/forms/<form>/assets/). Prompt 3 adds js/legal.js. */
const FORM_FILES = [
    'legal' => [],
];

const EXTENSIONS = ['css', 'js', 'png', 'ico', 'svg', 'woff2'];

$opt = getopt('', ['form:', 'quiet', 'root:']);
$form = (string) ($opt['form'] ?? '');
$quiet = isset($opt['quiet']);
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "build-assets: $msg\n");
    exit($code);
}

if (!array_key_exists($form, FORM_FILES)) {
    fail('--form must be one of: ' . implode(', ', array_keys(FORM_FILES)));
}

/** @return list<string> every file under $dir (relative), including dotfiles; [] if $dir is absent */
function list_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $out[] = substr($f->getPathname(), strlen($dir) + 1);
    }
    sort($out);

    return $out;
}

// source dir => allowlist
$sources = [
    "$root/app/shared-assets" => SHARED,
    "$root/app/forms/$form/assets" => FORM_FILES[$form],
];

$errors = [];
$publish = [];   // logical path => absolute source file
foreach ($sources as $dir => $allow) {
    $rel = substr($dir, strlen($root) + 1);
    foreach (array_diff(list_files($dir), $allow) as $extra) {
        $errors[] = "$rel/$extra is not on the allowlist (add it to bin/build-assets.php or remove it)";
    }
    foreach ($allow as $path) {
        $file = "$dir/$path";
        if (!is_file($file) || is_link($file)) {
            $errors[] = "$rel/$path is on the allowlist but missing (or a symlink)";
            continue;
        }
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), EXTENSIONS, true)
            || preg_match('~(^|/)\.~', $path) || !preg_match('~^[A-Za-z0-9._/-]+$~', $path)) {
            $errors[] = "$rel/$path: extension or name not allowed for publishing";
            continue;
        }
        if (isset($publish[$path])) {
            $errors[] = "$path is published by both app/shared-assets and app/forms/$form/assets";
            continue;
        }
        $publish[$path] = $file;
    }
}
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "ERROR: $e\n");
    }
    fail(count($errors) . ' error(s). Nothing published.');
}

// Build public/<form>/assets/ anew next to the live one, then swap.
$pub = "$root/public/$form";
$dest = "$pub/assets";
$tag = bin2hex(random_bytes(4));
$new = "$pub/.assets-new-$tag";
$old = "$pub/.assets-old-$tag";
if (!is_dir($pub) && !mkdir($pub, 0777, true)) {
    fail("cannot create $pub", 2);
}

/** Delete a tree this script created. */
function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $n) {
        if ($n !== '.' && $n !== '..') {
            remove_tree("$path/$n");
        }
    }
    rmdir($path);
}

$manifest = [];
try {
    mkdir($new, 0777);
    foreach ($publish as $path => $file) {
        $target = "$new/$path";
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
            throw new RuntimeException("cannot create " . dirname($target));
        }
        if (!copy($file, $target)) {
            throw new RuntimeException("cannot copy $path");
        }
        $manifest[$path] = "/$form/assets/$path?v=" . substr(hash_file('sha256', $target), 0, 12);
    }
    $expected = array_keys($publish);
    sort($expected);
    if (list_files($new) !== $expected) {
        throw new RuntimeException('the new assets folder does not match the allowlist');
    }
    if (is_dir($dest) && !rename($dest, $old)) {
        throw new RuntimeException("cannot move the current $dest aside");
    }
    if (!rename($new, $dest)) {
        if (is_dir($old)) {
            rename($old, $dest);
        }
        throw new RuntimeException("cannot move the new assets into place (previous assets restored)");
    }
    if (is_dir($old)) {
        remove_tree($old);
    }
} catch (Throwable $e) {
    if (is_dir($new)) {
        remove_tree($new);
    }
    fail($e->getMessage(), 2);
}

ksort($manifest);
$json = json_encode(['form' => $form, 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$mfile = "$root/app/data/assets-$form.json";
if (!is_dir(dirname($mfile)) && !mkdir(dirname($mfile), 0777, true)) {
    fail('cannot create ' . dirname($mfile), 2);
}
$tmp = dirname($mfile) . '/.assets-' . $tag . '.tmp';
if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $mfile)) {
    @unlink($tmp);
    fail("cannot write $mfile", 2);
}

if (!$quiet) {
    printf("build-assets: OK. %d file(s) published to public/%s/assets/\n", count($manifest), $form);
    foreach ($manifest as $url) {
        echo "  $url\n";
    }
    echo "  wrote app/data/assets-$form.json\n";
}
exit(0);
