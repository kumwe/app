<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;

/**
 * Principal and authorization epoch returned by portal password authentication.
 *
 * @since  2.0.0
 */
final readonly class PortalPasswordIdentity
{
    /**
     * Capture the identity state authenticated in the password exchange.
     *
     * @param   AuthenticatedPrincipal  $principal      Authenticated actor with live grants.
     * @param   int                     $securityEpoch  Current user authorization epoch.
     *
     * @throws  InvalidArgumentException  When the epoch is not positive or differs from the principal.
     *
     * @since   2.0.0
     */
    public function __construct(
        public AuthenticatedPrincipal $principal,
        public int $securityEpoch,
    ) {
        if ($securityEpoch < 1 || $principal->securityEpoch() !== $securityEpoch) {
            throw new InvalidArgumentException('A portal password identity epoch must match its principal.');
        }
    }
}
