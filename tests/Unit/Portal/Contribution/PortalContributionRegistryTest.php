<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinitionRegistry;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalRouteDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\CMS\Portal\Contribution\PortalRouteRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\CMS\Portal\Presentation\PortalContributionRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(PortalWorkspaceDefinition::class)]
#[CoversClass(PortalNavigationDefinition::class)]
#[CoversClass(PortalTemplateDefinition::class)]
#[CoversClass(PortalRouteDefinition::class)]
#[CoversClass(PortalWorkspaceRegistry::class)]
#[CoversClass(PortalNavigationRegistry::class)]
#[CoversClass(PortalTemplateRegistry::class)]
#[CoversClass(PortalRouteRegistry::class)]
final class PortalContributionRegistryTest extends TestCase
{
    public function testOwnedCapabilityTemplateNavigationAndRouteStayInTheExtensionPortalNamespace(): void
    {
        $owner = ContributionOwner::extension('acme/orders');
        $authorization = new AuthorizationPolicyRegistry();
        $capabilities = new CapabilityDefinitionRegistry($authorization);
        $capabilities->register($owner, new CapabilityDefinition(
            'acme.orders.read',
            'Read orders',
            'Read orders in the customer portal.',
        ));
        $policies = new ResourcePolicyDefinitionRegistry($authorization);
        $policies->register($owner, new ResourcePolicyDefinition(
            'acme.orders.portal',
            'acme.orders.read',
            [new ResourcePolicyTarget('portal_session')],
        ));
        $workspaces = new PortalWorkspaceRegistry();
        $workspaces->register($owner, new PortalWorkspaceDefinition(
            'acme.orders.workspace',
            'Orders',
            'Customer order work.',
            20,
        ));
        $templates = new PortalTemplateRegistry();
        $templates->register($owner, new PortalTemplateDefinition('acme.orders.index', 'orders/index.twig'));
        $navigation = new PortalNavigationRegistry($workspaces, $capabilities, $authorization);
        $navigation->register($owner, new PortalNavigationDefinition(
            'acme.orders.navigation',
            'acme.orders.workspace',
            'Orders',
            'Review your orders.',
            '/',
            'orders',
            'acme.orders.read',
            10,
        ));
        $routes = new PortalRouteRegistry($capabilities, $templates, $authorization);
        $routes->register($owner, new PortalRouteDefinition(
            'acme.orders.index',
            '/',
            ['GET'],
            'acme.orders.read',
            'acme.orders.index',
        ), $this->factory());

        self::assertSame([], $navigation->visible([]));
        self::assertSame(
            '/portal/extensions/acme/orders',
            $navigation->visible(['acme.orders.read' => true])[0]['href'],
        );
        self::assertSame(
            '/portal/extensions/acme/orders',
            $routes->ownedBy($owner)[0]['registered_path'],
        );
        self::assertSame(
            'portal.extension.acme.orders.index',
            $routes->ownedBy($owner)[0]['registered_name'],
        );
    }

