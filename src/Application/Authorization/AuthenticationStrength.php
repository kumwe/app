<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Kind of credential that proved the identity carried by an execution context.
 *
 * `ExecutionContext` stores this alongside the actor and treats it as a consistency check rather than
 * decoration: a context built around a `SystemIdentity` must declare `System`, and a context built
 * around a human principal must not, so a background worker can never be mistaken for a signed-in
 * operator. The decision recorder writes the backing value into the audit record alongside every
 * authorization outcome, which is what lets a reviewer separate browser-session activity from
 * token-driven API and MCP traffic after the fact. It is also mixed into the context's authorization
 * fingerprint, so a stored idempotent result cannot be replayed by a caller who authenticated
 * differently from the one that produced it.
 *
 * @since  2.0.0
 */
enum AuthenticationStrength: string
{
    /**
     * A human proved themselves with their account password in this exchange.
     *
     * Covers the administrator login handler, the cookie-backed administrator session it establishes,
     * and console commands that read an operator's password from a secret file.
     *
     * @since  2.0.0
     */
    case Password = 'password';

    /**
     * A human principal was resolved from an issued access token presented as a bearer credential.
     *
     * Used by the REST and MCP entry points and by console commands authorized with a token file. The
     * actor is still a person, but no password was offered in this exchange.
     *
     * @since  2.0.0
     */
    case BearerToken = 'bearer_token';

    /**
     * A signed-in human completed a fresh multi-factor challenge in the current rotated session.
     *
     * The accompanying `StepUpProof`, rather than this label alone, supplies the actor, session, scope,
     * method and freshness bindings a high-impact action must verify.
     *
     * @since  2.0.0
     */
    case MultiFactor = 'multi_factor';

    /**
     * No human is behind the request: the context was issued to a trusted in-process identity.
     *
     * Reserved for `ExecutionContext::issueSystem()` and the `SystemIdentity` cases behind it, such as
     * migrations, the scheduler and workers, whose authority comes from the identity rather than from a
     * credential presented in this exchange.
     *
     * @since  2.0.0
     */
    case System = 'system';
}
