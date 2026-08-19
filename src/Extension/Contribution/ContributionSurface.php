<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * One kind of thing an extension can contribute, addressable by its owning extension.
 *
 * Every registry in ExtensionContributionRegistrySet implements this so that diagnostic
 * inventory and lifecycle removal iterate one declared collection. Adding a contribution
 * kind therefore cannot leave executable contributions behind on disable or trust
 * revocation, which a hand-maintained removal list eventually would.
 *
 * @since  2.0.0
 */
interface ContributionSurface
{
    /**
     * List everything one owner has contributed to this surface.
     *
     * @param   ContributionOwner  $owner  Contributor whose entries are being inspected.
     *
     * @return  list<mixed>  The owner's entries in this surface's own export shape; empty when it has none.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array;

    /**
     * Withdraw every contribution one owner made to this surface.
     *
     * An owner that contributed nothing is not an error, which is what lets the registry set sweep
     * every surface on disable, uninstall, or trust revocation without knowing what each one holds.
     *
     * @param   ContributionOwner  $owner  Contributor whose entries are being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void;
}
