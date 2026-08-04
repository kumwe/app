<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionRecord;
use Kumwe\CMS\Extension\Domain\ExtensionStatus;
use Kumwe\CMS\Extension\Domain\ExtensionType;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use Kumwe\CMS\Extension\Domain\VersionConstraint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionRecord::class)]
final class ExtensionRecordTest extends TestCase
{
    public function testInstallActivationAndUpgradeAreExplicitlyVersioned(): void
    {
        $record = ExtensionRecord::install($this->manifest('1.0.0'));

        self::assertSame(ExtensionStatus::Disabled, $record->status());
        $record->activate();
        $record->activate();
        $record->upgrade($this->manifest('1.1.0'));

        self::assertSame(ExtensionStatus::Disabled, $record->status());
        self::assertSame('1.1.0', (string) $record->installedVersion());
        self::assertSame(2, $record->registryVersion());
    }

    public function testRejectsDowngrades(): void
    {
        $record = ExtensionRecord::install($this->manifest('1.0.0'));
        $this->expectException(InvalidArgumentException::class);

        $record->upgrade($this->manifest('0.9.0'));
    }

    private function manifest(string $version): ExtensionManifest
    {
        return new ExtensionManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            ExtensionType::Plugin,
            SemanticVersion::fromString($version),
            'Acme\\Editor\\Provider',
            VersionConstraint::fromString('^2.0.0'),
            VersionConstraint::fromString('^8.4.0'),
        );
    }
}
