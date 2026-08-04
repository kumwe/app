<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationWindow::class)]
final class PublicationWindowTest extends TestCase
{
    public function testUnboundedWindowContainsAnyInstant(): void
    {
        self::assertTrue(PublicationWindow::unbounded()->contains(new DateTimeImmutable('2050-01-01T00:00:00Z')));
    }

    public function testStartIsInclusiveAndEndIsExclusive(): void
    {
        $window = new PublicationWindow(
            new DateTimeImmutable('2026-08-04T10:00:00Z'),
            new DateTimeImmutable('2026-08-04T11:00:00Z'),
        );

        self::assertFalse($window->contains(new DateTimeImmutable('2026-08-04T09:59:59Z')));
        self::assertTrue($window->contains(new DateTimeImmutable('2026-08-04T10:00:00Z')));
        self::assertTrue($window->contains(new DateTimeImmutable('2026-08-04T10:59:59Z')));
        self::assertFalse($window->contains(new DateTimeImmutable('2026-08-04T11:00:00Z')));
    }

    public function testRejectsEmptyOrReversedWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PublicationWindow(
            new DateTimeImmutable('2026-08-04T11:00:00Z'),
            new DateTimeImmutable('2026-08-04T11:00:00Z'),
        );
    }
}
