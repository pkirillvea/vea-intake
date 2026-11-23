<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/** The signed-in user, from a verified Cloudflare Access JWT (docs/PLAN.md sections 1, 11). */
final class AccessIdentity
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $sub,
    ) {
    }
}
