<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\App\Application\Authorization\SiteGroupUnknown;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnership;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineResourceSiteOwnership::class)]
final class DoctrineResourceSiteOwnershipTest extends TestCase
{
    public function testReadsPersistedCrossSiteOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Site->value,
            'group_identifier' => null,
            'enabled_site' => 'corporate',
        ]);

        $scope = $this->ownership($database)
            ->scopeFor(AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'));

        self::assertSame('corporate', $scope->identifier);
        self::assertSame(OwnershipScopeLevel::Site, $scope->level);
    }

    public function testUnknownResourceFailsClosedWithoutCreatingOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $database->expects(self::never())->method('insert');

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        $this->ownership($database)
            ->scopeFor(AuthorizationResource::item('user', '018f22e2-7c8b-7ab0-8f3a-88e8026bb499'));
    }

    public function testDisabledOwningSiteStopsResolvingWithoutFallingBackToTheCaller(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Site->value,
            'group_identifier' => null,
            'enabled_site' => null,
        ]);

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        $this->ownership($database)
            ->scopeFor(AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'));
    }

    public function testGroupOwnedResourceResolvesToTheDeclaredMembership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Group->value,
            'group_identifier' => 'kumwe-group',
            'enabled_site' => null,
        ]);

        $scope = $this->ownership($database, new SiteGroup('kumwe-group', 'Kumwe group', [
            'manufacturing',
            'retail',
        ]))->scopeFor(AuthorizationResource::item('person', '018f22e2-7c8b-7ab0-8f3a-88e8026bb501'));

        self::assertSame(OwnershipScopeLevel::Group, $scope->level);
        self::assertSame(['manufacturing', 'retail'], $scope->sites);
    }

    public function testGroupWithNoEnabledMemberFailsClosedRatherThanResolvingToNobody(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Group->value,
            'group_identifier' => 'withdrawn-group',
            'enabled_site' => null,
        ]);

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        $this->ownership($database)
            ->scopeFor(AuthorizationResource::item('person', '018f22e2-7c8b-7ab0-8f3a-88e8026bb501'));
    }

    private function ownership(Connection $database, ?SiteGroup $group = null): DoctrineResourceSiteOwnership
    {
        return new DoctrineResourceSiteOwnership(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->groupRegistry($group),
        );
    }

    private function groupRegistry(?SiteGroup $group): SiteGroupRegistry
    {
        return new class ($group) implements SiteGroupRegistry {
            public function __construct(private ?SiteGroup $group)
            {
            }

            public function group(string $identifier): SiteGroup
            {
                if ($this->group === null || $this->group->identifier !== $identifier) {
                    throw new SiteGroupUnknown($identifier);
                }

                return $this->group;
            }

            public function all(): array
            {
                return $this->group === null ? [] : [$this->group];
            }
        };
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
