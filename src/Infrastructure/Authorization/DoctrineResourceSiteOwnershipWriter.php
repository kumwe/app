<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipConflict;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Write side of the resource-to-site registry, kept in the prefixed `resource_site_ownership` table.
 *
 * This is the `ResourceSiteOwnershipWriter` the container wires, and the only supported way to create or
 * withdraw the rows `DoctrineResourceSiteOwnership` reads. It opens no transaction of its own, so a caller
 * must invoke it inside the transaction that creates or deletes the resource itself; that is what keeps a
 * resource and its ownership row from ever existing apart. Withdrawal matches on the expected owner as well
 * as on the resource, so a caller acting for the wrong site removes nothing and is told which site actually
 * holds the record, and a resource with no record at all is reported rather than passed over. Collection
 * targets are refused outright, since `*` names a family and no single row owns it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineResourceSiteOwnershipWriter implements ResourceSiteOwnershipWriter
{
    /**
     * Bind the writer to the connection and table map its statements run against.
     *
     * @param  Connection  $database  DBAL connection the ownership rows are written on; the caller's open
     *         transaction, when there is one, is the scope these writes join.
     * @param  TableNames  $tables    Resolver applying the configured prefix to `resource_site_ownership`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Insert the row that ties a newly created resource to the site that owns it.
     *
     * The table is keyed on the resource type and identifier, so a second record for the same resource is
     * rejected by the driver instead of quietly reassigning it to another site.
     *
     * @param   AuthorizationResource  $resource  Resource being created; must name one item, not a collection.
     * @param   SiteContext            $site      Site that owns it for the rest of its life.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource is a collection, which has no ownership to record.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert, a duplicate ownership row
     *          included.
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void
    {
        $this->assertItem($resource);

        $this->database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $resource->type(),
            'resource_id' => $resource->identifier(),
            'site_identifier' => $site->identifier(),
        ]);
    }

    /**
     * Withdraw the ownership row for a resource being deleted, and only for the site that owns it.
     *
     * The expected site is part of the delete's match, so exactly one affected row proves the caller was
     * right about the owner and the method returns. Any other count triggers a second read on the resource
     * alone, purely to separate the two outcomes worth telling apart: a record that survives because it
     * names a different site, and no record at all. The affected count is compared as a string because a
     * driver may report it as a numeric string rather than an int.
     *
     * @param   AuthorizationResource  $resource      Resource being deleted; must name one item, not a
     *          collection.
     * @param   SiteContext            $expectedSite  Site the caller believes owns the resource.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource is a collection, which has no ownership to remove.
     * @throws  ResourceSiteOwnershipConflict  When a record survives the delete because it names another site.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete or the follow-up lookup.
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
    {
        $this->assertItem($resource);
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE resource_type = ? AND resource_id = ? AND site_identifier = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resource->type(), $resource->identifier(), $expectedSite->identifier()]);

        if ((string) $affected === '1') {
            return;
        }

        $actualSite = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resource->type(), $resource->identifier()]);

        if (is_string($actualSite) && $actualSite !== '') {
            throw new ResourceSiteOwnershipConflict(
                $resource,
                $expectedSite,
                SiteContext::fromString($actualSite),
            );
        }

        throw new AuthorizationResourceOwnershipUnknown($resource);
    }

    /**
     * Refuse a target that names a whole resource family instead of one owned record.
     *
     * Guards both entry points before any statement runs, so a collection can neither create a row that
     * `DoctrineResourceSiteOwnership` would never look for nor match one that belongs to a real resource.
     *
     * @param   AuthorizationResource  $resource  Target the caller asked to record or withdraw ownership for.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource identifier is `*`.
     *
     * @since   2.0.0
     */
    private function assertItem(AuthorizationResource $resource): void
    {
        if ($resource->identifier() === '*') {
            throw new \InvalidArgumentException('Collection resources cannot have an ownership record.');
        }
    }
}
