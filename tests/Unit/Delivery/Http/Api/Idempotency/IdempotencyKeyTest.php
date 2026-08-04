<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    public function testPreservesAndSafelyComparesTransportKeys(): void
    {
        $key = IdempotencyKey::fromHeader('request-1234');

        self::assertSame('request-1234', (string) $key);
        self::assertTrue($key->equals(IdempotencyKey::fromHeader('request-1234')));
        self::assertFalse($key->equals(IdempotencyKey::fromHeader('request-5678')));
    }

    public function testRejectsShortKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromHeader('short');
    }
}
