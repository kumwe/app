<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Domain;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capability::class)]
final class CapabilityTest extends TestCase
{
    public function testNormalizesAndComparesCapabilities(): void
    {
        $capability = Capability::fromString(' Content.Article-Publish ');

        self::assertSame('content.article-publish', $capability->value());
        self::assertSame('content.article-publish', (string) $capability);
        self::assertTrue($capability->equals(Capability::fromString('content.article-publish')));
        self::assertFalse($capability->equals(Capability::fromString('content.article.read')));
    }

    public function testRejectsUnstableIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Capability::fromString('content article publish');
    }
}
