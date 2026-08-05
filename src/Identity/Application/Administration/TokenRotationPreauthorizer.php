<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;

/** Reusable exact preauthorization for secret-once HTTP, MCP, and the application mutation. */
final readonly class TokenRotationPreauthorizer
{
    public function __construct(
        private AccessControlRepository $repository,
        private AuthorizationGateway $authorization,
        private TokenDelegationPreauthorizer $delegation,
    ) {
    }

    public function authorize(ExecutionContext $context, string $tokenId, bool $lock = false): TokenRotation
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            AuthorizationResource::item('api_token', $tokenId),
        );
        $token = $this->repository->activeTokenForRotation($tokenId, $lock)
            ?? throw new InvalidArgumentException('The active token to rotate does not exist.');
        if ($token['site_identifier'] !== $context->site()->identifier()) {
            throw new InvalidArgumentException('A token cannot be rotated outside its site context.');
        }
        $delegation = $this->delegation->authorize($context, $token['email'], $token['capabilities']);
        if ($delegation->subjectId !== $token['subject_id']) {
            throw new InvalidArgumentException('The active token subject changed during authorization.');
        }

        /** @var non-empty-list<string> $capabilities */
        $capabilities = $delegation->capabilities;
        return new TokenRotation(
            $delegation->subjectId,
            $token['email'],
            $capabilities,
            $token['site_identifier'],
            $token['audience'],
            $token['purpose'],
        );
    }
}
