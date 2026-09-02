<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\PhpDeclarationScanner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/PhpDeclarationScanner.php` to the surface and references it must report.
 *
 * The scanner is the eye of both governance gates: it decides which declarations exist, what their public
 * surface is, and which names a file references. These cases pin kinds, modifiers, promoted properties,
 * typed signatures, enum cases, `@internal` detection, import resolution and the rule that comments and
 * strings never produce references.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PhpDeclarationScannerTest extends TestCase
{
    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * Kinds, modifiers, parents, interfaces and the public surface are reported for every class-like.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclarationsCarryTheirKindModifiersAndPublicSurface(): void
    {
        $scan = PhpDeclarationScanner::scanSource(self::sample(), 'sample.php');
        $declarations = [];
        foreach ($scan['declarations'] as $declaration) {
            $declarations[$declaration['fqcn']] = $declaration;
        }

        $sample = $declarations['Kumwe\\App\\Sample\\Sample'];
        self::assertSame('class', $sample['kind']);
        self::assertTrue($sample['final']);
        self::assertTrue($sample['readonly']);
        self::assertFalse($sample['abstract']);
        self::assertTrue($sample['internal']);
        self::assertSame('Kumwe\\App\\Sample\\Base', $sample['parent']);
        self::assertSame(['Kumwe\\App\\Old\\Alpha', 'Iterator', 'Kumwe\\App\\Old\\Beta'], $sample['interfaces']);
        self::assertSame(['A', 'C', 'NAME'], $sample['constants'], 'Protected constants are not public surface.');
        self::assertSame(['asym', 'bare', 'count', 'hooked', 'promoted'], array_keys($sample['properties']));
        self::assertSame(
            ['type' => '?Kumwe\\App\\Old\\Thing', 'static' => false, 'readonly' => true],
            $sample['properties']['promoted'],
        );
        self::assertSame(['type' => 'int', 'static' => true, 'readonly' => false], $sample['properties']['count']);
        self::assertSame(['__construct', 'list', 'make', 'run'], array_keys($sample['methods']));
        self::assertSame('static', $sample['methods']['run']['return']);
        self::assertSame(
            [
                ['name' => 'a', 'type' => 'int', 'optional' => false, 'variadic' => false, 'by_reference' => false],
                [
                    'name' => 'b',
                    'type' => 'Kumwe\\App\\Old\\Thing|Kumwe\\App\\Old\\Beta|null',
                    'optional' => true,
                    'variadic' => false,
                    'by_reference' => false,
                ],
                [
                    'name' => 'rest',
                    'type' => 'Kumwe\\App\\Sample\\Marker',
                    'optional' => false,
                    'variadic' => true,
                    'by_reference' => false,
                ],
                ['name' => 'out', 'type' => 'array', 'optional' => true, 'variadic' => false, 'by_reference' => true],
            ],
            $sample['methods']['run']['parameters'],
        );
        self::assertTrue($sample['methods']['make']['static']);
        self::assertSame(
            '(Kumwe\\App\\Old\\Alpha&Kumwe\\App\\Old\\Beta)|null',
            $sample['methods']['make']['parameters'][0]['type'],
        );
        self::assertSame('?self', $sample['methods']['make']['return']);

        $level = $declarations['Kumwe\\App\\Sample\\Level'];
        self::assertSame('enum', $level['kind']);
        self::assertSame(['Low', 'High'], $level['cases']);
        self::assertSame(['Kumwe\\App\\Old\\Alpha'], $level['interfaces']);
        self::assertSame(['DEFAULT'], $level['constants']);

        $port = $declarations['Kumwe\\App\\Sample\\Port'];
        self::assertSame('interface', $port['kind']);
        self::assertSame(['Kumwe\\App\\Old\\Alpha', 'Kumwe\\App\\Old\\Beta'], $port['interfaces']);
        self::assertSame('void', $port['methods']['handle']['return']);

        self::assertTrue($declarations['Kumwe\\App\\Sample\\Shape']['abstract']);
        self::assertSame(['name'], array_keys($declarations['Kumwe\\App\\Sample\\Shape']['methods']));
        self::assertSame('trait', $declarations['Kumwe\\App\\Sample\\SomeTrait']['kind']);
        self::assertFalse($declarations['Kumwe\\App\\Sample\\Level']['internal']);
    }

    /**
     * Imports, grouped imports, inline names, `::class`, `new`, `instanceof`, `catch` and attributes are references;
     * comments and strings are not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReferencesResolveThroughImportsAndIgnoreCommentsAndStrings(): void
    {
        $scan = PhpDeclarationScanner::scanSource(self::sample(), 'sample.php');
        $names = array_values(array_unique(array_column($scan['references'], 'name')));
        sort($names, SORT_STRING);

        self::assertSame(
            [
                'Kumwe\\App\\Old\\Thing' => 'Kumwe\\App\\Old\\Thing',
                'Alpha' => 'Kumwe\\App\\Old\\Alpha',
                'B' => 'Kumwe\\App\\Old\\Beta',
                'Container' => 'Psr\\Container\\ContainerInterface',
            ],
            array_combine(['Kumwe\\App\\Old\\Thing', 'Alpha', 'B', 'Container'], array_values($scan['imports'])),
        );
        foreach (
            [
                'Kumwe\\App\\Old\\Thing',
                'Kumwe\\App\\Old\\Alpha',
                'Kumwe\\App\\Old\\Beta',
                'Psr\\Container\\ContainerInterface',
                'Kumwe\\App\\Sample\\Attr',
                'Kumwe\\App\\Sample\\Marker',
                'Kumwe\\App\\Sample\\Other',
                'Kumwe\\App\\Sample\\Base',
                'Kumwe\\App\\Sample\\SomeTrait',
                'Kumwe\\App\\Sample\\Zeta',
                'Kumwe\\App\\Sample\\E',
                'Ex\\Cept',
                'Kumwe\\App\\Sample\\Local',
                'Kumwe\\App\\Sample\\Gamma',
                'Iterator',
            ] as $expected
        ) {
            self::assertContains($expected, $names);
        }
        self::assertNotContains('Kumwe\\App\\Old\\InString', $names, 'A string literal is not a reference.');
        self::assertNotContains('Kumwe\\App\\Old\\InComment', $names, 'A comment is not a reference.');
        self::assertNotContains('Kumwe\\App\\Sample\\helper', $names, 'A function call is not a class reference.');
        self::assertNotContains('Kumwe\\App\\Sample\\LIMIT', $names, 'A constant fetch is not a class reference.');

        $thingLines = array_column(
            array_filter(
                $scan['references'],
                static fn (array $reference): bool => $reference['name'] === 'Kumwe\\App\\Old\\Thing',
            ),
            'line',
        );
        self::assertContains(7, $thingLines, 'The import line is a reference.');
        self::assertContains(49, $thingLines, 'The ::class line is a reference.');
        self::assertContains('Kumwe\\\\App\\\\Old\\\\InString', array_column($scan['strings'], 'value'));
    }

    /**
     * A tree is scanned in path order and each file reports its own display path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATreeIsScannedInPathOrderWithDisplayPaths(): void
    {
        $root = GovernanceFixture::cleanRoot() . '/vendor/kumwe/example-v2/src';
        $scans = PhpDeclarationScanner::scanTree($root, 'vendor/kumwe/example-v2/src');

        self::assertSame(
            [
                'vendor/kumwe/example-v2/src/ConfigProvider.php',
                'vendor/kumwe/example-v2/src/Container/ExampleServiceFactory.php',
                'vendor/kumwe/example-v2/src/Contract/ExampleServiceInterface.php',
                'vendor/kumwe/example-v2/src/ExampleService.php',
                'vendor/kumwe/example-v2/src/Internal/Helper.php',
            ],
            array_column($scans, 'file'),
        );
        $internal = [];
        foreach ($scans as $scan) {
            if ($scan['declarations'][0]['internal'] === true) {
                $internal[] = $scan['declarations'][0]['fqcn'];
            }
        }
        self::assertSame(['Kumwe\Example\Internal\Helper'], $internal);
    }

    /**
     * Braced namespaces, `namespace\\Name` and string interpolation braces are handled without losing depth.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBracedNamespacesAndInterpolationKeepTheirDepth(): void
    {
        $source = <<<'PHP'
            <?php
            namespace First {
                use Other\Dep;
                final class One { public function a(): void { $x = "{$y} ${z}"; } }
            }
            namespace Second {
                class Two extends namespace\Base { public const X = 1; }
            }
            PHP;
        $scan = PhpDeclarationScanner::scanSource($source, 'braced.php');

        self::assertSame(['First\\One', 'Second\\Two'], array_column($scan['declarations'], 'fqcn'));
        self::assertSame(['a'], array_keys($scan['declarations'][0]['methods']));
        self::assertSame('Second\\Base', $scan['declarations'][1]['parent']);
        self::assertSame(['X'], $scan['declarations'][1]['constants']);
        self::assertContains('Other\\Dep', array_column($scan['references'], 'name'));
    }

    /**
     * The source every declaration case scans.
     *
     * @return  string  PHP source with one of each construct.
     *
     * @since   2.0.0
     */
    private static function sample(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Kumwe\App\Sample;

            use Kumwe\App\Old\Thing;
            use Kumwe\App\Old\{Alpha, Beta as B, function helper, const LIMIT};
            use Psr\Container\ContainerInterface as Container;
            use function strlen;

            /**
             * Doc.
             *
             * @internal
             * @since  2.0.0
             */
            #[Attr(Marker::class), Other]
            final readonly class Sample extends Base implements Alpha, \Iterator, B
            {
                use SomeTrait;
                use Other\Trait2 { foo as bar; }

                public const string NAME = "x";
                protected const HIDDEN = 1;
                public const A = 1, C = 2;

                public static int $count = 0;
                private ?Thing $thing = null;
                public string $hooked { get => "v"; }
                public private(set) int $asym = 1;

                public function __construct(
                    private Container $container,
                    public readonly ?Thing $promoted = null,
                    protected string $hidden = "",
                    readonly int $bare = 1,
                ) {
                }

                /** @return void */
                public function run(int $a, Thing|B|null $b = self::A, Marker ...$rest, array &$out = []): static
                {
                    $f = function (Alpha $x) use ($a): void { $y = new class { public function inner() {} }; };
                    $g = fn(B $y): int => 1;
                    try { $x = new Zeta(); } catch (E | \Ex\Cept $e) {}
                    $s = "Kumwe\\App\\Old\\InString";
                    // Kumwe\App\Old\InComment
                    $t = Thing::class;
                    $u = namespace\Local::NAME;
                    $v = $x instanceof Gamma;
                    return helper(LIMIT);
                }

                private function hiddenMethod(): void {}
                public static function make((Alpha&B)|null $v): ?self { return null; }
                public function list(): array { return []; }
            }

            enum Level: string implements Alpha
            {
                case Low = "low";
                case High = "high";
                public const DEFAULT = self::Low;
                public function label(): string { return match ($this) { self::Low => "l", default => "h" }; }
            }

            interface Port extends Alpha, B
            {
                public function handle(Thing $t): void;
            }

            abstract class Shape { abstract protected function area(): float;
                public function name(): string { return ""; } }
            trait SomeTrait { public function traitMethod() {} }
            PHP;
    }
}
