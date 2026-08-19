<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Outcome of one authorization question, together with the rule that settled it.
 *
 * `AuthorizationGateway::decide()` returns this instead of a bare boolean because the answer alone is
 * not enough downstream: the same record is handed to `AuthorizationDecisionRecorder` for the audit
 * trail and copied into `AuthorizationDenied`, so an operator reading a refusal can tell an untrusted
 * execution context from an unmapped action, a cross-site resource, or a missing grant. The policy and
 * reason strings are stable machine tokens meant for logs and assertions, never for end users — the
 * delivery layer answers a denial with a generic problem document and keeps them internal.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationDecision
{
    /**
     * Capture an evaluated decision before the gateway records and acts on it.
     *
     * @param  bool    $allowed  Whether the action may proceed; false is the default for every path
     *         the gateway does not explicitly permit.
     * @param  string  $policy   Versioned identifier of the rule that decided, such as
     *         `core.scoped-grants.v1` or `core.site-ownership.v1`.
     * @param  string  $reason   Stable token naming the cause, such as `matching_effective_grant` or
     *         `resource_site_mismatch`.
     *
     * @since  2.0.0
     */
    public function __construct(
        public bool $allowed,
        public string $policy,
        public string $reason,
    ) {
    }
}
