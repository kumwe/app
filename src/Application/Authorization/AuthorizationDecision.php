<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

final readonly class AuthorizationDecision
{
    public function __construct(
        public bool $allowed,
        public string $policy,
        public string $reason,
    ) {
    }
}
