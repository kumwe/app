<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

interface AuthorizationGateway
{
    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision;

    /** @throws AuthorizationDenied */
    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void;

    /** @throws AuthorizationDenied */
    public function assertCanDelegate(
        ExecutionContext $context,
        Capability $action,
        GrantScope $scope,
    ): void;
}
