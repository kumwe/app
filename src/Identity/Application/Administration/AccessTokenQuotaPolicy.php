<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

interface AccessTokenQuotaPolicy
{
    public function assertAllowed(
        string $subjectId,
        string $siteIdentifier,
        string $audience,
        string $purpose,
        int $activeTokens,
    ): void;
}
