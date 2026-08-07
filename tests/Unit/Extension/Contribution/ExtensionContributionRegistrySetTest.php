<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
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
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\CoreExtensionContributions;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(ContributionOwner::class)]
#[CoversClass(CoreExtensionContributions::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
final class ExtensionContributionRegistrySetTest extends TestCase
{
    public function testCoreNavigationUsesTheSameTypedPermissionAwareRegistry(): void
    {
        $navigation = (new ExtensionContributionRegistrySet())->navigation();
        $visible = $navigation->visible([
            'content.read' => true,
            'content.create' => true,
            'extensions.manage' => true,
        ]);

        self::assertSame(
            ['core.dashboard', 'core.content', 'core.create-content', 'core.media', 'core.models', 'core.extensions'],
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
}
