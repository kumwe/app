<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveExtensionSet::class)]
/**
 * Proves an active extension entry only ever carries the manifest graph its own provider declared.
 *
 * @since  2.0.0
 */
final class ActiveExtensionSetOwnershipTest extends TestCase
{
    /**
     * Prove a contribution graph signed for another package never attaches to a provider.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignManifestContributionGraphIsRefused(): void
    {
        $active = new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false));
        $foreignGraph = ManifestContributions::fromSchemaOne(
            ExtensionIdentifier::fromString('acme/other'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must belong to its provider');

        $active->add(
            'acme/probe',
            self::provider(),
            new RestrictedExtensionContainer('acme/probe', []),
            $foreignGraph,
        );
    }

    /**
     * Build an inert provider probe whose lifecycle methods record nothing.
     *
     * @return  ExtensionServiceProvider  Provider that contributes nothing.
     *
     * @since   2.0.0
     */
    private static function provider(): ExtensionServiceProvider
    {
        return new class implements ExtensionServiceProvider {
            /**
             * Register no services for the probe extension.
             *
             * @param   ExtensionContainer  $container  Restricted container for this extension.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function register(ExtensionContainer $container): void
            {
                unset($container);
            }

            /**
             * Boot nothing for the probe extension.
             *
             * @param   ExtensionContainer  $container  Restricted container for this extension.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function boot(ExtensionContainer $container): void
            {
                unset($container);
            }
        };
    }
}
