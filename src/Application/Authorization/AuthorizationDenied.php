<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use DomainException;

/**
 * Raised when the authorization gateway refuses an action, carrying the full shape of the refusal.
 *
 * This is the single failure every application service lets propagate when policy says no, so delivery
 * code has one thing to catch: `ProblemDetailsMiddleware` answers it with a generic 403 problem
 * document that reveals none of these fields, and the administrator surfaces refuse the request in the
 * same way. The properties exist for the operator-facing log and for tests that need to pin *why* a
 * request was refused rather than merely that it was — the policy and reason are copied straight from
 * the `AuthorizationDecision` the gateway already recorded.
 *
 * @since  2.0.0
 */
final class AuthorizationDenied extends DomainException
{
    /**
     * Describe the refusal in the terms the gateway evaluated it in.
     *
     * @param  string  $subject             Actor identifier the context resolved to, human or system.
     * @param  string  $action              Capability value that was refused, such as `content.publish`.
     * @param  string  $resourceType        Resource type the action was aimed at, such as `content`.
     * @param  string  $resourceIdentifier  Resource identifier, or `*` for a collection-wide attempt.
     * @param  string  $siteIdentifier      Site the execution context was operating in.
     * @param  string  $policy              Versioned rule that produced the refusal.
     * @param  string  $reason              Stable token naming the cause, such as `global_grant_required`.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceIdentifier,
        public readonly string $siteIdentifier,
        public readonly string $policy,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Subject %s is not authorized to perform %s on %s:%s in site %s.',
            $subject,
            $action,
            $resourceType,
            $resourceIdentifier,
            $siteIdentifier,
        ));
    }
}
