<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

interface AuthenticationRateLimiter
{
    public function assertAllowed(string $subjectDigest, string $sourceDigest): void;

    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void;
}
