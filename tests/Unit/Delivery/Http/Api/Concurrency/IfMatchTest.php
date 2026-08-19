<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IfMatch::class)]
final class IfMatchTest extends TestCase
{
    public function testMatchesWildcardsAndStrongTagLists(): void
    {
        $current = EntityTag::fromVersion(3);

        self::assertTrue(IfMatch::fromHeader('*')->matches($current));
        self::assertFalse(IfMatch::fromHeader('*')->matches($current, false));
        self::assertTrue(IfMatch::fromHeader('"v2", "v3"')->matches($current));
        self::assertFalse(IfMatch::fromHeader('"v2"')->matches($current));
    }

    public function testRejectsWeakPreconditions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IfMatch::fromHeader('W/"v3"');
    }
}
