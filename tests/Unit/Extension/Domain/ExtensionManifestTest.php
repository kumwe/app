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

    public function testParsesStrictSchemaTwoContributionContracts(): void
    {
        $manifest = ExtensionManifest::fromJson(<<<'JSON'
{
  "schema": 2,
  "name": "acme/editor",
  "type": "component",
  "version": "2.0.0",
  "provider": "Acme\\Editor\\Provider",
  "autoload": {"psr-4": {"Acme\\Editor\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"},
  "contributions": {
    "version": 1,
    "capabilities": [{
      "id": "acme.editor.manage",
      "label": "Manage editor",
      "description": "Open and manage the editor workspace."
    }],
    "administrator": {
      "workspaces": [{
        "id": "acme.editor.workspace",
        "label": "Editor",
        "description": "Editor operations",
        "priority": 300
      }],
      "navigation": [{
        "id": "acme.editor.navigation",
        "workspace": "acme.editor.workspace",
        "label": "Editor",
        "description": "Open editor",
        "path": "/",
        "icon": "content",
        "capability": "acme.editor.manage",
        "priority": 10,
        "keywords": "editor"
      }],
      "routes": [{
        "name": "acme.editor.index",
        "path": "/",
        "methods": ["GET"],
        "capability": "acme.editor.manage",
        "view": "acme.editor.index"
      }],
      "views": [{"name": "acme.editor.index", "template": "index.twig"}]
    }
  }
}
JSON);

        self::assertSame(2, $manifest->schemaVersion());
        self::assertSame(['acme.editor.manage'], $manifest->permissions());
        self::assertSame('acme.editor.index', $manifest->contributions()->routes()[0]->name);
        self::assertSame('index.twig', $manifest->contributions()->views()[0]->template);
    }

    public function testSchemaTwoRejectsUnknownAndForeignOwnedContributions(): void
    {
        $json = str_replace(
            '"schema": 1,',
            '"schema": 2, "unknown": true,',
            $this->manifestJson(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key unknown');
        ExtensionManifest::fromJson($json);
    }

    public function testSchemaOneRemainsPermissiveAndHasNoTypedShellContributions(): void
    {
        $manifest = ExtensionManifest::fromJson(str_replace(
            '"schema": 1,',
            '"schema": 1, "legacy_package_metadata": true,',
            $this->manifestJson(),
        ));

        self::assertSame(1, $manifest->schemaVersion());
        self::assertSame([], $manifest->contributions()->capabilities());
        self::assertSame([], $manifest->contributions()->routes());
    }

    public function testSchemaTwoRejectsForeignContributionOwnership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot claim capability identifier foreign.manage');

        ExtensionManifest::fromJson(<<<'JSON'
{
  "schema": 2,
  "name": "acme/editor",
  "type": "component",
  "version": "2.0.0",
  "provider": "Acme\\Editor\\Provider",
  "autoload": {"psr-4": {"Acme\\Editor\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"},
  "contributions": {
    "version": 1,
    "capabilities": [{
      "id": "foreign.manage",
      "label": "Foreign",
      "description": "Invalid foreign ownership."
    }],
    "administrator": {}
  }
}
JSON);
    }

    public function testSchemaTwoRejectsUnknownNestedKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requirements object contains unknown key platform');

        ExtensionManifest::fromJson(<<<'JSON'
{
  "schema": 2,
  "name": "acme/editor",
  "type": "component",
  "version": "2.0.0",
  "provider": "Acme\\Editor\\Provider",
  "autoload": {"psr-4": {"Acme\\Editor\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0", "platform": "unknown"},
  "contributions": {"version": 1, "capabilities": [], "administrator": {}}
}
JSON);
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
