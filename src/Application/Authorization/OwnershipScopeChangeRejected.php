<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Raised when a scope change is neither a widening nor a narrowing of the owner it starts from.
 *
 * `widen()` and `narrow()` are separate operations because they carry different obligations, so each
 * must be handed a target that actually moves in its direction. A caller asking to "widen" a resource
 * from one group to an unrelated group of the same size is asking for something that adds and removes
 * reach at once, which no single guard can make safe; it is refused so the caller performs the two
 * moves explicitly and each is judged on its own.
 *
 * @since  2.0.0
 */
final class OwnershipScopeChangeRejected extends \RuntimeException
{
    /**
     * Name both scopes and the direction that was asked for.
     *
     * @param  AuthorizationResource  $resource   Resource whose owning scope was being changed.
     * @param  OwnershipScope         $current    Owner the resource holds now.
     * @param  OwnershipScope         $target     Owner the caller asked for.
     * @param  string                 $direction  Operation that refused: `widen` or `narrow`.
     *
     * @since  2.0.0
     */
    public function __construct(
        AuthorizationResource $resource,
        OwnershipScope $current,
        OwnershipScope $target,
        string $direction,
    ) {
        parent::__construct(sprintf(
            'Cannot %s %s:%s from %s to %s; the target does not move in that direction.',
            $direction,
            $resource->type(),
            $resource->identifier(),
            $current->describe(),
            $target->describe(),
        ));
    }
}
