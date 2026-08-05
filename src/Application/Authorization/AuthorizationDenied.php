<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use DomainException;

final class AuthorizationDenied extends DomainException
{
    public function __construct(
        public readonly string $subject,
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceIdentifier,
        public readonly string $siteIdentifier,
        public readonly string $policy,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Subject %s is not authorized to perform %s on %s:%s in site %s.',
            $subject,
            $action,
            $resourceType,
            $resourceIdentifier,
            $siteIdentifier,
        ));
    }
}
