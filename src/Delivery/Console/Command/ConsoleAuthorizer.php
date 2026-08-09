<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Turns the token file a console command was invoked with into an authorized execution context.
 *
 * A console run has neither a request nor a session, so its authority has to come from somewhere: an
 * access token, held in a file the operator has protected, presented through `--token-file`. The
 * management commands route through here, which keeps the rules in one place — the token must have been
 * minted for the console rather than for HTTP or MCP, it must be scoped to the site named by `--site`,
 * and the principal behind it must already hold the capability the command is about to use. A command
 * that gets a context back may treat the operation as authorized.
 *
 * @since  2.0.0
 */
final readonly class ConsoleAuthorizer
{
    /**
     * Wire the authorizer to the verifier that resolves tokens into principals.
     *
     * @param  AccessTokenVerifier  $tokens  Verifier that resolves a token string into the principal it names.
     *
     * @since  2.0.0
     */
    public function __construct(private AccessTokenVerifier $tokens)
    {
    }

    /**
     * Authorize the current console invocation for one capability and mint its execution context.
     *
     * The token is accepted only when it verifies for the `kumwe-cli` audience and the `management`
     * purpose against the site named by `--site`, so a token issued for the REST API or for MCP is
     * rejected here even though it is otherwise valid. Call this before doing any work: a missing
     * capability fails the whole invocation rather than half of it. Each call stamps a fresh random
     * request identifier onto the context, so the audit trail keeps one console run apart from the next.
     *
     * @param   array<string, string>  $options     Parsed command options; `--site` and `--token-file` must
     *          both be present.
     * @param   string                 $capability  Capability the command is about to exercise, such as
     *          `extensions.manage`.
     *
     * @return  ExecutionContext  Context naming the token's principal, at `AuthenticationStrength::BearerToken`.
     *
     * @throws  InsufficientCapability  When the token verifies to nobody for this audience, purpose and
     *          site, or the principal it names does not hold the capability.
     * @throws  \InvalidArgumentException  When `--site` or `--token-file` is missing, the site identifier is
     *          malformed, or the token file fails its location and permission checks.
     *
     * @since   2.0.0
     */
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
