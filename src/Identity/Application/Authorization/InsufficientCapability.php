<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use DomainException;

/**
 * Raised when an actor reaches a guarded operation without the capability that operation requires.
 *
 * This is the pre-flight refusal a guard raises before any work begins — `ConsoleAuthorizer` on a
 * command, the MCP handlers on a tool call, `ContentTransitionAuthorizer` on a workflow move, the theme
 * authorizers on a template change. It is deliberately distinct from `AuthorizationDenied`, which the
 * gateway raises only after it has evaluated and recorded a decision; this one says the caller never
 * held the grant to begin with, including the case where the credential resolved to no principal at
 * all. Delivery code answers it with a 403 `urn:kumwe:problem:insufficient-capability` document, and
 * the missing capability is carried as a property so logs and tests can name it without parsing the
 * message.
 *
 * @since  2.0.0
 */
final class InsufficientCapability extends DomainException
{
    /**
     * Name the capability whose absence stopped the operation.
     *
     * The name reaches the client in the problem document's detail, so pass the capability the caller
     * was actually asked for — never a secret, a token, or an internal identifier.
     *
     * @param  string  $capability  Capability the caller needed, such as `content.publish`, or
     *         `authenticated` where the credential resolved to no principal at all.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $capability)
    {
        parent::__construct(sprintf('Capability %s is required for this operation.', $capability));
    }
}
