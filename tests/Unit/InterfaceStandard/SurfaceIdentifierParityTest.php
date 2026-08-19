<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\App\Extension\Contribution\AdministratorRouteDefinition;
use Kumwe\App\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\App\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\App\Portal\Contribution\PortalRouteDefinition;
use Kumwe\App\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\App\Portal\Contribution\PortalWorkspaceDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves owner-bound KIS surfaces and their graphical contribution bindings share one safe grammar.
 *
 * @since  2.0.0
 */
#[CoversClass(SurfaceId::class)]
#[CoversClass(AdministratorWorkspaceDefinition::class)]
#[CoversClass(AdministratorNavigationDefinition::class)]
#[CoversClass(AdministratorRouteDefinition::class)]
#[CoversClass(AdministratorViewDefinition::class)]
#[CoversClass(PortalWorkspaceDefinition::class)]
#[CoversClass(PortalNavigationDefinition::class)]
#[CoversClass(PortalRouteDefinition::class)]
#[CoversClass(PortalTemplateDefinition::class)]
#[UsesClass(ContributionOwner::class)]
#[UsesClass(ExtensionIdentifier::class)]
#[UsesClass(Capability::class)]
final class SurfaceIdentifierParityTest extends TestCase
{
    /**
     * Digit-led, dotted, underscored, and hyphenated package namespaces bind across both graphical areas.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionCompatibleNamespaceBindsSurfaceRouteWorkspaceAndNavigation(): void
    {
        $owner = ContributionOwner::extension('9ac.me/2-orders_v1');
        $namespace = '9ac.me.2-orders_v1';
        $administratorSurface = $namespace . '.administrator.index';
        $portalSurface = $namespace . '.portal.index';

        $administratorWorkspace = new AdministratorWorkspaceDefinition(
            $namespace . '.administrator.workspace',
            'Orders',
            'Manage extension orders.',
            10,
        );
        $administratorNavigation = new AdministratorNavigationDefinition(
            $namespace . '.administrator.navigation',
            $administratorWorkspace->id,
            'Orders',
            'Open extension orders.',
            '/',
            'orders',
            'content.read',
            10,
            surface: $administratorSurface,
        );
        $administratorView = new AdministratorViewDefinition($administratorSurface, 'index.twig');
        $administratorRoute = new AdministratorRouteDefinition(
            $administratorSurface,
            '/',
            ['GET'],
            'content.read',
            $administratorView->name,
        );

        $portalWorkspace = new PortalWorkspaceDefinition(
            $namespace . '.portal.workspace',
            'Orders',
            'Use extension orders.',
            10,
        );
        $portalNavigation = new PortalNavigationDefinition(
            $namespace . '.portal.navigation',
            $portalWorkspace->id,
            'Orders',
            'Open extension orders.',
            '/',
            'orders',
            'content.read',
            10,
            surface: $portalSurface,
        );
        $portalTemplate = new PortalTemplateDefinition($portalSurface, 'index.twig');
        $portalRoute = new PortalRouteDefinition(
            $portalSurface,
            '/',
            ['GET'],
            'content.read',
            $portalTemplate->name,
        );

        foreach (
            [
            $administratorWorkspace->id,
            $administratorNavigation->id,
            $administratorRoute->name,
            $administratorView->name,
            $portalWorkspace->id,
            $portalNavigation->id,
            $portalRoute->name,
            $portalTemplate->name,
            ] as $identifier
        ) {
            $owner->assertOwns($identifier, 'test contribution');
        }
        $owner->assertOwns(SurfaceId::fromString($administratorSurface)->value(), 'interface surface');
        $owner->assertOwns(SurfaceId::fromString($portalSurface)->value(), 'interface surface');

        self::assertSame($administratorSurface, $administratorNavigation->surface);
        self::assertSame($administratorSurface, $administratorRoute->name);
        self::assertSame($portalSurface, $portalNavigation->surface);
        self::assertSame($portalSurface, $portalRoute->name);
    }

    /**
     * Both 63-character package segments remain representable inside the 191-character contribution bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaximumExtensionOwnerSegmentsRemainRepresentable(): void
    {
        $vendor = '9' . str_repeat('a', 62);
        $package = '2' . str_repeat('b', 62);
        $owner = ContributionOwner::extension($vendor . '/' . $package);
        $surface = SurfaceId::fromString($vendor . '.' . $package . '.workspace');

        $owner->assertOwns($surface->value(), 'interface surface');

        self::assertSame($vendor . '.' . $package . '.workspace', $surface->value());
    }

    /**
     * Historical package dots remain representable only as part of the exact declaring owner prefix.
     *
     * @param   string  $ownerIdentifier  Canonical slash-separated package identifier.
     * @param   string  $namespace        Historical dotted contribution namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('legacyDottedOwners')]
    public function testLegacyOwnerDotSpellingsRemainRepresentable(
        string $ownerIdentifier,
        string $namespace,
    ): void {
        $owner = ContributionOwner::extension($ownerIdentifier);
        $identifier = $namespace . '.workspace';

        $owner->assertOwns(SurfaceId::fromString($identifier)->value(), 'interface surface');
        AdministratorWorkspaceDefinition::assertIdentifier($identifier, 'workspace');
        PortalWorkspaceDefinition::assertIdentifier($identifier, 'workspace');

        self::assertSame($namespace, $owner->namespace());
    }

    /**
     * Supply canonical package spellings whose legacy slash-to-dot mapping contains repeated dots.
     *
     * @return  iterable<string, array{string, string}>
     *
     * @since   2.0.0
     */
    public static function legacyDottedOwners(): iterable
    {
        yield 'repeated vendor dot' => ['a../b', 'a...b'];
        yield 'trailing vendor dot' => ['a./b', 'a..b'];
        yield 'trailing package dot' => ['a/b.', 'a.b.'];
    }

    /**
     * Repeated dots outside the exact owner prefix cannot become an ambiguous core contribution suffix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnerBoundaryRejectsRepeatedDotsInContributionSuffix(): void
    {
        SurfaceId::fromString('core..settings');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('core namespace');

        ContributionOwner::core()->assertOwns('core..settings', 'interface surface');
    }

    /**
     * Every shared lexical parser rejects unsafe boundaries, path characters, casing drift, and overlength values.
     *
     * @param   string  $identifier  Unsafe contribution identifier candidate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidIdentifiers')]
    public function testSharedGrammarRejectsUnsafeOrAmbiguousIdentifiers(string $identifier): void
    {
        $validators = [
            static function () use ($identifier): void {
                SurfaceId::fromString($identifier);
            },
            static function () use ($identifier): void {
                AdministratorWorkspaceDefinition::assertIdentifier($identifier, 'test');
            },
            static function () use ($identifier): void {
                PortalWorkspaceDefinition::assertIdentifier($identifier, 'test');
            },
        ];

        foreach ($validators as $validator) {
            try {
                $validator();
                self::fail('Every shared contribution identifier parser must reject the candidate.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Supply representative invalid shared identifiers.
     *
     * @return  iterable<string, array{string}>
     *
     * @since   2.0.0
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'leading separator' => ['.acme.orders'];
        yield 'trailing separator' => ['acme.orders.'];
        yield 'uppercase' => ['Acme.orders'];
        yield 'path separator' => ['acme/orders.index'];
        yield 'overlength' => ['a.' . str_repeat('b', 190)];
    }
}
