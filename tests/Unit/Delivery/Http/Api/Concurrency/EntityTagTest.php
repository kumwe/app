<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTag::class)]
final class EntityTagTest extends TestCase
{
    public function testUsesStrongComparisonForVersionTags(): void
    {
        $current = EntityTag::fromVersion(8);

        self::assertSame('"v8"', (string) $current);
        self::assertTrue($current->stronglyEquals(EntityTag::fromHeader('"v8"')));
        self::assertFalse($current->stronglyEquals(EntityTag::fromHeader('W/"v8"')));
    }

    public function testRejectsUnquotedTags(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntityTag::fromHeader('v8');
    }
}
