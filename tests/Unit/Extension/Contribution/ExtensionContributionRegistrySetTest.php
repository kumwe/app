<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\CapabilityDefinition as AuthorizationCapabilityDefinition;
use Kumwe\CMS\Application\Authorization\CapabilityDefinitionRegistry as AuthorizationCapabilityDefinitionRegistry;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourcePolicyDefinition as AuthorizationResourcePolicyDefinition;
use Kumwe\CMS\Application\Authorization\ResourcePolicyRegistry;
use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteRegistry;
use Kumwe\CMS\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceRegistry;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionDefinitionChecksum;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\CoreExtensionContributions;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinitionRegistry;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\CMS\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AdministratorNavigationDefinition::class)]
#[CoversClass(AdministratorNavigationRegistry::class)]
#[CoversClass(AdministratorRouteDefinition::class)]
#[CoversClass(AdministratorRouteRegistry::class)]
#[CoversClass(AdministratorViewDefinition::class)]
#[CoversClass(AdministratorViewRegistry::class)]
#[CoversClass(AdministratorWorkspaceDefinition::class)]
#[CoversClass(AdministratorWorkspaceRegistry::class)]
#[CoversClass(CapabilityDefinition::class)]
#[CoversClass(CapabilityDefinitionRegistry::class)]
#[CoversClass(ContributionDefinitionChecksum::class)]
#[CoversClass(ContributionOwner::class)]
#[CoversClass(CoreExtensionContributions::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
#[CoversClass(ResourcePolicyDefinition::class)]
#[CoversClass(ResourcePolicyDefinitionRegistry::class)]
#[UsesClass(AuthorizationCapabilityDefinition::class)]
#[UsesClass(AuthorizationCapabilityDefinitionRegistry::class)]
#[UsesClass(AuthorizationDefinitionLifecycle::class)]
#[UsesClass(AuthorizationPolicyRegistry::class)]
#[UsesClass(AuthorizationResourcePolicyDefinition::class)]
#[UsesClass(JobContributionDefinition::class)]
#[UsesClass(ResourcePolicyRegistry::class)]
#[UsesClass(ResourcePolicyTarget::class)]
#[UsesClass(RuntimeCanonicalJson::class)]
final class ExtensionContributionRegistrySetTest extends TestCase
{
    public function testContributionChecksumsBindCanonicalDefinitionMetadataToItsOwner(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $definition = new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage records owned by the editor package.',
            ['site', 'global'],
            false,
            true,
            AuthorizationDefinitionLifecycle::Deprecated,
            3,
        );

