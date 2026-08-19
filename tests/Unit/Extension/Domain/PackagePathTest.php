<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\PackagePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackagePath::class)]
final class PackagePathTest extends TestCase
{
    public function testAcceptsAStableRelativePath(): void
    {
        self::assertSame('src/Provider.php', (string) PackagePath::fromString('src/Provider.php'));
    }

    public function testRejectsTraversalAndPlatformSeparators(): void
    {
        foreach (['../secret', 'src/../secret', '/absolute', 'C:/windows', 'src\\file.php'] as $path) {
            try {
                PackagePath::fromString($path);
                self::fail(sprintf('Expected path %s to be rejected.', $path));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
