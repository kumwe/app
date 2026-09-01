<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionContributionRegistrySet::class)]
/**
 * Proves the surface map keeps every declared contribution kind discoverable and removable together.
 *
 * @since  2.0.0
 */
final class ExtensionContributionRegistrySetTest extends TestCase
{
    /**
     * Prove each declared kind is inventoried through the surface map and withdrawn on owner removal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredSurfaceAppearsInInventoryAndIsWithdrawnOnRemoval(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/editor');
        $registries->activateManifest(self::manifest());

        // Inventory and removal both derive from the surface map, so a contribution kind
        // cannot be discoverable while remaining un-removable on disable or trust revocation.
        $inventory = $registries->inventory($owner);
        foreach ($registries->surfaceKeys() as $key) {
            $value = $inventory;
            foreach (explode('.', $key) as $segment) {
                self::assertIsArray($value, sprintf('Surface %s is missing from the inventory.', $key));
                self::assertArrayHasKey($segment, $value, sprintf('Surface %s is not inventoried.', $key));
                $value = $value[$segment];
            }
            self::assertIsArray($value);
        }
        self::assertNotSame([], $inventory['capabilities']);
        self::assertNotSame([], $inventory['administrator']['workspaces']);

        $registries->remove($owner);

        $emptied = $registries->inventory($owner);
        foreach ($registries->surfaceKeys() as $key) {
            $value = $emptied;
            foreach (explode('.', $key) as $segment) {
                self::assertIsArray($value);
                $value = $value[$segment] ?? null;
            }
            self::assertSame([], $value, sprintf('Surface %s survived owner removal.', $key));
        }
        self::assertSame([], $registries->navigation()->visible(['acme.editor.manage' => true]));
    }

    /**
     * Declare one graphical administrator contribution set under the acme/editor namespace.
     *
     * @return  ManifestContributions  Canonical signed declaration graph.
     *
     * @since   2.0.0
     */
    private static function manifest(): ManifestContributions
    {
        return ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            [
                'version' => 2,
                'capabilities' => [[
                    'id' => 'acme.editor.manage',
                    'label' => 'Manage editor',
                    'description' => 'Manage the editor proof workspace and its screens.',
                    'allowed_scopes' => ['global', 'site'],
                    'delegatable' => true,
                    'high_impact' => false,
                    'lifecycle' => 'active',
                    'version' => 1,
                ]],
                'administrator' => [
                    'workspaces' => [[
                        'id' => 'acme.editor.workspace',
                        'label' => 'Editor',
                        'description' => 'Neutral editor proof workspace.',
                        'priority' => 200,
                    ]],
                    'navigation' => [[
                        'id' => 'acme.editor.navigation',
                        'workspace' => 'acme.editor.workspace',
                        'label' => 'Editor',
                        'description' => 'Open the editor proof screen.',
                        'path' => '/',
                        'icon' => 'extensions',
                        'capability' => 'acme.editor.manage',
                        'priority' => 10,
                        'keywords' => 'editor example',
                        'surface' => 'acme.editor.administrator.index',
                    ]],
                    'views' => [[
                        'name' => 'acme.editor.administrator.index',
                        'template' => 'index.twig',
                    ]],
                    'routes' => [[
                        'name' => 'acme.editor.administrator.index',
                        'path' => '/',
                        'methods' => ['GET'],
                        'capability' => 'acme.editor.manage',
                        'view' => 'acme.editor.administrator.index',
                    ]],
                ],
                'interface' => ['surfaces' => [[
                    'surface' => 'acme.editor.administrator.index',
                    'standard' => 'kis-1.0',
                    'area' => 'administrator',
                    'actor' => 'administrator',
                    'intent' => 'diagnostics',
                    'resource' => 'editor',
                    'purpose' => 'Inspect the editor proof workspace.',
                    'pattern' => 'diagnostics-workspace',
                    'capabilities' => ['acme.editor.manage'],
                    'states' => ['default', 'empty', 'error', 'permission-reduced'],
                    'customization' => [['slot' => 'density', 'scope' => 'user']],
                    'responsive' => [[
                        'element' => 'editor-proof',
                        'priority' => 'essential',
                        'may_collapse' => false,
                    ]],
                    'icon' => 'extensions',
                ]]],
            ],
            4,
        );
    }
}
