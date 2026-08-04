<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Domain;

use Kumwe\CMS\Content\Domain\ContentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentStatus::class)]
final class ContentStatusTest extends TestCase
{
    public function testOnlyPublishedStatusIsPublic(): void
    {
        self::assertTrue(ContentStatus::Published->isPublic());
        self::assertFalse(ContentStatus::Draft->isPublic());
        self::assertFalse(ContentStatus::Review->isPublic());
        self::assertFalse(ContentStatus::Archived->isPublic());
    }
}
