<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionIdentifier::class)]
final class ExtensionIdentifierTest extends TestCase
{
    public function testNormalizesAndComparesVendorNames(): void
    {
        $identifier = ExtensionIdentifier::fromString(' Acme/Editor ');

        self::assertSame('acme/editor', (string) $identifier);
        self::assertTrue($identifier->equals(ExtensionIdentifier::fromString('acme/editor')));
    }

    public function testRejectsNamesWithoutVendorBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExtensionIdentifier::fromString('editor');
    }
}
