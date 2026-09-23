<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Access\Capability;
use Kumwe\Access\GrantScope;
use LogicException;

/** Gateway double: authorization itself is proven by the application suite, not by a persistence test. */
final class AllowingAuditAuthorization implements AuthorizationGateway
{
    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        throw new LogicException('unused');
    }

    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void {
    }

    public function assertCanDelegate(ExecutionContext $context, Capability $action, GrantScope $scope): void
    {
    }
}
