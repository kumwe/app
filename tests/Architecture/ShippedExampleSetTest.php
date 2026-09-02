<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins every shipped example package to the demonstration's default set or to an explicit, reasoned exclusion.
 *
 * The library adoption deleted one example directory and trimmed it out of both demo commands' default
 * lists in the same commit, so the demonstration quietly installed three examples where the documentation
 * still promised four and nothing in the suite noticed. The set is inventory, and inventory is pinned:
 * both commands must carry the same default list, every default must be a directory with a manifest and a
 * README (the authoring check the conformance gate runs), and every example directory must be either a
 * default or named here with the reason it is left to operators. A future extraction that drops an example
 * therefore fails this test instead of the demonstration.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ShippedExampleSetTest extends TestCase
{
    /**
     * Example directories the demonstration deliberately does not install by default, with the reason.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array LEFT_TO_OPERATORS = [
        'minimal-administrator-template' => 'the installable KIS 1.0 administrator-shell contract fixture; '
            . 'selecting an administrator template is an operator decision',
        'minimal-template' => 'the minimal site-template override fixture; the demonstration ships the '
            . 'Horizon theme as its selectable site template instead',
    ];

    /**
     * Both demo commands install the same default examples, and every shipped example is accounted for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryShippedExampleIsADemoDefaultOrAReasonedExclusion(): void
    {
        $root = dirname(__DIR__, 2);
        $install = $this->defaultExamples($root . '/src/Delivery/Console/Command/DemoInstallCommand.php');
        $examples = $this->defaultExamples($root . '/src/Delivery/Console/Command/DemoExamplesCommand.php');
        self::assertSame($install, $examples, 'demo:install and demo:install-examples must share one default set.');
        self::assertNotSame([], $install);

        $shipped = [];
        foreach (glob($root . '/examples/extensions/*/kumwe.json') ?: [] as $manifest) {
            $shipped[] = basename(dirname($manifest));
        }
        sort($shipped, SORT_STRING);
        self::assertNotSame([], $shipped);

        foreach ($install as $example) {
            self::assertContains($example, $shipped, sprintf(
                'The default example "%s" has no directory with a manifest under examples/extensions.',
                $example,
            ));
            self::assertFileExists(
                sprintf('%s/examples/extensions/%s/README.md', $root, $example),
                sprintf('The default example "%s" must carry the README the conformance gate checks.', $example),
            );
        }
        foreach ($shipped as $example) {
            self::assertTrue(
                in_array($example, $install, true) || isset(self::LEFT_TO_OPERATORS[$example]),
                sprintf(
                    'The shipped example "%s" is neither a demonstration default nor a reasoned exclusion; '
                    . 'add it to both DEFAULT_EXAMPLES lists or name it in this test with the reason.',
                    $example,
                ),
            );
        }
        foreach (array_keys(self::LEFT_TO_OPERATORS) as $excluded) {
            self::assertContains($excluded, $shipped, sprintf(
                'The exclusion "%s" names an example that no longer ships; remove it here.',
                $excluded,
            ));
            self::assertNotContains($excluded, $install, sprintf(
                'The example "%s" is both a default and an exclusion; pick one.',
                $excluded,
            ));
        }
    }

    /**
     * Read one command's literal `DEFAULT_EXAMPLES` list from its source.
     *
     * @param   string  $path  Absolute path of the command class.
     *
     * @return  list<string>  The example names in declaration order.
     *
     * @since   2.0.0
     */
    private function defaultExamples(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertSame(
            1,
            preg_match('/DEFAULT_EXAMPLES\s*=\s*\[(?<entries>[^\]]*)\];/', $source, $match),
            sprintf('%s must declare DEFAULT_EXAMPLES as one literal list.', basename($path)),
        );
        preg_match_all('/\'(?<name>[a-z][a-z0-9-]*)\'/', $match['entries'], $entries);

        return $entries['name'];
    }
}
