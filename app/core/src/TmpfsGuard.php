<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Refuse submissions unless /run/vea-intake is a mounted tmpfs (docs/PLAN.md
 * sections 2, 3): checked in /proc/self/mountinfo, so uploads and dompdf temp
 * files can never fall back to disk. Implemented in Prompt 4.
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
        throw new \LogicException('Prompt 4');
    }

    /** @throws HttpError 503 (unavailable) when not ready */
    public function assertReady(): void
    {
        throw new \LogicException('Prompt 4');
    }
}
