<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionType;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionManifest::class)]
final class ExtensionManifestTest extends TestCase
{
    public function testParsesACompatibleManifestWithTypedDependencies(): void
    {
        $manifest = ExtensionManifest::fromJson($this->manifestJson());

        self::assertSame('acme/editor', $manifest->identifier()->value());
        self::assertSame(ExtensionType::Plugin, $manifest->type());
        self::assertSame('Acme\\Editor\\Provider', $manifest->serviceProvider());
        self::assertSame(['Acme\\Editor\\' => 'src/'], $manifest->autoload());
        self::assertCount(1, $manifest->dependencies());
        self::assertTrue($manifest->supports(
            SemanticVersion::fromString('2.4.0'),
            SemanticVersion::fromString('8.4.1'),
        ));
    }

    public function testRejectsSelfDependencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExtensionManifest::fromJson(str_replace('acme/library', 'acme/editor', $this->manifestJson()));
    }

    private function manifestJson(): string
    {
        return <<<'JSON'
{
  "schema": 1,
  "name": "acme/editor",
  "type": "plugin",
  "version": "1.2.3",
  "provider": "Acme\\Editor\\Provider",
  "autoload": {"psr-4": {"Acme\\Editor\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.4.0"},
  "dependencies": [{"name": "acme/library", "constraint": "^1.0.0", "optional": false}]
}
JSON;
    }
}
