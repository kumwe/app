<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Domain;

use InvalidArgumentException;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Domain\VersionConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpectedVersion::class)]
#[UsesClass(VersionConflict::class)]
final class ExpectedVersionTest extends TestCase
{
    public function testMatchingVersionIsAccepted(): void
    {
        $version = new ExpectedVersion(3);

        $version->assertMatches(3);

        self::assertSame(3, $version->value());
    }

    public function testMismatchingVersionRaisesConflictWithBothVersions(): void
    {
        $this->expectException(VersionConflict::class);
        $this->expectExceptionMessage('Expected version 2, but the current version is 3.');

        (new ExpectedVersion(2))->assertMatches(3);
    }

    public function testRejectsNonPositiveVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExpectedVersion(0);
    }
}
