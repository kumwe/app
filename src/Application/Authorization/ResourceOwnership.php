<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * One resource paired with the scope that owns it, provably permitted for that resource's category.
 *
 * The constructor is private and `of()` is the only way in, so holding an instance is itself the proof
 * that the category's rule admits the level: an ownership row that a legal entity's books could not
 * legitimately carry cannot be assembled, let alone written. Every write path that changes an owner takes
 * this type rather than a loose resource-and-scope pair, which moves the isolation check from a rule the
 * registry remembers to apply into a shape the type system will not let a caller skip.
 *
 * @since  2.0.0
 */
final readonly class ResourceOwnership
{
    /**
     * Hold a pairing that has already been proven permitted.
     *
     * @param  AuthorizationResource  $resource  Resource whose owner this is.
     * @param  OwnershipScope         $scope     Owner, at a level the resource's category admits.
     *
     * @since  2.0.0
     */
    private function __construct(
        public AuthorizationResource $resource,
        public OwnershipScope $scope,
    ) {
    }

    /**
     * Establish who owns a resource, refusing a level the resource's category does not admit.
     *
     * @param   AuthorizationResource         $resource  Resource being created or reassigned; a collection
     *          names a family and has no single owner to record.
     * @param   OwnershipScope                $scope     Owner being proposed for it.
     * @param   ResourceOwnershipScopePolicy  $policy    Catalogue holding this build's frozen category table.
     *
     * @return  self  The proven pairing, safe to hand to the ownership registry.
     *
     * @throws  OwnershipScopeNotPermitted  When the category may not be owned at that level.
     * @throws  \InvalidArgumentException  When the resource names a whole collection.
     *
     * @since   2.0.0
     */
    public static function of(
        AuthorizationResource $resource,
        OwnershipScope $scope,
        ResourceOwnershipScopePolicy $policy,
    ): self {
        if ($resource->identifier() === '*') {
            throw new \InvalidArgumentException('Collection resources cannot have an ownership record.');
        }

        $rule = $policy->rule($resource->type());
        if (!$rule->permits($scope->level)) {
            throw new OwnershipScopeNotPermitted($resource, $scope, $rule);
        }

        return new self($resource, $scope);
    }
}
