<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Navigation;

use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the core administrator menu and the protected shell layout agree on one icon sprite.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorNavigationRegistry::class)]
final class AdministratorNavigationRegistryTest extends TestCase
{
    /**
     * Every protected core navigation icon resolves to one deterministic sprite symbol, and vice versa.
     *
     * The shell renders `<use href="#kumwe-icon-{icon}">` for each entry, so an icon name the layout
     * does not define draws nothing; a static reference in the layout to an undefined symbol is the same
     * defect from the other side.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreAdministratorNavigationIconsResolveToProtectedSymbols(): void
    {
        $layoutPath = dirname(__DIR__, 4) . '/templates/administrator/layout.twig';
        $layout = file_get_contents($layoutPath);
        self::assertIsString($layout, sprintf('The protected administrator layout is unreadable at %s.', $layoutPath));

        $symbolCount = preg_match_all(
            '/<symbol\s+id="kumwe-icon-([a-z][a-z0-9-]{0,63})"/D',
            $layout,
            $symbolMatches,
        );
        self::assertNotFalse($symbolCount);
        /** @var list<string> $symbols */
        $symbols = $symbolMatches[1];
        self::assertNotSame([], $symbols, 'The protected administrator layout defines no icon symbols.');
        self::assertSame($symbols, array_values(array_unique($symbols)), 'Administrator icon symbols must be unique.');

        $navigation = AdministratorNavigationRegistry::core()->ownedBy(ContributionOwner::core());
        self::assertNotSame([], $navigation, 'Core ships no administrator navigation.');
        $navigationIcons = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['icon'],
            $navigation,
        )));
        sort($navigationIcons, SORT_STRING);
        $missingNavigationIcons = array_values(array_diff($navigationIcons, $symbols));
        self::assertSame(
            [],
            $missingNavigationIcons,
            'Core navigation refers to icon symbols the protected administrator layout does not define.',
        );

        $referenceCount = preg_match_all('/href="#kumwe-icon-([a-z][a-z0-9-]{0,63})"/D', $layout, $referenceMatches);
        self::assertNotFalse($referenceCount);
        /** @var list<string> $staticReferences */
        $staticReferences = $referenceMatches[1];
        self::assertNotSame([], $staticReferences, 'The protected administrator layout references no icon symbols.');
        self::assertSame(
            [],
            array_values(array_diff(array_unique($staticReferences), $symbols)),
            'The protected administrator layout refers to an undefined static icon symbol.',
        );
    }
}
