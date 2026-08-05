<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineResourceSiteOwnership;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineResourceSiteOwnership::class)]
final class DoctrineResourceSiteOwnershipTest extends TestCase
{
    public function testReadsPersistedCrossSiteOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn('corporate');

        $site = (new DoctrineResourceSiteOwnership(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->siteFor(AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'));

        self::assertSame('corporate', $site->identifier());
    }

    public function testUnknownResourceFailsClosedWithoutCreatingOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn(false);
        $database->expects(self::never())->method('insert');

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        (new DoctrineResourceSiteOwnership(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->siteFor(AuthorizationResource::item('user', '018f22e2-7c8b-7ab0-8f3a-88e8026bb499'));
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
