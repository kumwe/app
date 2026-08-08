<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

/**
 * One kind of thing an extension can contribute, addressable by its owning extension.
 *
 * Every registry in ExtensionContributionRegistrySet implements this so that diagnostic
 * inventory and lifecycle removal iterate one declared collection. Adding a contribution
 * kind therefore cannot leave executable contributions behind on disable or trust
 * revocation, which a hand-maintained removal list eventually would.
 */
interface ContributionSurface
{
    /** @return list<mixed> */
    public function ownedBy(ContributionOwner $owner): array;

    public function remove(ContributionOwner $owner): void;
}
