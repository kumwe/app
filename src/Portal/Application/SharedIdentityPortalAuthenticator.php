<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;

/**
 * Adapter that reuses the canonical password verifier while returning a portal-neutral identity snapshot.
 *
 * @since  2.0.0
 */
final readonly class SharedIdentityPortalAuthenticator implements PortalAuthenticator
{
    /**
     * Bind password authentication to the canonical gateway and a live principal loader.
     *
     * @param  AdministratorIdentityGateway  $identities  Existing shared password authentication boundary.
     * @param  PortalPrincipalLoader         $principals  Live grant and security-epoch loader.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private PortalPrincipalLoader $principals,
    ) {
    }

    /**
     * Authenticate through the shared identity gateway, then rebuild the portal principal from live grants.
     *
     * @param   string  $email     Submitted email.
     * @param   string  $password  Submitted password.
     * @param   string  $source    Trusted origin or `unknown`.
     *
     * @return  ?PortalPasswordIdentity  Live identity or null for every authentication failure.
     *
     * @since   2.0.0
     */
    public function authenticate(string $email, string $password, string $source): ?PortalPasswordIdentity
    {
        $principal = $this->identities->authenticate($email, $password, $source);
        if ($principal === null) {
            return null;
        }

        return $this->principals->load(
            $principal->subject(),
            'portal-password:' . $principal->subject(),
        );
    }
}
