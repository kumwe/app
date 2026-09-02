<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\LayerClassifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/LayerClassifier.php` to the two classification rules of `docs/architecture/map.md`.
 *
 * An explicit longest prefix wins, an App name falls back to the first namespace segment that names a layer, and
 * anything else is unclassifiable and refused rather than guessed.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class LayerClassifierTest extends TestCase
{
    /**
     * Scratch layer graphs written by a test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporary = [];

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
     * Remove scratch graphs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporary = [];
    }

    /**
     * A longer prefix beats a shorter one, and any prefix beats the segment rule.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLongestPrefixWinsBeforeTheSegmentRule(): void
    {
        $classifier = LayerClassifier::fromFile(GovernanceFixture::repositoryRoot() . '/docs/architecture/layers.json');

        self::assertSame('delivery', $classifier->classify('Kumwe\\App\\Http\\Handler\\Domain\\Anything'));
        self::assertSame(
            'application',
            $classifier->classify('Kumwe\\App\\Extension\\Contribution\\ContributionDefinitionChecksum'),
        );
        self::assertSame('domain', $classifier->classify('Kumwe\\App\\InterfaceStandard\\Domain\\Surface'));
        self::assertSame('shared', $classifier->classify('Kumwe\\Conversion\\Value\\MoneyValue'));
        self::assertSame('application', $classifier->classify('Kumwe\\Conversion\\Provider\\MoneyConversionPipeline'));
        self::assertSame('domain', $classifier->classify('Kumwe\\Extension\\Spi\\BusinessRecord\\Query\\RecordFilter'));
        self::assertTrue($classifier->isFirstParty('Kumwe\\Producer\\Wire\\Dispatcher'));
        self::assertFalse($classifier->isFirstParty('Kumwe\\Producerish\\Thing'));
        self::assertSame(
            ['shared', 'domain', 'application', 'infrastructure', 'presentation', 'delivery', 'kernel'],
            $classifier->layers(),
        );
    }

    /**
     * An App name uses the first namespace segment that names a layer, the rule the dependency gate enforces,
     * ignoring the class's own short name and any later layer-named segment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAppNameUsesTheFirstMatchingNamespaceSegment(): void
    {
        $classifier = LayerClassifier::fromFile(GovernanceFixture::repositoryRoot() . '/docs/architecture/layers.json');

        self::assertSame(
            'application',
            $classifier->classify('Kumwe\\App\\BusinessSurface\\Application\\Custom\\Handler'),
        );
        self::assertSame('delivery', $classifier->classify('Kumwe\\App\\Delivery\\Console\\Application\\Runner'));
        self::assertSame('domain', $classifier->classify('Kumwe\\App\\BusinessRecord\\Domain\\Money'));
        self::assertSame('kernel', $classifier->classify('Kumwe\\App\\Kernel\\ContainerFactory'));
        self::assertSame(
            'domain',
            $classifier->classify('Kumwe\\App\\Content\\Domain\\Application'),
            'The short name does not count.',
        );
        self::assertTrue(LayerClassifier::isPortable('domain'));
        self::assertFalse(LayerClassifier::isPortable('infrastructure'));
    }

    /**
     * A name nothing governs and a foreign Kumwe package are refused with a fix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnclassifiableNamesAreRefused(): void
    {
        $classifier = LayerClassifier::fromFile(GovernanceFixture::repositoryRoot() . '/docs/architecture/layers.json');

        $names = ['Kumwe\\App\\Nowhere\\Thing', 'Kumwe\\Unknown\\Domain\\Thing', 'Vendor\\Library\\Domain\\Thing'];
        foreach ($names as $name) {
            try {
                $classifier->classify($name);
                self::fail($name . ' must be unclassifiable.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString($name, $violation->getMessage());
                self::assertStringContainsString('namespace_prefixes', $violation->getMessage());
            }
        }
    }

    /**
     * A graph naming an undeclared layer, a foreign prefix or a malformed package entry is refused when loaded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedGraphsAreRefused(): void
    {
        $base = [
            'layers' => ['domain' => ['may_depend_on' => []]],
            'first_party_namespaces' => ['Kumwe\\App'],
            'namespace_segments' => ['Domain' => 'domain'],
            'namespace_prefixes' => [],
        ];
        $cases = [
            'undeclared layer' => array_merge($base, ['namespace_segments' => ['Domain' => 'nowhere']]),
            'foreign prefix' => array_merge($base, ['namespace_prefixes' => ['Kumwe\\Other\\X' => 'domain']]),
            'malformed package' => array_merge($base, ['first_party_namespaces' => ['Kumwe\\App\\Sub']]),
            'missing layers' => array_diff_key($base, ['layers' => true]),
        ];
        foreach ($cases as $label => $graph) {
            $path = sys_get_temp_dir() . '/kumwe-layers-' . bin2hex(random_bytes(8)) . '.json';
            self::assertNotFalse(file_put_contents($path, json_encode($graph, JSON_THROW_ON_ERROR)));
            $this->temporary[] = $path;
            try {
                LayerClassifier::fromFile($path);
                self::fail($label . ' must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('Fix:', $violation->getMessage(), $label);
            }
        }
    }
}
