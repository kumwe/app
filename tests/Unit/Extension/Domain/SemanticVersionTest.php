<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\SemanticVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SemanticVersion::class)]
final class SemanticVersionTest extends TestCase
{
    public function testUsesSemanticVersionPrecedenceAndIgnoresBuildMetadata(): void
    {
        $alpha = SemanticVersion::fromString('2.0.0-alpha.1+build.7');
        $release = SemanticVersion::fromString('2.0.0+release.1');

        self::assertTrue($alpha->isPreRelease());
        self::assertLessThan(0, $alpha->compare($release));
        self::assertSame(0, $release->compare(SemanticVersion::fromString('2.0.0+other')));
        self::assertSame('2.0.0-alpha.1+build.7', (string) $alpha);
    }

    public function testRejectsLeadingZeroes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SemanticVersion::fromString('02.0.0');
    }

    public function testRejectsCoreComponentsOutsideThePlatformIntegerRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SemanticVersion::fromString(PHP_INT_MAX . '0.0.0');
    }
}
