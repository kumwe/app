<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\OwnershipScopeLevel;
use Kumwe\CMS\Application\Authorization\ResourceOwnershipReferences;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Finds sites that would be stranded because one of their roles is granted authority over the resource.
 *
 * This is the reference core itself owns and can therefore always check. A capability grant may name an
 * exact resource — `GrantScope::named('client', '…')` — and the role holding it belongs to a site. If
 * that site is about to lose reach into the resource, the grant is left pointing at something the site
 * can no longer see: authority that resolves to nothing, which is worse than authority that was never
 * given, because nobody notices it stopped working.
 *
 * Only site-scoped role ownership is considered a reference. A role owned by a group is not stranded by
 * a narrowing that leaves the group intact, and an installation-owned role reaches the resource through
 * a global grant rather than through the group.
 *
 * @since  2.0.0
 */
final readonly class DoctrineGrantScopeOwnershipReferences implements ResourceOwnershipReferences
{
    /**
     * Bind the inspector to the connection and table map its lookup runs against.
     *
     * @param  Connection  $database  DBAL connection the grants and ownership rows are read from.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the grant and ownership
     *         tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Name the sites, among those about to lose reach, holding a grant that points at the resource.
     *
     * @param   AuthorizationResource  $resource  Resource whose owning scope is about to narrow.
     * @param   list<string>           $sites     Site identifiers that would lose reach; never empty.
     *
     * @return  list<string>  The subset holding such a grant, in site-identifier order.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    public function sitesReferencing(AuthorizationResource $resource, array $sites): array
    {
        if ($sites === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sites), '?'));
        $postgres = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $roleId = $postgres ? 'CAST(g.role_id AS VARCHAR)' : 'g.role_id';
        $rows = $this->database->fetchFirstColumn(sprintf(
            'SELECT DISTINCT o.site_identifier FROM %s g INNER JOIN %s o '
            . "ON o.resource_type = 'role' AND o.resource_id = %s "
            . 'WHERE g.scope_type = ? AND g.scope_identifier = ? AND o.scope_level = ? '
            . 'AND o.site_identifier IN (%s) ORDER BY o.site_identifier',
            $this->tables->quoted('role_capability_grants'),
            $this->tables->quoted('resource_site_ownership'),
            $roleId,
            $placeholders,
        ), [
            $resource->type(),
            $resource->identifier(),
            OwnershipScopeLevel::Site->value,
            ...$sites,
        ]);

        $referencing = [];
        foreach ($rows as $row) {
            if (is_string($row) && $row !== '') {
                $referencing[] = $row;
            }
        }

        return $referencing;
    }
}
