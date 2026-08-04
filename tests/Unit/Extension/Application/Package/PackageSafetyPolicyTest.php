<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Application\Package;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Package\ArchiveEntry;
use Kumwe\CMS\Extension\Application\Package\ArchiveEntryType;
use Kumwe\CMS\Extension\Application\Package\ArchivePackage;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Package\UnsafePackage;
use Kumwe\CMS\Extension\Domain\PackagePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArchiveEntry::class)]
#[CoversClass(ArchivePackage::class)]
#[CoversClass(PackageSafetyPolicy::class)]
final class PackageSafetyPolicyTest extends TestCase
{
    public function testAcceptsAUniqueBoundedPackageWithRootManifest(): void
    {
        $package = new ArchivePackage([
            $this->file('kumwe.json', 50, 100),
            $this->file('src/Provider.php', 100, 200),
        ]);

        (new PackageSafetyPolicy())->assertSafe($package);
        self::addToAssertionCount(1);
    }

    public function testRejectsLinksDuplicatePathsAndCompressionBombs(): void
    {
        $unsafePackages = [
            new ArchivePackage([
                $this->file('kumwe.json', 50, 100),
                new ArchiveEntry(PackagePath::fromString('link'), ArchiveEntryType::SymbolicLink, 1, 1),
            ]),
            new ArchivePackage([
                $this->file('kumwe.json', 50, 100),
                $this->file('KUMWE.JSON', 50, 100),
            ]),
            new ArchivePackage([
                $this->file('kumwe.json', 1, 101),
            ]),
        ];

        foreach ($unsafePackages as $package) {
            try {
                (new PackageSafetyPolicy())->assertSafe($package);
                self::fail('Expected the unsafe package to be rejected.');
            } catch (UnsafePackage) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsImpossibleArchiveEntrySizes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ArchiveEntry(PackagePath::fromString('directory'), ArchiveEntryType::Directory, 1, 0);
    }

    private function file(string $path, int $compressed, int $uncompressed): ArchiveEntry
    {
        return new ArchiveEntry(
            PackagePath::fromString($path),
            ArchiveEntryType::File,
            $compressed,
            $uncompressed,
        );
    }
}
