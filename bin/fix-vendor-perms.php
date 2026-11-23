<?php

/**
 * Re-create vendor/ so it gets the tree's group and modes (docs/PLAN.md section 2).
 *
 * Why: composer extracts packages with `unzip`, which chmods every directory to
 * the archive's stored mode. Linux clears a directory's setgid bit when someone
 * outside its group chmods it, and developer is deliberately NOT in vea-intake
 * (so it cannot read /etc/vea-intake/app.env). Everything created below such a
 * directory then gets group "developer" instead of "vea-intake", and developer
 * cannot chgrp it back. vea-join solves this with chgrp in fix-perms.sh, which
 * works there only because developer is in the vea-join group.
 *
 * Fix without chgrp or sudo: copy vendor/ to a sibling directory inside the repo.
 * New files and directories inherit group vea-intake, setgid and the default ACL
 * (files 0640, dirs 2750); files that were executable get 0750. Symlinks are
 * re-created. Then the copy replaces vendor/ and the old tree is removed.
 *
 * Does nothing if vendor/ is already clean. Runs automatically after
 * `composer install` / `composer update` (see composer.json), before check-perms.
 *
 * Usage: php bin/fix-vendor-perms.php [vendor|tests/e2e/node_modules]   (default: vendor)
 * npm has the same problem as composer (its tarball extraction chmods directories),
 * so run it with tests/e2e/node_modules after `npm install` in tests/e2e/.
 */

declare(strict_types=1);

const GROUP = 'vea-intake';

/** Trees this script may re-create (composer's vendor/ and the e2e tests' npm node_modules/). */
const TREES = ['vendor', 'tests/e2e/node_modules'];

$root = dirname(__DIR__);
$rel = $argv[1] ?? 'vendor';
if (!in_array($rel, TREES, true)) {
    fwrite(STDERR, 'fix-vendor-perms: the tree must be one of: ' . implode(', ', TREES) . "\n");
    exit(1);
}
$vendor = "$root/$rel";
umask(027);

$group = posix_getgrnam(GROUP);
if ($group === false) {
    fwrite(STDERR, "fix-vendor-perms: group " . GROUP . " not found\n");
    exit(1);
}
$gid = $group['gid'];

if (!is_dir($vendor) || is_link($vendor)) {
    echo "fix-vendor-perms: no $rel/ directory, nothing to do\n";
    exit(0);
}

/** True if any path under $dir has the wrong group, "other" bits, group write or a non-setgid dir. */
$needsFix = static function (string $dir) use ($gid): bool {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ([$dir, ...$it] as $path) {
        $st = lstat((string) $path);
        if (($st['mode'] & 0170000) === 0120000) {
            continue;
        }
        if ($st['gid'] !== $gid || ($st['mode'] & 0027) || (is_dir((string) $path) && !($st['mode'] & 02000))) {
            return true;
        }
    }
    return false;
};

if (!$needsFix($vendor)) {
    echo "fix-vendor-perms: $rel/ already clean\n";
    exit(0);
}

$tag = getmypid() . '-' . bin2hex(random_bytes(3));
$base = dirname($vendor) . "/." . basename($vendor);   // hidden sibling inside the repo
$new = "$base-new-$tag";
$old = "$base-old-$tag";

/** Recursive copy that creates every entry anew (no metadata preserved except the execute bit). */
$copy = static function (string $src, string $dst) use (&$copy): void {
    if (!mkdir($dst, 0777)) {
        throw new RuntimeException("mkdir failed: $dst");
    }
    foreach (scandir($src) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $s = "$src/$name";
        $d = "$dst/$name";
        if (is_link($s)) {
            if (!symlink((string) readlink($s), $d)) {
                throw new RuntimeException("symlink failed: $d");
            }
        } elseif (is_dir($s)) {
            $copy($s, $d);
        } else {
            if (!copy($s, $d)) {
                throw new RuntimeException("copy failed: $d");
            }
            if (fileperms($s) & 0111) {
                chmod($d, 0750);
            }
        }
    }
};

/** Recursive delete of a tree this script created or replaced. Never follows symlinks. */
$remove = static function (string $path) use (&$remove, $base): void {
    if (!str_starts_with($path, "$base-")) {
        throw new LogicException("refusing to remove $path");
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $remove("$path/$name");
        }
    }
    rmdir($path);
};

try {
    $copy($vendor, $new);
    if ($needsFix($new)) {
        throw new RuntimeException('the copy still has wrong permissions (is the repo root setgid with a default ACL?)');
    }
    if (!rename($vendor, $old)) {
        throw new RuntimeException("could not move $rel/ aside");
    }
    if (!rename($new, $vendor)) {
        rename($old, $vendor);
        throw new RuntimeException("could not move the copy into place; $rel/ restored");
    }
    $remove($old);
} catch (Throwable $e) {
    if (is_dir($new)) {
        $remove($new);
    }
    fwrite(STDERR, 'fix-vendor-perms: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "fix-vendor-perms: $rel/ re-created with group " . GROUP . ", setgid dirs, no 'other' access\n";
exit(0);
