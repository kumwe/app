<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
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
     * Distinct active owners cannot share or nest the same legacy dotted contribution namespace.
     *
     * `a.b/c` and `a/b.c` both flatten to the dotted namespace `a.b.c`, and `a.b/c.d` sits beneath it,
     * so a string-prefix ownership test could no longer tell their contributions apart. The second
     * distinct owner is refused before any of its declarations are registered, and the namespace is
     * released again once the first owner is removed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAmbiguousOwnerNamespacesFailBeforeRegistrationAndReleaseOnRemoval(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $first = ContributionOwner::extension('a.b/c');
        $registries->activateManifest(self::emptyManifest('a.b/c'));

        foreach (['a/b.c', 'a.b/c.d'] as $colliding) {
            try {
                $registries->activateManifest(self::emptyManifest($colliding));
                self::fail('Two active owners must not share the same dotted contribution namespace.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('conflicts with active owner a.b/c', $exception->getMessage());
            }
        }

        $registries->remove($first);

        self::assertInstanceOf(
            OwnedExtensionBindingRegistrar::class,
            $registries->activateManifest(self::emptyManifest('a/b.c')),
        );
    }

    /**
     * Core publishes complete typed capability metadata and a typed system-identity policy allowlist.
     *
     * The gateway reads ownership, impact, scope, delegation, human-grant and unattended-identity
     * answers from the shipped core contribution set alone, so each of those answers is pinned here
     * against the live registry rather than inferred from capability names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCorePublishesCompleteTypedCapabilityAndSystemPolicyMetadata(): void
    {
        $policies = (new ExtensionContributionRegistrySet())->authorizationPolicies();
        $delete = $policies->capability(Capability::fromString('content.delete'));

        self::assertNotNull($delete);
        self::assertSame('core', $delete->owner);
        self::assertTrue($delete->highImpact);
        self::assertContains('content', $delete->allowedScopes);
        self::assertTrue($policies->supports(
            Capability::fromString('content.read'),
            AuthorizationResource::item('business_definition', 'page'),
        ));
        self::assertTrue($policies->allowsSystemIdentity(
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', 'page'),
            SystemIdentity::Worker,
        ));
        self::assertFalse($policies->allowsSystemIdentity(
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', 'page'),
            SystemIdentity::Scheduler,
        ));
        self::assertFalse($policies->supports(
            Capability::fromString('themes.site.manage'),
            AuthorizationResource::item('theme', 'administrator'),
        ));
        self::assertFalse($policies->allowsHumanGrant(Capability::fromString('administrator.bootstrap')));
        foreach (
            [
                'business.approval.approve' => 'approval_request',
                'business.approval.manage' => 'approval_request',
                'business.approval.request' => 'business_record',
                'business.security.manage' => 'resource_policy',
                'business.step_up.manage' => 'step_up_credential',
                'portal.access' => 'portal_session',
            ] as $capability => $resource
        ) {
            $definition = $policies->capability(Capability::fromString($capability));
            self::assertNotNull($definition, $capability);
            self::assertTrue($definition->delegatable, $capability);
            self::assertContains('global', $definition->allowedScopes, $capability);
            self::assertTrue($policies->supports(
                Capability::fromString($capability),
                AuthorizationResource::item($resource, 'fixture'),
            ), $capability);
        }
        self::assertTrue(
            $policies->capability(Capability::fromString('business.approval.approve'))?->highImpact,
        );
        self::assertFalse($policies->capability(Capability::fromString('portal.access'))?->highImpact);
    }

    /**
     * Declare one manifest graph that contributes nothing, so only its owner namespace is claimed.
     *
     * @param   string  $identifier  Package identifier in `vendor/name` form.
     *
     * @return  ManifestContributions  Canonical declaration graph with every surface empty.
     *
     * @since   2.0.0
     */
    private static function emptyManifest(string $identifier): ManifestContributions
    {
        return ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString($identifier),
            ['version' => 2],
            4,
        );
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
