<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteHandlerFactory;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\InterfaceSurfaceRegistrar;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves KIS declarations use the signed owner-bound extension contribution lifecycle.
 *
 * @since  2.0.0
 */
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(OwnedRuntimeContributionRegistry::class)]
#[UsesClass(SurfaceDefinition::class)]
final class InterfaceSurfaceContributionTest extends TestCase
{
    /**
     * Parse, export, register, inventory, and withdraw the identical admitted declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSurfaceFollowsManifestReconciliationAndOwnerLifecycle(): void
    {
        $document = self::contributions();
        $set = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            $document,
            4,
        );
        $surface = $set->interfaceSurfaces()[0];
        $capability = $set->capabilities()[0];
        $owner = ContributionOwner::extension('acme/inspections');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($owner, $set);

        self::assertInstanceOf(InterfaceSurfaceRegistrar::class, $registrar);
        $registrar->capability($capability);
        $registrar->administratorWorkspace($set->workspaces()[0]);
        $registrar->administratorView($set->views()[0]);
        $registrar->administratorRoute($set->routes()[0], $this->routeFactory());
        $registrar->interfaceSurface($surface);
        $registrar->administratorNavigation($set->navigation()[0]);
        $registrar->complete();

        self::assertSame($document, $set->toArray());
        self::assertSame($surface, $registries->interfaceSurfaces()->definition(
            $owner,
            'acme.inspections.administrator.catalog',
        ));
        self::assertSame(
            $surface->toArray(),
            $registries->inventory($owner)['interface']['surfaces'][0],
        );
        self::assertSame(
            $surface->identifier(),
            $registries->navigation()->visible(['acme.inspections.view' => true])[0]['surface'] ?? null,
        );

        $registries->remove($owner);

        self::assertNull($registries->interfaceSurfaces()->definition(
            $owner,
            'acme.inspections.administrator.catalog',
        ));
        self::assertSame([], $registries->navigation()->visible(['acme.inspections.view' => true]));
    }

    /**
     * A dotted, underscored, hyphenated, and digit-bearing package namespace round-trips every GUI binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionCompatibleNamespaceRoundTripsGraphicalContributionBindings(): void
    {
        $json = json_encode(self::contributions(), JSON_THROW_ON_ERROR);
        $document = json_decode(
            str_replace('acme.inspections', 'ac9.me.pack_age-v1', $json),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);

        $set = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('ac9.me/pack_age-v1'),
            $document,
            4,
        );

        self::assertSame($document, $set->toArray());
        self::assertSame(
            'ac9.me.pack_age-v1.administrator.catalog',
            $set->interfaceSurfaces()[0]->identifier(),
        );
        self::assertSame(
            $set->interfaceSurfaces()[0]->identifier(),
            $set->navigation()[0]->surface,
        );
        self::assertSame($set->interfaceSurfaces()[0]->identifier(), $set->routes()[0]->name);
        self::assertSame('ac9.me.pack_age-v1.workspace', $set->workspaces()[0]->id);
    }

    /**
     * Refuse a semantic surface that names authority its package did not declare.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestSurfaceCannotReferenceForeignOrMissingCapability(): void
    {
        $document = self::contributions();
        $document['interface']['surfaces'][0]['capabilities'] = ['content.read'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capability declared by its owner');

        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            $document,
            4,
        );
    }

    /**
     * Once a package opts into KIS, it cannot publish an undeclared graphical GET route.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKisEnabledPackageCannotLeaveAGraphicalRouteWithoutASurface(): void
    {
        $document = self::contributions();
        $document['administrator']['views'][] = [
            'name' => 'acme.inspections.administrator.orphan-view',
            'template' => 'orphan.twig',
        ];
        $document['administrator']['routes'][] = [
            'name' => 'acme.inspections.administrator.orphan',
            'path' => '/orphan/',
            'methods' => ['GET'],
            'capability' => 'acme.inspections.view',
            'view' => 'acme.inspections.administrator.orphan-view',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declare every administrator graphical GET route as a surface');

        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            $document,
            4,
        );
    }

    /**
     * Refuse navigation that is not explicitly bound to the surface its graphical route serves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKisNavigationCannotDriftFromItsRouteSurface(): void
    {
        $document = self::contributions();
        $document['administrator']['navigation'][0]['surface'] = 'acme.inspections.administrator.missing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('admitted interface surface identifier');

        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            $document,
            4,
        );
    }

    /**
     * Refuse a surface whose declared authority omits the capability guarding its route.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKisSurfaceMustIncludeItsRouteCapability(): void
    {
        $document = self::contributions();
        $document['capabilities'][] = [
            'id' => 'acme.inspections.audit',
            'label' => 'Audit inspections',
            'description' => 'Audit policy-filtered inspections.',
            'allowed_scopes' => ['global', 'site'],
            'delegatable' => true,
            'high_impact' => false,
            'lifecycle' => 'active',
            'version' => 1,
        ];
        $document['interface']['surfaces'][0]['capabilities'] = ['acme.inspections.audit'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must include its route capability');

        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            $document,
            4,
        );
    }

    /**
     * Require provider code to reconcile every signed surface before its phase closes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProviderCannotOmitManifestSurface(): void
    {
        $set = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/inspections'),
            self::contributions(),
            4,
        );
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar(
            ContributionOwner::extension('acme/inspections'),
            $set,
        );
        $registrar->capability($set->capabilities()[0]);
        $registrar->administratorWorkspace($set->workspaces()[0]);
        $registrar->administratorView($set->views()[0]);
        $registrar->administratorRoute($set->routes()[0], $this->routeFactory());
        $registrar->administratorNavigation($set->navigation()[0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Provider omitted declared interface_surface contribution acme.inspections.administrator.catalog.',
        );

        $registrar->complete();
    }

    /**
     * Build a complete schema-four contribution document with one safe semantic surface.
     *
     * @return  array<string, mixed>  Canonical manifest contribution object.
     *
     * @since   2.0.0
     */
    private static function contributions(): array
    {
        return [
            'version' => 2,
            'capabilities' => [[
                'id' => 'acme.inspections.view',
                'label' => 'View inspections',
                'description' => 'View policy-filtered inspections.',
                'allowed_scopes' => ['global', 'site'],
                'delegatable' => true,
                'high_impact' => false,
                'lifecycle' => 'active',
                'version' => 1,
            ]],
            'resource_policies' => [],
            'administrator' => [
                'workspaces' => [[
                    'id' => 'acme.inspections.workspace',
                    'label' => 'Inspections',
                    'description' => 'Inspection operations workspace.',
                    'priority' => 10,
                ]],
                'navigation' => [[
                    'id' => 'acme.inspections.navigation',
                    'workspace' => 'acme.inspections.workspace',
                    'label' => 'Inspections',
                    'description' => 'Open the inspection catalog.',
                    'path' => '/inspections/',
                    'icon' => 'inspections',
                    'capability' => 'acme.inspections.view',
                    'priority' => 10,
                    'keywords' => 'inspection catalog',
                    'surface' => 'acme.inspections.administrator.catalog',
                ]],
                'routes' => [[
                    'name' => 'acme.inspections.administrator.catalog',
                    'path' => '/inspections/',
                    'methods' => ['GET'],
                    'capability' => 'acme.inspections.view',
                    'view' => 'acme.inspections.administrator.catalog-view',
                ]],
                'views' => [[
                    'name' => 'acme.inspections.administrator.catalog-view',
                    'template' => 'catalog.twig',
                ]],
            ],
            'portal' => ['workspaces' => [], 'navigation' => [], 'routes' => [], 'templates' => []],
            'business' => ['field_types' => [], 'definitions' => []],
            'interface' => ['surfaces' => [[
                'surface' => 'acme.inspections.administrator.catalog',
                'standard' => 'kis-1.0',
                'area' => 'administrator',
                'actor' => 'administrator',
                'intent' => 'collection',
                'resource' => 'facility-inspection',
                'purpose' => 'Find and inspect a policy-filtered facility inspection.',
                'pattern' => 'master-detail-workspace',
                'capabilities' => ['acme.inspections.view'],
                'states' => ['default', 'dense', 'empty', 'error', 'permission-reduced'],
                'customization' => [
                    ['slot' => 'columns', 'scope' => 'user'],
                    ['slot' => 'density', 'scope' => 'user'],
                ],
                'responsive' => [
                    ['element' => 'inspection-identity', 'priority' => 'essential', 'may_collapse' => false],
                    ['element' => 'inspection-owner', 'priority' => 'secondary', 'may_collapse' => true],
                ],
                'icon' => 'inspections',
            ]]],
            'integration' => [
                'event_schemas' => [],
                'domain_listeners' => [],
                'consumers' => [],
                'jobs' => [],
                'queues' => [],
                'schedules' => [],
                'projections' => [],
                'reports' => [],
                'webhooks' => [],
            ],
        ];
    }

    /**
     * Supply a non-executed handler factory for the owner-bound route registry.
     *
     * @return  AdministratorRouteHandlerFactory  Deterministic test-only route factory.
     *
     * @since   2.0.0
     */
    private function routeFactory(): AdministratorRouteHandlerFactory
    {
        return new class implements AdministratorRouteHandlerFactory {
            public function create(AdministratorRenderer $renderer): RequestHandlerInterface
            {
                return new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new HtmlResponse('KIS test route');
                    }
                };
            }
        };
    }
}