    public function testRejectsForeignOwnershipAndMixedSafeMutatingMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix');
        new PortalRouteDefinition(
            'acme.orders.mixed',
            '/',
            ['GET', 'POST'],
            'acme.orders.read',
            'acme.orders.index',
        );
    }

    public function testRegistryRefusesAWorkspaceOutsideTheOwnersNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PortalWorkspaceRegistry())->register(
            ContributionOwner::extension('acme/orders'),
            new PortalWorkspaceDefinition('other.orders.workspace', 'Orders', 'Order work.', 1),
        );
    }

    public function testRouteRejectsAnOwnedCapabilityWithoutAPortalSessionPolicy(): void
    {
        $owner = ContributionOwner::extension('acme/orders');
        $authorization = new AuthorizationPolicyRegistry();
        $capabilities = new CapabilityDefinitionRegistry($authorization);
        $capabilities->register($owner, new CapabilityDefinition(
            'acme.orders.read',
            'Read orders',
            'Read orders in the customer portal.',
        ));
        $templates = new PortalTemplateRegistry();
        $templates->register($owner, new PortalTemplateDefinition('acme.orders.index', 'orders/index.twig'));
        $routes = new PortalRouteRegistry($capabilities, $templates, $authorization);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('portal-session policy');
        $routes->register($owner, new PortalRouteDefinition(
            'acme.orders.index',
            '/',
            ['GET'],
            'acme.orders.read',
            'acme.orders.index',
        ), $this->factory());
    }

    public function testNavigationRejectsAnOwnedCapabilityWithoutAPortalSessionPolicy(): void
    {
        $owner = ContributionOwner::extension('acme/orders');
        $authorization = new AuthorizationPolicyRegistry();
        $capabilities = new CapabilityDefinitionRegistry($authorization);
        $capabilities->register($owner, new CapabilityDefinition(
            'acme.orders.read',
            'Read orders',
            'Read orders in the customer portal.',
        ));
        $workspaces = new PortalWorkspaceRegistry();
        $workspaces->register($owner, new PortalWorkspaceDefinition(
            'acme.orders.workspace',
            'Orders',
            'Customer order work.',
            20,
        ));
        $navigation = new PortalNavigationRegistry($workspaces, $capabilities, $authorization);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('portal-session policy');
        $navigation->register($owner, new PortalNavigationDefinition(
            'acme.orders.navigation',
            'acme.orders.workspace',
            'Orders',
            'Review your orders.',
            '/',
            'orders',
            'acme.orders.read',
            10,
        ));
    }

    public function testPortalManifestRoundTripRegistrarInventoryAndRemovalShareOneLifecycle(): void
    {
        $manifest = [
            'version' => 1,
            'capabilities' => [[
                'id' => 'acme.orders.read',
                'label' => 'Read orders',
                'description' => 'Read orders in the customer portal.',
            ]],
            'resource_policies' => [[
                'id' => 'acme.orders.portal',
                'capability' => 'acme.orders.read',
                'resources' => [['type' => 'portal_session', 'identifiers' => []]],
            ]],
            'portal' => [
                'workspaces' => [[
                    'id' => 'acme.orders.workspace',
                    'label' => 'Orders',
                    'description' => 'Customer order work.',
                    'priority' => 20,
                ]],
                'navigation' => [[
                    'id' => 'acme.orders.navigation',
                    'workspace' => 'acme.orders.workspace',
                    'label' => 'Orders',
                    'description' => 'Review your orders.',
                    'path' => '/',
                    'icon' => 'orders',
                    'capability' => 'acme.orders.read',
                    'priority' => 10,
                    'keywords' => 'orders',
                ]],
                'templates' => [[
                    'name' => 'acme.orders.index',
                    'template' => 'orders/index.twig',
                ]],
                'routes' => [[
                    'name' => 'acme.orders.index',
                    'path' => '/',
                    'methods' => ['GET'],
                    'capability' => 'acme.orders.read',
                    'template' => 'acme.orders.index',
                ]],
            ],
        ];
        $declared = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/orders'),
            $manifest,
        );
        self::assertSame($declared->toArray(), ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/orders'),
            $declared->toArray(),
        )->toArray());

        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($declared->owner, $declared);
        $registrar->capability($declared->capabilities()[0]);
        $registrar->resourcePolicy($declared->resourcePolicies()[0]);
        $registrar->portalWorkspace($declared->portalWorkspaces()[0]);
        $registrar->portalNavigation($declared->portalNavigation()[0]);
        $registrar->portalTemplate($declared->portalTemplates()[0]);
        $registrar->portalRoute($declared->portalRoutes()[0], $this->factory());
        $registrar->complete();

        self::assertSame('acme.orders.index', $registries->inventory(
            $declared->owner,
        )['portal']['routes'][0]['name']);
        $registries->remove($declared->owner);
        self::assertSame([], $registries->inventory($declared->owner)['portal']['routes']);
        self::assertSame([], $registries->inventory($declared->owner)['portal']['navigation']);
    }

    private function factory(): PortalRouteHandlerFactory
    {
        return new class implements PortalRouteHandlerFactory {
            public function create(PortalContributionRenderer $renderer): RequestHandlerInterface
            {
                return new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        throw new \LogicException('The registry unit test never dispatches the route.');
                    }
                };
            }
        };
    }
}
