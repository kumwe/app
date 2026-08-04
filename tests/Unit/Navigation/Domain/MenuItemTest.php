<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Navigation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Navigation\Domain\MenuItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MenuItem::class)]
final class MenuItemTest extends TestCase
{
    private const ITEM_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb161';
    private const PARENT_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb162';

    public function testCreatesDetachedValidatedItem(): void
    {
        $item = MenuItem::create(self::ITEM_ID, '  Documentation  ', 'documentation', self::PARENT_ID);

        self::assertSame(self::ITEM_ID, $item->id());
        self::assertSame('Documentation', $item->title());
        self::assertSame('documentation', $item->slug());
        self::assertSame(self::PARENT_ID, $item->parentId());
        self::assertSame('', $item->path());
    }

    public function testPlacementReturnsNewItemWithoutChangingOriginal(): void
    {
        $original = MenuItem::create(self::ITEM_ID, 'Documentation', 'documentation');
        $placed = $original->placedAt(self::PARENT_ID, '/company/documentation');

        self::assertNull($original->parentId());
        self::assertSame('', $original->path());
        self::assertSame(self::PARENT_ID, $placed->parentId());
        self::assertSame('/company/documentation', $placed->path());
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MenuItem::create(self::ITEM_ID, 'Documentation', '../documentation');
    }
}
