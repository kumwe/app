<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Navigation\Application;

use DateTimeImmutable;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Navigation\Application\NavigationVersionConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(NavigationService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(MenuItemRecord::class)]
#[UsesClass(MenuRecord::class)]
final class NavigationServiceTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const MENU = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
    private const ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';
    private const PARENT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb403';

    public function testCreatesNormalizedMenuInsideAuditedTransaction(): void
    {
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('insertMenu')->with(self::callback(
            static fn (MenuRecord $menu): bool => $menu->handle === 'main_menu'
                && $menu->title === 'Main menu'
                && $menu->version === 1,
        ));
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === self::ACTOR
                && $event->action() === 'navigation.menu.create'
                && $event->subjectType() === 'menu',
        ));

        $menu = $this->service($repository, $audit)->createMenu(self::ACTOR, ' Main_Menu ', ' Main menu ');

        self::assertSame('main_menu', $menu->handle);
        self::assertSame('Main menu', $menu->title);
        self::assertSame(1, $menu->version);
    }

    public function testMovesDescendantPathsWhenParentOrSlugChanges(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $stored = new MenuItemRecord(
            self::ITEM,
            self::MENU,
            null,
            'Guides',
            'guides',
            '/guides',
            0,
            4,
            $now->modify('-1 day'),
            $now->modify('-1 hour'),
        );
        $repository = $this->createMock(NavigationRepository::class);
        $repository->method('item')->with(self::ITEM)->willReturn($stored);
        $repository->expects(self::once())->method('assertMoveIsAcyclic')->with(
            self::ITEM,
            self::MENU,
            self::PARENT,
        );
        $repository->expects(self::once())->method('pathForParent')->with(
            self::MENU,
            self::PARENT,
            'documentation',
        )->willReturn('/resources/documentation');
        $repository->expects(self::once())->method('updateItem')->with(self::callback(
            static fn (MenuItemRecord $item): bool => $item->path === '/resources/documentation'
                && $item->version === 5,
        ), 4);
        $repository->expects(self::once())->method('moveDescendantPaths')->with(
            self::ITEM,
            '/guides',
            '/resources/documentation',
            $now,
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'navigation.item.update'
                && $event->metadata()['version'] === 5,
        ));

        $updated = $this->service($repository, $audit, $now)->updateItem(
            self::ACTOR,
            self::ITEM,
            4,
            self::PARENT,
            'Documentation',
            'documentation',
            2,
        );

        self::assertSame('/resources/documentation', $updated->path);
        self::assertSame(5, $updated->version);
    }

    public function testRejectsStaleVersionBeforeMoveOrWrite(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->method('item')->willReturn(new MenuItemRecord(
            self::ITEM,
            self::MENU,
            null,
            'Guides',
            'guides',
            '/guides',
            0,
            4,
            $now,
            $now,
        ));
        $repository->expects(self::never())->method('assertMoveIsAcyclic');
        $repository->expects(self::never())->method('updateItem');

        $this->expectException(NavigationVersionConflict::class);

        $this->service($repository, $this->createStub(AuditRecorder::class), $now)->updateItem(
            self::ACTOR,
            self::ITEM,
            3,
            null,
            'Guides',
            'guides',
            0,
        );
    }

    private function service(
        NavigationRepository $repository,
        AuditRecorder $audit,
        ?DateTimeImmutable $now = null,
    ): NavigationService {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-08-04T10:00:00+00:00'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new NavigationService($repository, $audit, $transactions, $clock);
    }
}
