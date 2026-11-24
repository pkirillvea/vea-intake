<?php

declare(strict_types=1);

namespace VeaIntake\Core;

/**
 * The signed-in user, from a verified Cloudflare Access JWT (docs/PLAN.md
 * sections 1, 10, 11). Access app tokens carry no display name, so the email
 * is the identity everywhere ("Signed in as <email>", "Submitted by: <email>");
 * name stays null unless a future identity provider claim supplies one.
 */
final class AccessIdentity
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly string $sub = '',
    ) {
    }
}
