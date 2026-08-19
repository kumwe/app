<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * The set of ownership levels one resource category may be held at.
 *
 * A category declares a rule, not a list, so the permitted combinations are closed: there is no way to
 * write down "group but not site", and no way to assemble a set at runtime from configuration. That is
 * what makes accounting isolation structural rather than operational — a ledger's category resolves to
 * `SiteOnly`, and `SiteOnly::permits()` answers false for a group with no branch an operator can reach.
 * Every rule permits the site level, because a resource that no site may own could never be created.
 *
 * @since  2.0.0
 */
enum OwnershipScopeRule: string
{
    /**
     * The category is isolated: one site owns each of its resources and no wider owner is representable.
     *
     * A legal entity's books, its pay runs and its ledgers are declared here. This is also the rule an
     * undeclared category falls back to, so a new category is isolated until someone deliberately says
     * otherwise.
     *
     * @since  2.0.0
     */
    case SiteOnly = 'site_only';

    /**
     * The category is shareable by explicit opt-in: a site owns it, or a declared group does.
     *
     * Clients, products, price lists and person master data are declared here. Sharing is still an act —
     * a resource stays site-owned until an operator widens it — but the wider owner is representable.
     *
     * @since  2.0.0
     */
    case SiteOrGroup = 'site_or_group';

    /**
     * The category may additionally be owned by the installation, which needs a global human grant.
     *
     * Reserved for installation-wide machinery — the runtime map, the trust key ring — whose resources
     * belong to the installation rather than to any business in it.
     *
     * @since  2.0.0
     */
    case SiteGroupOrInstallation = 'site_group_or_installation';

    /**
     * Whether a category under this rule may be owned at a level.
     *
     * @param   OwnershipScopeLevel  $level  Level an ownership row would be written at.
     *
     * @return  bool  True only when this rule admits the level.
     *
     * @since   2.0.0
     */
    public function permits(OwnershipScopeLevel $level): bool
    {
        return match ($this) {
            self::SiteOnly => $level === OwnershipScopeLevel::Site,
            self::SiteOrGroup => $level !== OwnershipScopeLevel::Installation,
            self::SiteGroupOrInstallation => true,
        };
    }

    /**
     * The levels this rule admits, widest last.
     *
     * @return  list<OwnershipScopeLevel>  Permitted levels in order of reach, for documentation and
     *          administration screens that show an operator what a category may become.
     *
     * @since   2.0.0
     */
    public function levels(): array
    {
        return match ($this) {
            self::SiteOnly => [OwnershipScopeLevel::Site],
            self::SiteOrGroup => [OwnershipScopeLevel::Site, OwnershipScopeLevel::Group],
            self::SiteGroupOrInstallation => [
                OwnershipScopeLevel::Site,
                OwnershipScopeLevel::Group,
                OwnershipScopeLevel::Installation,
            ],
        };
    }
}
