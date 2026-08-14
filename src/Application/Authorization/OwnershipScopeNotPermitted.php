<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when a resource category is asked to be owned at a level its rule does not admit.
 *
 * This is the refusal that keeps a legal entity's books out of joint ownership. It fires at the point an
 * ownership fact is constructed, in `ResourceOwnership::of()`, rather than at the database, so the
 * impermissible pairing never reaches storage and no code path exists that could write one. Reaching an
 * operator, it means someone asked to share a category this build declares isolated; the answer is to
 * share a different category, never to change the rule.
 *
 * @since  2.0.0
 */
final class OwnershipScopeNotPermitted extends \RuntimeException
{
    /**
     * Name the category, the refused level and the rule that refused it.
     *
     * @param  AuthorizationResource  $resource  Target whose ownership was being established.
     * @param  OwnershipScope         $scope     Owner that was asked for.
     * @param  OwnershipScopeRule     $rule      Rule this build fixes for the resource's category.
     *
     * @since  2.0.0
     */
    public function __construct(
        AuthorizationResource $resource,
        OwnershipScope $scope,
        OwnershipScopeRule $rule,
    ) {
        parent::__construct(sprintf(
            'Resources of category %s cannot be owned at %s; this build permits %s only.',
            $resource->type(),
            $scope->describe(),
            implode(
                ', ',
                array_map(
                    static fn (OwnershipScopeLevel $level): string => $level->value,
                    $rule->levels(),
                ),
            ),
        ));
    }
}
