<?php

declare(strict_types=1);

namespace VeaIntake\Tests\Support;

/** A throw-away repo-like tree under the system temp dir, for the bin/ script tests. */
final class TempTree
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vea-intake-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    public function write(string $rel, string $content): void
    {
        $path = "$this->root/$rel";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }

    public function json(string $rel, mixed $data): void
    {
        $this->write($rel, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function read(string $rel): string
    {
        return (string) file_get_contents("$this->root/$rel");
    }

    /** Run one of the repo's bin/ scripts against this tree. @return array{0: int, 1: string} exit code, stdout+stderr */
    public function run(string $script, string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . "/bin/$script")
            . ' ' . escapeshellarg("--root=$this->root");
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function remove(): void
    {
        $rm = static function (string $p) use (&$rm): void {
            if (is_dir($p) && !is_link($p)) {
                foreach (scandir($p) ?: [] as $n) {
                    if ($n !== '.' && $n !== '..') {
                        $rm("$p/$n");
                    }
                }
                rmdir($p);
            } elseif (file_exists($p) || is_link($p)) {
                unlink($p);
            }
        };
        if (str_starts_with($this->root, sys_get_temp_dir() . '/vea-intake-test-')) {
            $rm($this->root);
        }
    }
}
