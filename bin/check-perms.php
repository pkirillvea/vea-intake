<?php

/**
 * Read-only permission check of the vea-intake tree (docs/PLAN.md section 2).
 *
 * Every path under the repo root must be:
 *   - in group vea-intake (the PHP-FPM pool reads the code through the group)
 *   - without any access for "other" (www-data reaches public/ through ACLs only)
 *   - not group-writable (the pool must never write to the code)
 *   - a setgid directory, if it is a directory (so new files inherit the group)
 *
 * Changes nothing. Symlinks are reported, not followed. Typical cause of a
 * failure: running composer without `umask 027` (vendor/bin/* get "other" bits).
 *
 * Usage: php bin/check-perms.php [--quiet]      Exit 0 = clean, 1 = problems found.
 */

declare(strict_types=1);

const GROUP = 'vea-intake';
const MAX_LISTED = 50;

$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);

$group = function_exists('posix_getgrnam') ? posix_getgrnam(GROUP) : false;
if ($group === false) {
    fwrite(STDERR, "check-perms: group " . GROUP . " not found (or the posix extension is missing)\n");
    exit(1);
}
$gid = $group['gid'];

$problems = [];
$checked = 0;
$add = static function (string $path, string $why) use (&$problems, $root): void {
    $problems[] = sprintf('%-28s %s', $why, substr($path, strlen($root)) ?: '/');
};

$check = static function (string $path) use ($gid, $add, &$checked): void {
    $checked++;
    $st = @lstat($path);
    if ($st === false) {
        $add($path, 'cannot stat');
        return;
    }
    $mode = $st['mode'];
    $type = $mode & 0170000;
    if ($type === 0120000) {
        $add($path, 'symlink (not followed)');
        return;
    }
    if ($st['gid'] !== $gid) {
        $add($path, 'group is not ' . GROUP);
    }
    if ($mode & 0007) {
        $add($path, sprintf('"other" access (%04o)', $mode & 07777));
    }
    if ($mode & 0020) {
        $add($path, sprintf('group-writable (%04o)', $mode & 07777));
    }
    if ($type === 0040000 && !($mode & 02000)) {
        $add($path, sprintf('directory not setgid (%04o)', $mode & 07777));
    }
};

$check($root);
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);
foreach ($it as $path) {
    $check((string) $path);
}

if ($problems === []) {
    if (!$quiet) {
        echo "check-perms: OK ($checked paths: group " . GROUP . ", no 'other' access, no group write, dirs setgid)\n";
    }
    exit(0);
}
fwrite(STDERR, sprintf("check-perms: %d problem(s) in %d paths:\n", count($problems), $checked));
foreach (array_slice($problems, 0, MAX_LISTED) as $line) {
    fwrite(STDERR, "  $line\n");
}
if (count($problems) > MAX_LISTED) {
    fwrite(STDERR, '  ... ' . (count($problems) - MAX_LISTED) . " more\n");
}
fwrite(STDERR, "Fix as developer, without sudo: re-create the files under `umask 027` (e.g. `umask 027 && composer install`).\n");
exit(1);
