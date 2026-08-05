<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\GrantScope;

/** Reusable exact authorization for all token issuance adapters and the application mutation. */
final readonly class TokenDelegationPreauthorizer
{
    public function __construct(
        private AccessControlRepository $repository,
        private AuthorizationGateway $authorization,
    ) {
    }

    /** @param array<mixed> $capabilities */
    public function authorize(
        ExecutionContext $context,
        string $email,
        array $capabilities,
    ): TokenDelegation {
        if (!array_is_list($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException('At least one token capability is required.');
        }

        /** @var array<string, true> $requested */
        $requested = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Token capabilities must be strings.');
            }
            $requested[Capability::fromString($capability)->value()] = true;
        }

        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            AuthorizationResource::collection('api_token'),
        );
        $normalizedEmail = EmailAddress::fromString($email)->value();
        $subjectId = $this->repository->userIdByEmail($normalizedEmail)
            ?? throw new InvalidArgumentException('The requested active token subject does not exist.');
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            AuthorizationResource::item('user', $subjectId),
        );

        $targetGrants = $this->repository->userGrants($subjectId);
        foreach (array_keys($requested) as $capability) {
            $matching = array_values(array_filter(
                $targetGrants,
                static fn (array $grant): bool => $grant['capability'] === $capability,
            ));
            if ($matching === []) {
                throw new InvalidArgumentException(sprintf(
                    'The token subject does not grant capability %s.',
                    $capability,
                ));
            }
            foreach ($matching as $grant) {
                $this->authorization->assertCanDelegate(
                    $context,
                    Capability::fromString($capability),
                    $grant['scope_type'] === 'global'
                        ? GrantScope::global()
                        : GrantScope::named($grant['scope_type'], $grant['scope_identifier'] ?? ''),
                );
            }
        }

        /** @var non-empty-list<string> $authorizedCapabilities */
        $authorizedCapabilities = array_keys($requested);
        return new TokenDelegation($subjectId, $authorizedCapabilities);
    }
}
