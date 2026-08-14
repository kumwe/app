<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when a caller that can only act for one site is handed a scope that names several.
 *
 * Durable background work executes as the site that owns it, so the worker and the scheduler need a
 * single site rather than a set. Every category that carries such work is declared site-only in
 * `ResourceOwnershipScopePolicy`, which means this exception marks a broken invariant — an ownership row
 * that was widened past what its category permits — and not an ordinary operator mistake. Refusing here
 * keeps the failure loud instead of silently electing one member of a group to run as.
 *
 * @since  2.0.0
 */
final class OwnershipScopeNotSiteBound extends \RuntimeException
{
    /**
     * Name the offending scope in the operator-facing message.
     *
     * @param  OwnershipScope  $scope  Scope that owns the resource but names no single site.
     *
     * @since  2.0.0
     */
    public function __construct(OwnershipScope $scope)
    {
        parent::__construct(sprintf(
            'Ownership scope %s names no single site, so work cannot be executed on its behalf.',
            $scope->describe(),
        ));
    }
}
