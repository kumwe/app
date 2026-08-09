<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Read side of the resource-to-site registry, answered from the prefixed `resource_site_ownership` table.
 *
 * This is the `ResourceSiteOwnership` the container wires behind `DenyByDefaultAuthorizationGateway`, so
 * every cross-site denial in the installation rests on what it returns. Three families of resource never
 * reach the database: a `site` resource is its own owner, a collection has no single row to own, and the
 * installation-wide types `isIntrinsic()` lists belong to the default site. Everything else must produce a
 * stored row joined to an enabled `sites` entry; a resource with no such row is reported as unowned rather
 * than credited to the calling site, which is what makes the gateway fail closed instead of handing a
 * caller any resource it happens to name. Rows are maintained by `DoctrineResourceSiteOwnershipWriter` —
 * nothing here writes, and no missing record is ever repaired on read.
 *
 * @since  2.0.0
 */
final readonly class DoctrineResourceSiteOwnership implements ResourceSiteOwnership
{
    /**
     * Bind the resolver to the connection and table map its lookup runs against.
     *
     * @param  Connection  $database  DBAL connection the ownership rows are read from.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the `resource_site_ownership`
     *         and `sites` tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Resolve the site that owns a resource, from stored records rather than from the caller.
     *
     * A `site` resource resolves to itself, and a collection or an intrinsic installation-wide resource
     * resolves to the default site, all without touching the database. Every other resource is looked up,
     * and an answer that does not come back — no row, or a row naming a site an operator has disabled — is
     * reported as unknown rather than guessed at.
     *
     * @param   AuthorizationResource  $resource  Target whose owning site is being established.
     *
     * @return  SiteContext  The owning site, which the gateway compares against the site the caller runs in.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When no enabled site is recorded as owning the resource.
     * @throws  \InvalidArgumentException  When a `site` resource carries an identifier that is not a valid
     *          site identifier, since `AuthorizationResource` accepts a wider alphabet than `SiteContext`.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the ownership lookup.
     *
     * @since   2.0.0
     */
    public function siteFor(AuthorizationResource $resource): SiteContext
    {
        if ($resource->type() === 'site') {
            return SiteContext::fromString($resource->identifier());
        }
        if ($resource->identifier() === '*' || $this->isIntrinsic($resource)) {
            return SiteContext::default();
        }

        $site = $this->lookup($resource);
        if ($site === null) {
            throw new AuthorizationResourceOwnershipUnknown($resource);
        }

        return SiteContext::fromString($site);
    }

    /**
     * Read the site identifier recorded against a resource, restricted to sites that are enabled.
     *
     * The inner join on `sites` is what lets an operator withdraw a whole site without deleting anything:
     * the ownership rows survive, but they stop resolving, so `siteFor()` fails closed for every resource
     * that site owns. A stored identifier that is not a non-empty string is treated as no answer at all.
     *
     * @param   AuthorizationResource  $resource  Target whose ownership row is being read.
     *
     * @return  ?string  Identifier of the owning site, or null when no enabled site claims the resource.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    private function lookup(AuthorizationResource $resource): ?string
    {
        $site = $this->database->fetchOne(sprintf(
            'SELECT o.site_identifier FROM %s o INNER JOIN %s s ON s.identifier = o.site_identifier '
            . 'WHERE o.resource_type = ? AND o.resource_id = ? AND s.enabled = ?',
            $this->tables->quoted('resource_site_ownership'),
            $this->tables->quoted('sites'),
        ), [$resource->type(), $resource->identifier(), true], [
            Types::STRING,
            Types::STRING,
            Types::BOOLEAN,
        ]);

        return is_string($site) && $site !== '' ? $site : null;
    }

    /**
     * Decide whether a resource belongs to the installation itself rather than to one of its sites.
     *
     * These types are never given a `resource_site_ownership` row, so without this test they would all
     * resolve as unowned and be denied. `database_schema`, `extension_runtime_map` and `theme` qualify only
     * for their singleton identifiers, which keeps any other identifier under those types on the ordinary
     * lookup path instead of granting it the default site by accident.
     *
     * @param   AuthorizationResource  $resource  Target being classified.
     *
     * @return  bool  True when the resource is installation-wide and therefore owned by the default site.
     *
     * @since   2.0.0
     */
    private function isIntrinsic(AuthorizationResource $resource): bool
    {
        return match ($resource->type()) {
            'administrator' => true,
            'automation_installation' => true,
            'database_schema' => $resource->identifier() === 'current',
            'extension_runtime_map' => $resource->identifier() === 'active',
            'extension_trust_key' => true,
            // Queues are configured transport partitions, not durable business resources.
            'queue' => true,
            'theme' => in_array($resource->identifier(), ['site', 'administrator'], true),
            default => false,
        };
    }
}
