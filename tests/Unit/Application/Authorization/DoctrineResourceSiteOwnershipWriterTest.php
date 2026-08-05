<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipConflict;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineResourceSiteOwnershipWriter::class)]
#[CoversClass(ResourceSiteOwnershipConflict::class)]
final class DoctrineResourceSiteOwnershipWriterTest extends TestCase
{
    public function testRecordsAuthoritativeOwnership(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('insert')->with(
            'kumwe_resource_site_ownership',
            [
                'resource_type' => 'content',
                'resource_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb402',
                'site_identifier' => 'corporate',
            ],
        );

        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->record(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testRemovesOnlyOwnershipForTheExpectedSite(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->with(
            'DELETE FROM kumwe_resource_site_ownership '
                . 'WHERE resource_type = ? AND resource_id = ? AND site_identifier = ?',
            ['content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402', 'corporate'],
        )->willReturn(1);
        $database->expects(self::never())->method('fetchOne');

        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testCrossSiteOwnershipCannotBeRemoved(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::once())->method('fetchOne')->willReturn('subsidiary');

        $this->expectException(ResourceSiteOwnershipConflict::class);
        $this->expectExceptionMessage('because it belongs to site subsidiary');
        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testMissingOwnershipCannotBeSilentlyRemoved(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::once())->method('fetchOne')->willReturn(false);

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }
}
