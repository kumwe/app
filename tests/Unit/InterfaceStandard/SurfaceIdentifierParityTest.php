<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Contribution\AdministratorNavigationDefinition;
use Kumwe\Extension\Spi\Contribution\AdministratorRouteDefinition;
use Kumwe\Extension\Spi\Contribution\AdministratorViewDefinition;
use Kumwe\Extension\Spi\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\Extension\Spi\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\Extension\Spi\Portal\Contribution\PortalRouteDefinition;
use Kumwe\Extension\Spi\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\Extension\Spi\Portal\Contribution\PortalWorkspaceDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves owner-bound KIS surfaces and their graphical contribution bindings share one safe grammar.
 *
 * @since  2.0.0
 */
#[CoversClass(SurfaceId::class)]
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
     * Both 63-character package segments remain representable inside the 191-character surface bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaximumExtensionOwnerSegmentsRemainRepresentable(): void
    {
        $vendor = '9' . str_repeat('a', 62);
        $package = '2' . str_repeat('b', 62);
        $surface = SurfaceId::fromString($vendor . '.' . $package . '.workspace');

        self::assertSame($vendor . '.' . $package . '.workspace', $surface->value());
    }

    /**
     * Historical package dots remain representable in a surface identifier.
     *
     * @param   string  $namespace  Historical dotted contribution namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('legacyDottedNamespaces')]
    public function testLegacyOwnerDotSpellingsRemainRepresentable(string $namespace): void
    {
        $identifier = $namespace . '.workspace';

        self::assertSame($identifier, SurfaceId::fromString($identifier)->value());
    }

    /**
     * Supply historical dotted namespaces whose legacy slash-to-dot mapping contains repeated dots.
     *
     * @return  iterable<string, array{string}>
     *
     * @since   2.0.0
     */
    public static function legacyDottedNamespaces(): iterable
    {
        yield 'repeated vendor dot' => ['a...b'];
        yield 'trailing vendor dot' => ['a..b'];
        yield 'trailing package dot' => ['a.b.'];
    }

    /**
     * The surface identifier parser rejects unsafe boundaries, path characters, casing drift, and overlength values.
     *
     * @param   string  $identifier  Unsafe surface identifier candidate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidIdentifiers')]
    public function testSharedGrammarRejectsUnsafeOrAmbiguousIdentifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        SurfaceId::fromString($identifier);
    }

    /**
     * Supply representative invalid surface identifiers.
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
