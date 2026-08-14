<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Authoritative answer to which sites a declared group currently contains.
 *
 * Group membership is administrative state — an operator adds or removes a site, and that is a rare,
 * audited event — so an implementation is expected to resolve the whole declared set once and answer
 * from it, never to issue a query per authorization decision. Membership must be reported fail-closed
 * on the same terms as site ownership: a site an operator has disabled is not a member for the purpose
 * of a decision, and a group nobody currently belongs to does not resolve at all.
 *
 * @since  2.0.0
 */
interface SiteGroupRegistry
{
    /**
     * Resolve one declared group and the sites it currently contains.
     *
     * @param   string  $identifier  Group identifier stored on an ownership row or named by an operator.
     *
     * @return  SiteGroup  The declared group, restricted to the sites that are currently enabled.
     *
     * @throws  SiteGroupUnknown  When no such group is declared, or none of its members is enabled.
     *
     * @since   2.0.0
     */
    public function group(string $identifier): SiteGroup;

    /**
     * List every declared group that currently resolves.
     *
     * @return  list<SiteGroup>  Groups in identifier order; empty on an installation that declares none.
     *
     * @since   2.0.0
     */
    public function all(): array;
}
