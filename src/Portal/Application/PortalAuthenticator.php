<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Application;

/**
 * Password authentication port shared with identities but exposed without administrator semantics.
 *
 * @since  2.0.0
 */
interface PortalAuthenticator
{
    /**
     * Resolve an active identity without revealing whether an email exists.
     *
     * @param   string  $email     Submitted address.
     * @param   string  $password  Submitted password.
     * @param   string  $source    Trusted-proxy-resolved origin or `unknown`.
     *
     * @return  ?PortalPasswordIdentity  Live principal and epoch, or null for every authentication failure.
     *
     * @throws  \Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled  When exhausted.
     *
     * @since   2.0.0
     */
    public function authenticate(string $email, string $password, string $source): ?PortalPasswordIdentity;
}
