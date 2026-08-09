<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

/**
 * Contract for one declarable thing an extension contributes, whatever kind it is.
 *
 * `OwnedExtensionContributionRegistrar` indexes a manifest's declarations by identifier and compares
 * each registered object against the declaration it matched, so every contribution kind has to expose
 * a stable identifier and an array form that compares equal when the two describe the same thing.
 * Implementing this is what lets a new kind take part in that check without the registrar knowing it.
 *
 * @since  2.0.0
 */
interface ContributionDefinition
{
    /**
     * The identifier this contribution claims within its owner's namespace.
     *
     * @return  string  Identifier unique among contributions of the same kind, such as `core.dashboard`.
     *
     * @since   2.0.0
     */
    public function identifier(): string;

    /**
     * Export the contribution as the comparable structure the manifest and the inventory both use.
     *
     * Equality of two exports is what decides whether a provider registered what its manifest
     * declared, so the shape must cover every field that distinguishes one contribution from another.
     *
     * @return  array<string, mixed>  Every declared field of this contribution, keyed by field name.
     *
     * @since   2.0.0
     */
    public function toArray(): array;
}
