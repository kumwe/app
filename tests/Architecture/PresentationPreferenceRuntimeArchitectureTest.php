<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the KIS preference migration and runtime services in the production composition graph.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PresentationPreferenceRuntimeArchitectureTest extends TestCase
{
    /**
     * Proves fresh deployment, persistence, live admission, resolution, and mutation are all composed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionCompositionIncludesCompletePreferenceRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $container = file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        self::assertIsString($container);

        foreach (
            [
            'new InterfacePresentationPreferenceMigration(',
            'DoctrinePresentationPreferenceRepository::class',
            'PresentationPreferenceRepository::class',
            'new RegisteredPresentationPreferencePolicy(',
            'PresentationPreferenceResolver::class',
            'PresentationPreferenceManager::class',
            'self::service($container, MembershipContextValidator::class)',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $container);
        }
        self::assertStringContainsString('->interfaceSurfaces()', $container);
    }
}
