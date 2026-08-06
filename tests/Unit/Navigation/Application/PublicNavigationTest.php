<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Navigation\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicNavigation::class)]
#[UsesClass(MenuItemRecord::class)]
#[UsesClass(MenuRecord::class)]
#[UsesClass(AuthorizationResource::class)]
#[UsesClass(SiteContext::class)]
final class PublicNavigationTest extends TestCase
{
    public function testBuildsTheManagedMainMenuRecursivelyInPositionOrder(): void
    {
        $repository = $this->createStub(NavigationRepository::class);
        $time = new DateTimeImmutable('2026-08-06T12:00:00+00:00');
        $menu = new MenuRecord('main-menu', 'main', 'Main menu', 1, $time, $time);
        $repository->method('menus')->willReturn([
            new MenuRecord('foreign', 'main', 'Other site menu', 1, $time, $time),
            $menu,
        ]);
        $repository->method('items')->with('main-menu')->willReturn([
            new MenuItemRecord('about', 'main-menu', null, 'About', 'about', '/about', 20, 1, $time, $time),
            new MenuItemRecord('team', 'main-menu', 'about', 'Team', 'team', '/about/team', 10, 1, $time, $time),
            new MenuItemRecord('home', 'main-menu', null, 'Home', 'home', '/home', 10, 1, $time, $time),
        ]);

        $ownership = new class implements ResourceSiteOwnership {
            public function siteFor(AuthorizationResource $resource): SiteContext
            {
                return $resource->identifier() === 'foreign'
                    ? SiteContext::fromString('other-site')
                    : SiteContext::default();
            }
        };
        $items = (new PublicNavigation($repository, $ownership, SiteContext::default()))->items();

        self::assertSame(['Home', 'About'], array_column($items, 'title'));
        self::assertSame('Team', $items[1]['children'][0]['title']);
        self::assertSame('/pages/team', $items[1]['children'][0]['href']);
    }
}
