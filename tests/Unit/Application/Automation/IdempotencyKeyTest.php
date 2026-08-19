<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\IdempotencyKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    public function testValidKeysRemainOpaqueAndComparable(): void
    {
        $key = IdempotencyKey::fromString('request:01989abc-def0');

        self::assertSame('request:01989abc-def0', $key->value());
        self::assertSame('request:01989abc-def0', (string) $key);
        self::assertTrue($key->equals(IdempotencyKey::fromString('request:01989abc-def0')));
        self::assertFalse($key->equals(IdempotencyKey::fromString('request:01989abc-def1')));
    }

    public function testWhitespaceAndShortKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromString('bad key');
    }
}
