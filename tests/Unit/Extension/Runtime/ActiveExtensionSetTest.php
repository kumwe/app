<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\DeferredExtensionRuntimeWithdrawal;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveExtensionSet::class)]
#[CoversClass(DeferredExtensionRuntimeWithdrawal::class)]
/**
 * Verifies lifecycle withdrawal empties every resident extension-owned execution and presentation path.
 *
 * @since  2.0.0
 */
final class ActiveExtensionSetTest extends TestCase
{
    /**
     * A signed executable declaration cannot activate without the canonical SDK binding phase.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExecutableManifestRequirementsCannotActivateWithoutABindingProvider(): void
    {
        $identifier = ExtensionIdentifier::fromString('acme/routes');
        $manifest = ManifestContributions::fromManifest($identifier, [
            'version' => 1,
            'capabilities' => [[
                'id' => 'acme.routes.view',
                'label' => 'View routes',
                'description' => 'Open the exact extension route.',
            ]],
            'administrator' => [
                'views' => [[
                    'name' => 'acme.routes.index',
                    'template' => 'index.twig',
                ]],
                'routes' => [[
                    'name' => 'acme.routes.index',
                    'path' => '/',
                    'methods' => ['GET'],
                    'capability' => 'acme.routes.view',
                    'view' => 'acme.routes.index',
                ]],
            ],
        ], 2);
        $active = new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false));
        $active->add(
            $identifier->value(),
            new ActiveExtensionProviderProbe(),
            new RestrictedExtensionContainer($identifier->value(), []),
            $manifest,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must bind every executable identifier');
        $active->activate();
    }

    /**
     * Superseding one signed graph withdraws all owners without disturbing the core-free fixture shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWithdrawAllRemovesProvidersRegistriesThemesViewsTemplatesAndCatalogues(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $active = new ActiveExtensionSet($registries);
        foreach (['acme/editor', 'vendor/rates'] as $identifier) {
            $owner = ContributionOwner::extension($identifier);
            $namespace = $owner->namespace();
            $declared = ManifestContributions::fromManifest(
                ExtensionIdentifier::fromString($identifier),
                [
                    'version' => 1,
                    'capabilities' => [[
                        'id' => $namespace . '.use',
                        'label' => 'Use contribution',
                        'description' => 'Use this extension contribution in the lifecycle fixture.',
                    ]],
                ],
                2,
            );
            $active->add(
                $identifier,
                new ActiveExtensionProviderProbe(),
                new RestrictedExtensionContainer($identifier, []),
                $declared,
                '1.2.3',
                hash('sha256', $identifier),
            );
            $active->addExtensionViewPath(ThemeSurface::Site, $identifier, '/runtime/' . $namespace . '/views');
            $active->addPortalTemplatePath($identifier, '/runtime/' . $namespace . '/portal');
            $active->addCatalogueDirectory($identifier, '/runtime/' . $namespace . '/messages');
        }
        $active->activate();
        $active->setThemePath(ThemeSurface::Administrator, '/runtime/acme.editor/theme', 'acme/editor');
        $active->setSiteThemePath('default', '/runtime/acme.editor/site-theme', 'acme/editor');

        self::assertSame(2, $active->count());
        self::assertCount(2, $active->extensionViewPaths(ThemeSurface::Site));
        self::assertCount(2, $active->portalTemplatePaths());
        self::assertCount(2, $active->catalogueDirectories());
        self::assertNotNull($active->themePath(ThemeSurface::Administrator));
        self::assertNotNull($active->siteThemePath('default'));
        self::assertSame([
            'id' => 'acme/editor',
            'version' => '1.2.3',
            'revision' => hash('sha256', 'acme/editor'),
        ], $active->siteThemeRelease('default'));

        $withdrawal = new DeferredExtensionRuntimeWithdrawal();
        $withdrawal->bind($active);
        $withdrawal->withdrawAll();

        self::assertSame(0, $active->count());
        self::assertSame([], $active->extensionViewPaths(ThemeSurface::Site));
        self::assertSame([], $active->portalTemplatePaths());
        self::assertSame([], $active->catalogueDirectories());
        self::assertNull($active->themePath(ThemeSurface::Administrator));
        self::assertNull($active->siteThemePath('default'));
        self::assertNull($active->siteThemeRelease('default'));
        foreach (['acme/editor', 'vendor/rates'] as $identifier) {
            self::assertSame(
                [],
                $registries->inventory(ContributionOwner::extension($identifier))['capabilities'],
            );
        }
    }
}

/**
 * Minimal provider object held only to prove lifecycle withdrawal releases resident instances.
 *
 * @since  2.0.0
 */
final readonly class ActiveExtensionProviderProbe implements ExtensionServiceProvider
{
    /**
     * Register no services; the fixture needs only a resident provider identity.
     *
     * @param   ExtensionContainer  $container  Restricted container intentionally left unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }
}
