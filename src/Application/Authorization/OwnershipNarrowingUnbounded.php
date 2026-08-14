<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when a narrowing cannot name the sites it would take reach away from.
 *
 * The installation scope means every site there is and every site there will be, so the set losing reach
 * when a resource leaves it is not a list the registry can enumerate and prove safe. Rather than run the
 * stranded-reference guard over a set it cannot bound — which would answer "nothing would be stranded"
 * simply because it had nothing to look at — the operation refuses. An operator who needs the resource at
 * a narrower owner records a new one at that owner and withdraws the installation-scoped resource
 * deliberately, which keeps the decision about what happens to the references an explicit one.
 *
 * @since  2.0.0
 */
final class OwnershipNarrowingUnbounded extends \RuntimeException
{
    /**
     * Name the resource and the owner it cannot be narrowed out of.
     *
     * @param  AuthorizationResource  $resource  Resource whose owning scope was being narrowed.
     * @param  OwnershipScope         $current   Owner whose membership cannot be enumerated.
     *
     * @since  2.0.0
     */
    public function __construct(AuthorizationResource $resource, OwnershipScope $current)
    {
        parent::__construct(sprintf(
            'Refusing to narrow %s:%s out of %s, because the sites losing reach cannot be enumerated.',
            $resource->type(),
            $resource->identifier(),
            $current->describe(),
        ));
    }
}
