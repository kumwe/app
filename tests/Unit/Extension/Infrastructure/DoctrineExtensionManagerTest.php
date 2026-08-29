<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Infrastructure;

use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(DoctrineExtensionManager::class)]
final class DoctrineExtensionManagerTest extends TestCase
{
    public function testCallerMutationCannotChangePrivateArchiveSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-snapshot-' . bin2hex(random_bytes(8));
        mkdir($root . '/operation', 0700, true);
        $source = $root . '/caller.zip';
        $original = str_repeat('signed-package-bytes', 4096);
        file_put_contents($source, $original);
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $snapshot = $reflection->getMethod('snapshotArchive')->invoke($manager, $source, $root . '/operation');
        file_put_contents($source, 'attacker replacement after the snapshot boundary');

        self::assertIsString($snapshot);
        self::assertSame(hash('sha256', $original), hash_file('sha256', $snapshot));
        self::assertNotSame(hash_file('sha256', $source), hash_file('sha256', $snapshot));
    }

    /**
     * Field-presentation declarations carry the same live or dormant diagnostic state as their owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributionDiagnosticsMarkFieldPresentationsActive(): void
    {
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('contributionDiagnostics');
        $manifest = ExtensionManifest::fromJson(json_encode([
            'schema' => 3,
            'name' => 'acme/editor',
            'type' => 'component',
            'version' => '2.0.0',
            'provider' => 'Acme\\Editor\\Provider',
            'autoload' => ['psr-4' => ['Acme\\Editor\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'contributions' => [
                'version' => 1,
                'business' => [
                    'field_types' => [[
                        'id' => 'acme.editor.code',
                        'label' => 'Code',
                        'description' => 'A bounded extension-owned code.',
                        'value_type' => 'string',
                        'storage_type' => 'string',
                        'configuration_keys' => [],
                    ]],
                    'definitions' => [],
                    'field_presentations' => [[
                        'field_type' => 'acme.editor.code',
                        'contexts' => ['detail', 'update'],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $active = $method->invoke($manager, $manifest, true);
        $dormant = $method->invoke($manager, $manifest, false);

        self::assertIsArray($active);
        self::assertIsArray($dormant);
        $activeBusiness = $active['business'] ?? null;
        $dormantBusiness = $dormant['business'] ?? null;
        self::assertIsArray($activeBusiness);
        self::assertIsArray($dormantBusiness);
        $activePresentations = $activeBusiness['field_presentations'] ?? null;
        $dormantPresentations = $dormantBusiness['field_presentations'] ?? null;
        self::assertIsArray($activePresentations);
        self::assertIsArray($dormantPresentations);
        $activePresentation = $activePresentations[0] ?? null;
        $dormantPresentation = $dormantPresentations[0] ?? null;
        self::assertIsArray($activePresentation);
        self::assertIsArray($dormantPresentation);
        $activeFlag = $activePresentation['active'] ?? null;
        $dormantFlag = $dormantPresentation['active'] ?? null;
        self::assertIsBool($activeFlag);
        self::assertIsBool($dormantFlag);
        self::assertTrue($activeFlag);
        self::assertFalse($dormantFlag);
    }
}
