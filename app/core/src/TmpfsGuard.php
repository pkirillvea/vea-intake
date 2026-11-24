<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Refuse requests unless /run/vea-intake is a mounted tmpfs (docs/PLAN.md
 * sections 2, 3), so uploads and dompdf temp files can never fall back to disk.
 * The first check in submit.php and ping.php.
 *
 * Reads /proc/self/mountinfo: field 5 is the mount point (octal escapes such as
 * \040 for a space), the field after " - " is the file system type. When several
 * mounts share the mount point, the last one is the visible one.
 */
final class TmpfsGuard
{
    public function __construct(
        private readonly string $path = '/run/vea-intake',
        private readonly string $mountinfo = '/proc/self/mountinfo',
    ) {
    }

    public function isReady(): bool
    {
        $lines = @file($this->mountinfo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }
        $type = null;
        foreach ($lines as $line) {
            $sep = strpos($line, ' - ');
            if ($sep === false) {
                continue;
            }
            $before = explode(' ', substr($line, 0, $sep));
            $after = explode(' ', substr($line, $sep + 3));
            if (count($before) < 5 || $after === [] || $after[0] === '') {
                continue;
            }
            if (self::unescape($before[4]) === $this->path) {
                $type = $after[0];
            }
        }

        return $type === 'tmpfs' && is_dir($this->path) && is_writable($this->path);
    }

    /** @throws HttpError 503 (unavailable) when not ready */
    public function assertReady(): void
    {
        if (!$this->isReady()) {
            Log::event('tmpfs_not_ready', ['status' => 503]);
            throw new HttpError(503, HttpError::UNAVAILABLE);
        }
    }

    private static function unescape(string $field): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $field);
    }
}
