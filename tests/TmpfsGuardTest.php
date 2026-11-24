<?php

declare(strict_types=1);

namespace VeaIntake\Tests;

use PHPUnit\Framework\TestCase;
use VeaIntake\Core\HttpError;
use VeaIntake\Core\Log;
use VeaIntake\Core\TmpfsGuard;
use VeaIntake\Tests\Support\TempTree;

/** TmpfsGuard against fake /proc/self/mountinfo files. */
final class TmpfsGuardTest extends TestCase
{
    private TempTree $t;
    private string $dir;

    protected function setUp(): void
    {
        $this->t = new TempTree();
        $this->dir = $this->t->root . '/run vea';   // a space: exercises the \040 escape
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        Log::setWriter(null);
        $this->t->remove();
    }

    private function guard(string $mountinfo): TmpfsGuard
    {
        $this->t->write('mountinfo', $mountinfo);

        return new TmpfsGuard($this->dir, $this->t->root . '/mountinfo');
    }

    private function line(string $type, string $point = ''): string
    {
        $point = str_replace(' ', '\\040', $point === '' ? $this->dir : $point);

        return "250 30 0:61 / $point rw,nosuid,nodev,noexec,relatime shared:140 - $type tmpfs rw,size=131072k,mode=700,uid=997,gid=987\n";
    }

    public function testMountedTmpfsIsReady(): void
    {
        $root = "22 1 252:1 / / rw,relatime shared:1 - ext4 /dev/vda1 rw\n";
        self::assertTrue($this->guard($root . $this->line('tmpfs'))->isReady());
    }

    public function testPlainDirectoryOnDiskIsNotReady(): void
    {
        self::assertFalse($this->guard("22 1 252:1 / / rw,relatime shared:1 - ext4 /dev/vda1 rw\n")->isReady());
    }

    public function testOtherFileSystemOrOtherPathIsNotReady(): void
    {
        self::assertFalse($this->guard($this->line('ext4'))->isReady());
        self::assertFalse($this->guard($this->line('tmpfs', $this->dir . '-other'))->isReady());
        self::assertFalse($this->guard($this->line('tmpfs', dirname($this->dir)))->isReady(), 'a tmpfs on the parent does not count');
    }

    public function testLastMountOnThePathWins(): void
    {
        self::assertFalse($this->guard($this->line('tmpfs') . $this->line('ext4'))->isReady());
        self::assertTrue($this->guard($this->line('ext4') . $this->line('tmpfs'))->isReady());
    }

    public function testMissingOrMalformedMountinfoIsNotReady(): void
    {
        self::assertFalse((new TmpfsGuard($this->dir, $this->t->root . '/nope'))->isReady());
        self::assertFalse($this->guard("garbage\n\n- \n")->isReady());
    }

    public function testMountedButMissingDirectoryIsNotReady(): void
    {
        rmdir($this->dir);
        self::assertFalse($this->guard($this->line('tmpfs'))->isReady());
    }

    public function testAssertReadyThrows503AndLogs(): void
    {
        $log = [];
        Log::setWriter(function (string $l) use (&$log): void { $log[] = $l; });
        try {
            $this->guard($this->line('ext4'))->assertReady();
            self::fail('no exception');
        } catch (HttpError $e) {
            self::assertSame(503, $e->status);
            self::assertSame(['ok' => false, 'error' => 'unavailable'], $e->body());
        }
        self::assertSame(['vea-intake event=tmpfs_not_ready status=503'], $log);
    }
}
