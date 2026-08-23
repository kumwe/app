<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\App\Portal\Application\PortalContext;

/**
 * Dedicated portal browser-session store, intentionally unrelated to administrator session storage.
 *
 * @since  2.0.0
 */
interface PortalSessionStore
{
    /**
     * Mint a portal-only session after password and membership resolution.
     *
     * @param   PortalPasswordIdentity  $identity   Password-authenticated ordinary user and epoch.
     * @param   PortalContext           $context    Server-issued site and membership selection.
     * @param   string                  $userAgent  Browser user agent to bind by keyed digest.
     *
     * @return  CreatedPortalSession  Stored session and one-time cookie token.
     *
     * @since   2.0.0
     */
    public function create(
        PortalPasswordIdentity $identity,
        PortalContext $context,
        string $userAgent,
    ): CreatedPortalSession;

    /**
     * Resolve a portal cookie using live identity and membership state.
     *
     * @param   string  $cookieToken  Opaque `kumwe_portal` cookie value.
     * @param   string  $userAgent    Presenting browser user agent.
     *
     * @return  ?PortalSession  Live session or null for every failure.
     *
     * @since   2.0.0
     */
    public function find(string $cookieToken, string $userAgent): ?PortalSession;

    /**
     * Revoke one portal session immediately.
     *
     * @param   string  $sessionId  Stored session UUID.
     * @param   string  $subjectId  Authenticated owner UUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $sessionId, string $subjectId): void;

    /**
     * Remove expired portal sessions as storage housekeeping.
     *
     * @return  int  Number removed.
     *
     * @since   2.0.0
     */
    public function purgeExpired(): int;
}