        $checksum = ContributionDefinitionChecksum::calculate($owner, $definition);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $checksum);
        self::assertSame($checksum, ContributionDefinitionChecksum::calculate($owner, $definition));
        $this->expectException(InvalidArgumentException::class);
        ContributionDefinitionChecksum::calculate(ContributionOwner::extension('acme/other'), $definition);
    }

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
            self::assertNotNull($definition);
            self::assertTrue($definition->delegatable);
            self::assertContains('global', $definition->allowedScopes);
            self::assertTrue($policies->supports(
                Capability::fromString($capability),
                AuthorizationResource::item($resource, 'fixture'),
            ));
        }
        self::assertTrue(
            $policies->capability(Capability::fromString('business.approval.approve'))?->highImpact,
        );
        self::assertFalse($policies->capability(Capability::fromString('portal.access'))?->highImpact);
    }

    public function testExtensionPoliciesShareOwnershipLifecycleAndRemovalWithCapabilities(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $capability = new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage records owned by the editor package.',
            ['global', 'site', 'acme_editor_record'],
            true,
            true,
        );
        $policy = new ResourcePolicyDefinition(
            'acme.editor.records',
            'acme.editor.manage',
            [new ResourcePolicyTarget('acme_editor_record')],
        );
        $declared = new ManifestContributionSet(
            owner: $owner,
            capabilities: [$capability],
            resourcePolicies: [$policy],
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($owner, $declared);
        $registrar->capability($capability);
        $registrar->resourcePolicy($policy);
        $registrar->complete();

        $action = Capability::fromString('acme.editor.manage');
        $resource = AuthorizationResource::item('acme_editor_record', 'record-1');
        self::assertTrue($registries->authorizationPolicies()->supports($action, $resource));
        self::assertSame('acme/editor', $registries->authorizationPolicies()->capability($action)?->owner);
        self::assertSame('acme.editor.records', $registries->inventory($owner)['resource_policies'][0]['id']);

        $registries->remove($owner);

        self::assertFalse($registries->authorizationPolicies()->supports($action, $resource));
        self::assertNull($registries->authorizationPolicies()->capability($action));
    }

    public function testDisabledCapabilityRemainsInventoriedButCannotAuthorize(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $capability = new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage records owned by the editor package.',
            lifecycle: AuthorizationDefinitionLifecycle::Disabled,
            version: 4,
        );
        $policy = new ResourcePolicyDefinition(
            'acme.editor.records',
            'acme.editor.manage',
            [new ResourcePolicyTarget('acme_editor_record')],
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar(
            $owner,
            new ManifestContributionSet(
                owner: $owner,
                capabilities: [$capability],
                resourcePolicies: [$policy],
            ),
        );
        $registrar->capability($capability);
        $registrar->resourcePolicy($policy);
        $registrar->complete();

        self::assertSame('disabled', $registries->inventory($owner)['capabilities'][0]['lifecycle']);
        self::assertFalse($registries->authorizationPolicies()->supports(
            Capability::fromString('acme.editor.manage'),
            AuthorizationResource::item('acme_editor_record', 'record-1'),
        ));
    }

    public function testExtensionCannotAttachPolicyToForeignCapabilityOrAuthorizeSystemIdentity(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($owner, new ManifestContributionSet($owner), false);
        $registrar->capability(new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage records owned by the editor package.',
        ));

        try {
            $registrar->resourcePolicy(new ResourcePolicyDefinition(
                'acme.editor.foreign',
                'content.read',
                [new ResourcePolicyTarget('content')],
            ));
            self::fail('An extension cannot bind policy to a foreign capability.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('capability owned by acme/editor', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot grant authority to system identities');
        $registrar->resourcePolicy(new ResourcePolicyDefinition(
            'acme.editor.system',
            'acme.editor.manage',
            [new ResourcePolicyTarget('acme_editor_record')],
            systemIdentities: [SystemIdentity::Worker],
        ));
    }

    public function testManifestRoundTripsCapabilityAndResourcePolicySecurityMetadata(): void
    {
        $declared = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            [
                'version' => 1,
                'capabilities' => [[
                    'id' => 'acme.editor.manage',
                    'label' => 'Manage editor',
                    'description' => 'Manage records owned by the editor package.',
                    'allowed_scopes' => ['site', 'acme_editor_record'],
                    'delegatable' => false,
                    'high_impact' => true,
                    'lifecycle' => 'deprecated',
                    'version' => 3,
                ]],
                'resource_policies' => [[
                    'id' => 'acme.editor.records',
                    'capability' => 'acme.editor.manage',
                    'resources' => [['type' => 'acme_editor_record', 'identifiers' => []]],
                    'installation_global' => false,
                    'system_identities' => [],
                    'lifecycle' => 'active',
                    'version' => 2,
                ]],
            ],
        );
        $roundTrip = $declared->toArray();

        self::assertSame(
            ['acme_editor_record', 'site'],
            $roundTrip['capabilities'][0]['allowed_scopes'],
        );
        self::assertFalse($roundTrip['capabilities'][0]['delegatable']);
        self::assertTrue($roundTrip['capabilities'][0]['high_impact']);
        self::assertSame('deprecated', $roundTrip['capabilities'][0]['lifecycle']);
        self::assertSame(3, $roundTrip['capabilities'][0]['version']);
        self::assertSame('acme.editor.records', $roundTrip['resource_policies'][0]['id']);
        self::assertSame(2, $roundTrip['resource_policies'][0]['version']);

        $reparsed = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $roundTrip,
        );
        self::assertSame($roundTrip, $reparsed->toArray());
    }

    public function testCoreNavigationUsesTheSameTypedPermissionAwareRegistry(): void
    {
        $navigation = (new ExtensionContributionRegistrySet())->navigation();
        $visible = $navigation->visible([
            'content.read' => true,
            'content.create' => true,
            'extensions.manage' => true,
        ]);

        self::assertSame(
            [
                'core.dashboard',
                'core.content',
                'core.create-content',
                'core.media',
                'core.models',
                'core.business-definitions',
                'core.extensions',
            ],
            array_column($visible, 'id'),
        );
        self::assertSame(
            ['Workspace', 'Structure', 'System'],
            array_column($navigation->visibleWorkspaces([
                'content.read' => true,
                'content.create' => true,
                'extensions.manage' => true,
            ]), 'label'),
        );
    }

    public function testReconcilesTypedDeclarationsAndFiltersNavigationByCapability(): void
    {
        [$declared, $definitions] = $this->declarations();
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($declared->owner, $declared);

        $registrar->capability($definitions['capability']);
        $registrar->administratorWorkspace($definitions['workspace']);
        $registrar->administratorNavigation($definitions['navigation']);
        $registrar->administratorView($definitions['view']);
        $registrar->administratorRoute($definitions['route'], $this->factory());
        $registrar->complete();

        self::assertSame([], $registries->navigation()->visible([]));
        $visible = $registries->navigation()->visible(['acme.editor.manage' => true]);
        self::assertSame('acme.editor.navigation', $visible[0]['id']);
        self::assertSame('/administrator/extensions/acme/editor', $visible[0]['href']);
        $inventory = $registries->inventory($declared->owner);
        self::assertSame('acme.editor.manage', $inventory['capabilities'][0]['id']);
        self::assertSame(
            'administrator.extension.acme.editor.index',
            $inventory['administrator']['routes'][0]['registered_name'],
        );
    }

    public function testEveryDeclaredSurfaceAppearsInInventoryAndIsWithdrawnOnRemoval(): void
    {
        [$declared, $definitions] = $this->declarations();
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($declared->owner, $declared);
        $registrar->capability($definitions['capability']);
        $registrar->administratorWorkspace($definitions['workspace']);
        $registrar->administratorNavigation($definitions['navigation']);
        $registrar->administratorView($definitions['view']);
        $registrar->administratorRoute($definitions['route'], $this->factory());
        $registrar->complete();

        // Inventory and removal both derive from the surface map, so a contribution kind
        // cannot be discoverable while remaining un-removable on disable or trust revocation.
        $inventory = $registries->inventory($declared->owner);
        foreach ($registries->surfaceKeys() as $key) {
            $segments = explode('.', $key);
            $value = $inventory;
            foreach ($segments as $segment) {
                self::assertIsArray($value, sprintf('Surface %s is missing from the inventory.', $key));
                self::assertArrayHasKey($segment, $value, sprintf('Surface %s is not inventoried.', $key));
                $value = $value[$segment];
            }
            self::assertIsArray($value);
        }

        $registries->remove($declared->owner);

        $emptied = $registries->inventory($declared->owner);
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

    public function testRejectsProviderDriftAndOmittedDeclarations(): void
    {
        [$declared, $definitions] = $this->declarations();
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar(
            $declared->owner,
            $declared,
        );

        try {
            $registrar->capability(new CapabilityDefinition(
                'acme.editor.manage',
                'Changed label',
                'Open and manage the editor workspace.',
            ));
            self::fail('Provider declarations cannot drift from the inspected manifest.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar(
            $declared->owner,
            $declared,
        );
        $registrar->capability($definitions['capability']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('omitted declared');
        $registrar->complete();
    }

    public function testStrictReconciliationIgnoresObjectKeyOrderButRetainsValueAndListOrder(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $declared = new JobContributionDefinition(
            'acme.editor.review',
            1,
            '1.0.0',
            [
                'additionalProperties' => false,
                'properties' => [
                    'minimum_age_days' => ['maximum' => 365, 'minimum' => 1, 'type' => 'integer'],
                    'site_identifier' => ['maxLength' => 191, 'minLength' => 1, 'type' => 'string'],
                ],
                'required' => ['site_identifier', 'minimum_age_days'],
                'type' => 'object',
            ],
            'default',
            5,
        );
        $sourceOrderedSchema = [
            'type' => 'object',
            'properties' => [
                'site_identifier' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'minimum_age_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
            ],
            'required' => ['site_identifier', 'minimum_age_days'],
            'additionalProperties' => false,
        ];
        $declarations = new ManifestContributionSet(owner: $owner, jobs: [$declared]);
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar($owner, $declarations);
        $registrar->jobHandler(new JobContributionDefinition(
            'acme.editor.review',
            1,
            '1.0.0',
            $sourceOrderedSchema,
            'default',
            5,
        ), $this->jobHandler());
        $registrar->complete();

        $driftedSchemas = [
            array_replace_recursive($sourceOrderedSchema, [
                'properties' => ['minimum_age_days' => ['minimum' => 2]],
            ]),
            array_replace($sourceOrderedSchema, [
                'required' => ['minimum_age_days', 'site_identifier'],
            ]),
        ];
        foreach ($driftedSchemas as $driftedSchema) {
            $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar($owner, $declarations);
            try {
                $registrar->jobHandler(new JobContributionDefinition(
                    'acme.editor.review',
                    1,
                    '1.0.0',
                    $driftedSchema,
                    'default',
                    5,
                ), $this->jobHandler());
                self::fail('Canonical reconciliation must still reject value and list-order drift.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    public function testRejectsForeignOwnershipDuplicatesAndUnsafeRoutes(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $first = $registries->registrar($owner, new ManifestContributionSet($owner), false);
        $first->capability(new CapabilityDefinition('acme.editor.manage', 'Manage', 'Manage the editor.'));

        $second = $registries->registrar($owner, new ManifestContributionSet($owner), false);
        try {
            $second->capability(new CapabilityDefinition('acme.editor.manage', 'Manage', 'Manage the editor.'));
            self::fail('Duplicate contribution identifiers must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already owned', $exception->getMessage());
        }

        try {
            $first->administratorWorkspace(new AdministratorWorkspaceDefinition(
                'foreign.workspace',
                'Foreign',
                'Foreign workspace.',
                1,
            ));
            self::fail('A contributor cannot claim another namespace.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot claim', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix safe and mutating');
        new AdministratorRouteDefinition(
            'acme.editor.mixed',
            '/',
            ['GET', 'POST'],
            'acme.editor.manage',
            'acme.editor.index',
        );
    }

    /**
     * @return array{
     *     ManifestContributionSet,
     *     array{
     *         capability: CapabilityDefinition,
     *         workspace: AdministratorWorkspaceDefinition,
     *         navigation: AdministratorNavigationDefinition,
     *         view: AdministratorViewDefinition,
     *         route: AdministratorRouteDefinition
     *     }
     * }
     */
    private function declarations(): array
    {
        $owner = ContributionOwner::extension('acme/editor');
        $definitions = [
            'capability' => new CapabilityDefinition(
                'acme.editor.manage',
                'Manage editor',
                'Open and manage the editor workspace.',
            ),
            'workspace' => new AdministratorWorkspaceDefinition(
                'acme.editor.workspace',
                'Editor',
                'Editor workspace.',
                100,
            ),
            'navigation' => new AdministratorNavigationDefinition(
                'acme.editor.navigation',
                'acme.editor.workspace',
                'Editor',
                'Open editor',
                '/',
                'content',
                'acme.editor.manage',
                10,
                'editor',
            ),
            'view' => new AdministratorViewDefinition('acme.editor.index', 'index.twig'),
            'route' => new AdministratorRouteDefinition(
                'acme.editor.index',
                '/',
                ['GET'],
                'acme.editor.manage',
                'acme.editor.index',
            ),
        ];
        return [new ManifestContributionSet(
            $owner,
            [$definitions['capability']],
            [$definitions['workspace']],
            [$definitions['navigation']],
            [$definitions['route']],
            [$definitions['view']],
        ), $definitions];
    }

    private function factory(): AdministratorRouteHandlerFactory
    {
        return new class implements AdministratorRouteHandlerFactory {
            public function create(AdministratorRenderer $renderer): RequestHandlerInterface
            {
                return new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        throw new \LogicException('The registry unit test does not execute routes.');
                    }
                };
            }
        };
    }

    private function jobHandler(): JobHandler
    {
        return new class implements JobHandler {
            public function type(): string
            {
                return 'acme.editor.review';
            }

            public function handle(array $payload, ExecutionContext $context): void
            {
            }
        };
    }
}
