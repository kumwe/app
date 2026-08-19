<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Raised when ownership is changed on behalf of an owner that does not actually hold the resource.
 *
 * `ResourceSiteOwnershipWriter::remove()` and `reassign()` both match on the resource *and* the owner the
 * caller expects, so a statement that affects no row while a record still exists means the caller was
 * wrong about the owner. Failing here instead of matching by resource alone stops one site from severing
 * another site's ownership — which would leave that resource unreachable to everyone — and turns two
 * concurrent scope changes into one change and one refusal rather than a last-writer-wins race.
 *
 * @since  2.0.0
 */
final class ResourceSiteOwnershipConflict extends \RuntimeException
{
    /**
     * Name the resource and both owners in the operator-facing message.
     *
     * @param  AuthorizationResource  $resource  Target whose ownership was being changed.
     * @param  OwnershipScope         $expected  Owner the caller believed held the resource.
     * @param  OwnershipScope         $actual    Owner the surviving ownership record names.
     *
     * @since  2.0.0
     */
    public function __construct(
        AuthorizationResource $resource,
        OwnershipScope $expected,
        OwnershipScope $actual,
    ) {
        parent::__construct(sprintf(
            'Refusing to change %s:%s ownership held by %s on behalf of %s.',
            $resource->type(),
            $resource->identifier(),
            $actual->describe(),
            $expected->describe(),
        ));
    }
}
