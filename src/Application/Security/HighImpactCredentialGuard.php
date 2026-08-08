<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Security;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface HighImpactCredentialGuard
{
    public function assertCurrentPassword(
        ExecutionContext $context,
        string $purpose,
        #[\SensitiveParameter] ?string $credential,
    ): void;
}
