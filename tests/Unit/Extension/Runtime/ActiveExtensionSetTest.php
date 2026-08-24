<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\DeferredExtensionRuntimeWithdrawal;
use Kumwe\App\Extension\Runtime\ExtensionContainer;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
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
            $definition = new CapabilityDefinition(
                $namespace . '.use',
                'Use contribution',
                'Use this extension contribution in the lifecycle fixture.',
            );
            $registrar = $registries->registrar(
                $owner,
                new ManifestContributionSet($owner, capabilities: [$definition]),
            );
            $registrar->capability($definition);
            $registrar->complete();
            $active->add(
                $identifier,
                new ActiveExtensionProviderProbe(),
                new RestrictedExtensionContainer($identifier, []),
                new ManifestContributionSet($owner),
                true,
                '1.2.3',
                hash('sha256', $identifier),
            );
            $active->addExtensionViewPath(ThemeSurface::Site, $identifier, '/runtime/' . $namespace . '/views');
            $active->addPortalTemplatePath($identifier, '/runtime/' . $namespace . '/portal');
            $active->addCatalogueDirectory($identifier, '/runtime/' . $namespace . '/messages');
        }
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
