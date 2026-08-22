<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Presentation;

use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Asset\ViteAssetManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Proves the portal shell receives the shared, content-hashed KIS runtime from the host build.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalRenderer::class)]
#[UsesClass(ViteAssetManifest::class)]
final class PortalRendererAssetTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir() . '/kumwe-portal-assets-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }
    }

    /**
     * Resolve the portal entry and refuse handler data that tries to replace protected shell assets.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalUsesHostOwnedBuiltAssets(): void
    {
        self::assertNotFalse(file_put_contents($this->manifest, json_encode([
            'assets/portal/main.ts' => [
                'file' => 'js/portal-safe.js',
                'css' => ['css/portal-safe.css'],
                'src' => 'assets/portal/main.ts',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR)));
        $renderer = new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/proof.twig' => '{{ portal_assets.stylesheets|join(",") }}|'
                    . '{{ portal_assets.modules|join(",") }}|{{ portal_navigation|length }}',
            ]), ['strict_variables' => true]),
            new PortalNavigationRegistry(
                new PortalWorkspaceRegistry(),
                new CapabilityDefinitionRegistry(),
                new AuthorizationPolicyRegistry(),
            ),
            new PortalTemplateRegistry(),
            $this->createStub(PortalNavigationVisibility::class),
            new ViteAssetManifest($this->manifest),
        );

        self::assertSame(
            '/assets/build/css/portal-safe.css|/assets/build/js/portal-safe.js|0',
            $renderer->render('proof', [
                'portal_assets' => ['stylesheets' => ['/unsafe.css'], 'modules' => ['/unsafe.js']],
                'portal_navigation' => [['id' => 'unsafe']],
            ]),
        );
    }
}
