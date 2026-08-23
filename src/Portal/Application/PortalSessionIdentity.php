<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;

/**
 * Live principal and membership snapshot reloaded while a portal session is resolved.
 *
 * @since  2.0.0
 */
final readonly class PortalSessionIdentity
{
    /**
     * Capture live identity state and its authorization epoch.
     *
     * @param   AuthenticatedPrincipal  $principal      Principal rebuilt from current roles and grants.
     * @param   PortalContext           $context        Live site, organization, and workspace membership.
     * @param   int                     $securityEpoch  Current user authorization epoch.
     *
     * @throws  InvalidArgumentException  When the epoch is not positive or differs from the principal.
     *
     * @since   2.0.0
     */
    public function __construct(
        public AuthenticatedPrincipal $principal,
        public PortalContext $context,
        public int $securityEpoch,
    ) {
        if ($securityEpoch < 1 || $principal->securityEpoch() !== $securityEpoch) {
            throw new InvalidArgumentException('A portal identity security epoch must match its principal.');
        }
    }
}
