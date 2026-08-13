<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Application\Authorization\AuthorizationDecision;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;
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
