<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class ConsoleAuthorizer
{
    public function __construct(private AccessTokenVerifier $tokens)
    {
    }

    /** @param array<string, string> $options */
    public function require(array $options, string $capability): ExecutionContext
    {
        $site = SiteContext::fromString(CommandInput::required($options, 'site'));
        $token = CommandInput::secretFile(CommandInput::required($options, 'token-file'));
        $principal = $this->tokens->verify($token, 'kumwe-cli', 'management', $site->identifier());
        if ($principal === null || !$principal->hasCapability(Capability::fromString($capability))) {
            throw new InsufficientCapability($capability);
        }

        return $principal->context(
            $site,
            AuthenticationStrength::BearerToken,
            'cli-' . bin2hex(random_bytes(16)),
        );
    }
}
