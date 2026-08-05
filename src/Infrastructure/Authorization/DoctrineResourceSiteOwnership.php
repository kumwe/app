<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/** Read-only resolver for the durable resource-to-site registry. */
final readonly class DoctrineResourceSiteOwnership implements ResourceSiteOwnership
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function siteFor(AuthorizationResource $resource): SiteContext
    {
        if ($resource->identifier() === '*' || $this->isIntrinsic($resource)) {
            return SiteContext::default();
        }

        $site = $this->lookup($resource);
        if ($site === null) {
            throw new AuthorizationResourceOwnershipUnknown($resource);
        }

        return SiteContext::fromString($site);
    }

    private function lookup(AuthorizationResource $resource): ?string
    {
        $site = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resource->type(), $resource->identifier()]);

        return is_string($site) && $site !== '' ? $site : null;
    }

    private function isIntrinsic(AuthorizationResource $resource): bool
    {
        return match ($resource->type()) {
            'administrator' => true,
            'database_schema' => $resource->identifier() === 'current',
            'extension_runtime_map' => $resource->identifier() === 'active',
            // Queues are configured transport partitions, not durable business resources.
            'queue' => true,
            'site' => $resource->identifier() === SiteContext::DEFAULT,
            'theme' => in_array($resource->identifier(), ['site', 'administrator'], true),
            default => false,
        };
    }
}
