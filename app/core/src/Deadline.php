<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * Wall-clock budget of one request (docs/PLAN.md section 2, "Timeouts").
 * submit.php must answer before nginx's fastcgi_read_timeout (90 s) and
 * Cloudflare's 100 s origin timeout, so every outbound call is bounded by the
 * time left until the deadline (80 s after the request started).
 */
final class Deadline
{
    public const SUBMIT_BUDGET = 80.0;

    private readonly \Closure $clock;

    public function __construct(private readonly float $end, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Budget counted from REQUEST_TIME_FLOAT (when PHP got the request), else from now. */
    public static function fromRequest(array $server, float $budget = self::SUBMIT_BUDGET, ?\Closure $clock = null): self
    {
        $now = $clock !== null ? $clock() : microtime(true);
        $start = $server['REQUEST_TIME_FLOAT'] ?? null;
        $start = is_numeric($start) && (float) $start <= $now && (float) $start > $now - 3600 ? (float) $start : $now;

        return new self($start + $budget, $clock);
    }

    public function remaining(): float
    {
        return max(0.0, $this->end - ($this->clock)());
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /** A per-call timeout: at most $max seconds, never past the deadline. 0 = no time left. */
    public function timeout(int $max): int
    {
        return (int) max(0, min($max, floor($this->remaining())));
    }
}
