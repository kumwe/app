<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use InvalidArgumentException;

final readonly class FixedAccessTokenQuotaPolicy implements AccessTokenQuotaPolicy
{
    public function __construct(private int $maximumActiveTokens = 25)
    {
        if ($maximumActiveTokens < 1 || $maximumActiveTokens > 1_000) {
            throw new InvalidArgumentException('The active API-token quota must be between 1 and 1,000.');
        }
    }

    public function assertAllowed(
        string $subjectId,
        string $siteIdentifier,
        string $audience,
        string $purpose,
        int $activeTokens,
    ): void {
        if ($activeTokens >= $this->maximumActiveTokens) {
            throw new InvalidArgumentException('The active token quota for this subject and scope has been reached.');
        }
    }
}
