<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Tests;

use @@PHP_NAMESPACE@@\Definition\BusinessDefinitions;
use @@PHP_NAMESPACE@@\Provider;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Runtime\RestrictedExtensionContainer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the package-owned business definition is complete and stable.
 *
 * @since  2.0.0
 */
final class PackageDefinitionTest extends TestCase
{
    /**
     * Confirm the scaffold exposes the signed definition handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionHandleIsStable(): void
    {
        $definitions = BusinessDefinitions::all();
        $json = file_get_contents(dirname(__DIR__) . '/kumwe.json');
        self::assertIsString($json);
        $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $declared = $manifest['contributions']['business']['definitions'];

        self::assertCount(1, $definitions);
        self::assertSame('@@EXTENSION_DOTTED@@.item', $definitions[0]->handle);
        self::assertSame($declared, array_map(
            static fn (EntityTypeDefinition $definition): array => $definition->toArray(),
            $definitions,
        ));
    }

    /**
     * Prove the executable provider registers every signed declaration exactly once and no others.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProviderExactlyReconcilesSignedManifest(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/kumwe.json');
        self::assertIsString($json);
        $manifest = ExtensionManifest::fromJson($json);
        $declarations = $manifest->contributions();
        $registries = new ExtensionContributionRegistrySet();
        $container = new RestrictedExtensionContainer($manifest->identifier()->value(), []);
        $provider = new Provider();
        $provider->register($container);
        $registrar = $registries->registrar($declarations->owner, $declarations);
        $provider->contribute($registrar, $container);
        $registrar->complete();
        $registries->validateBusinessDefinitions();
        $catalog = $registries->validateIntegrationContributions();

        self::assertSame(
            '@@EXTENSION_DOTTED@@.item_consumer',
            $catalog->consumer('@@EXTENSION_DOTTED@@.item_consumer')->identifier(),
        );
    }
}
