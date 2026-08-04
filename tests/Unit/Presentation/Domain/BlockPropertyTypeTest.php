<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use Kumwe\CMS\Presentation\Domain\BlockPropertyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockPropertyType::class)]
final class BlockPropertyTypeTest extends TestCase
{
    public function testAcceptsOnlyExactDeclaredPropertyShape(): void
    {
        self::assertTrue(BlockPropertyType::String->accepts('text'));
        self::assertFalse(BlockPropertyType::String->accepts(10));
        self::assertTrue(BlockPropertyType::Integer->accepts(10));
        self::assertTrue(BlockPropertyType::Number->accepts(10.5));
        self::assertFalse(BlockPropertyType::Number->accepts(INF));
        self::assertTrue(BlockPropertyType::Object->accepts(['name' => 'value']));
        self::assertTrue(BlockPropertyType::List->accepts(['one', 'two']));
    }
}
