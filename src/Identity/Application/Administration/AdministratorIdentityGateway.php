<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;

interface AdministratorIdentityGateway
{
    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal;

    public function createInitialAdministrator(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
    ): string;

    /**
     * @param list<string> $capabilities
     *
     * @return array{token: string, token_id: string}
     */
    public function issueAccessToken(
        ExecutionContext $context,
        string $email,
        string $name,
        array $capabilities,
        ?\DateTimeImmutable $expiresAt = null,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        ?string $rotatedFrom = null,
    ): array;

    /** @return array{token: string, token_id: string} */
    public function rotateAccessToken(
        ExecutionContext $context,
        string $tokenId,
        string $name,
        ?\DateTimeImmutable $expiresAt,
    ): array;
}
