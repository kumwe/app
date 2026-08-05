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

/** Read-only resolver for the durable resource-to-site registry. */
final readonly class DoctrineResourceSiteOwnership implements ResourceSiteOwnership
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

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
