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

final readonly class DoctrineResourceSiteOwnershipWriter implements ResourceSiteOwnershipWriter
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function record(AuthorizationResource $resource, SiteContext $site): void
    {
        $this->assertItem($resource);

        $this->database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $resource->type(),
            'resource_id' => $resource->identifier(),
            'site_identifier' => $site->identifier(),
        ]);
    }

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

    private function assertItem(AuthorizationResource $resource): void
    {
        if ($resource->identifier() === '*') {
            throw new \InvalidArgumentException('Collection resources cannot have an ownership record.');
        }
    }
}
