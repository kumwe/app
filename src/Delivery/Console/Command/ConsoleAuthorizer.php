<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class ConsoleAuthorizer
{
    public function __construct(private AccessTokenVerifier $tokens)
    {
    }

    /** @param array<string, string> $options */
    public function require(array $options, string $capability): AuthenticatedPrincipal
    {
        $token = CommandInput::secretFile(CommandInput::required($options, 'token-file'));
        $principal = $this->tokens->verify($token);
        if ($principal === null || !$principal->hasCapability(Capability::fromString($capability))) {
            throw new InsufficientCapability($capability);
        }

        return $principal;
    }
}
