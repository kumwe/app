<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Psr\Log\LoggerInterface;

final readonly class StructuredLogAuthorizationDecisionRecorder implements AuthorizationDecisionRecorder
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void {
        $record = [
            'subject' => $context->actorId(),
            'action' => $action->value(),
            'resource_type' => $resource->type(),
            'resource_id' => $resource->identifier(),
            'site' => $context->site()->identifier(),
            'authentication_strength' => $context->authenticationStrength()->value,
            'request_id' => $context->requestId(),
            'correlation_id' => $context->correlationId(),
            'policy' => $decision->policy,
            'reason' => $decision->reason,
            'allowed' => $decision->allowed,
        ];

        if ($decision->allowed) {
            $this->logger->info('Authorization decision.', $record);
        } else {
            $this->logger->warning('Authorization decision.', $record);
        }
    }
}
