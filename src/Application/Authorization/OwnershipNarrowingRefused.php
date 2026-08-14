<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when narrowing an owning scope would leave another site's records pointing at nothing.
 *
 * Widening is cheap because it only adds reach. Narrowing takes reach away, and the sites losing it may
 * already have built records around the shared resource; completing the change would leave those records
 * referring to something they can no longer see. The operation therefore refuses and names the sites, so
 * an operator resolves the references first and repeats the narrowing rather than discovering the damage
 * afterwards. The asymmetry is deliberate and is stated in the operator documentation.
 *
 * @since  2.0.0
 */
final class OwnershipNarrowingRefused extends \RuntimeException
{
    /**
     * Sites that still refer to the resource, in site-identifier order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $referencingSites;

    /**
     * Name the resource, the refused target scope and every site that would be stranded.
     *
     * @param  AuthorizationResource  $resource          Resource whose owning scope was being narrowed.
     * @param  OwnershipScope         $target            Scope the caller asked to narrow to.
     * @param  list<string>           $referencingSites  Sites whose records still refer to the resource.
     *
     * @since  2.0.0
     */
    public function __construct(
        AuthorizationResource $resource,
        OwnershipScope $target,
        array $referencingSites,
    ) {
        $this->referencingSites = $referencingSites;
        parent::__construct(sprintf(
            'Refusing to narrow %s:%s to %s while %s still refer to it.',
            $resource->type(),
            $resource->identifier(),
            $target->describe(),
            implode(', ', $referencingSites),
        ));
    }
}
