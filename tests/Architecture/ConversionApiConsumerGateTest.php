<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Proves App consumes Conversion's package-owned API profile without copying its type-shape manifest.
 *
 * The package publishes the canonical evidence; App records one digest and count. These tests prove the
 * consumer gate rebuilds that digest from full installed shapes, refuses reordered or unavailable members,
 * and keeps the App record smaller than the authority it verifies.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ConversionApiConsumerGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Temporary evidence records written by tests.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporary = [];

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Remove isolated JSON records.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
    }

    /**
     * The installed immutable Conversion release satisfies App's reviewed provider profile.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstalledConversionProfileMatchesConsumerEvidence(): void
    {
        $result = $this->execute([]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Conversion consumer profile verified', $result['output']);
        self::assertStringContainsString('15 canonical types', $result['output']);
    }

    /**
     * A canonical package-owned manifest and its minimal consumer coordinate pass together.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalSyntheticProfilePasses(): void
    {
        $result = $this->executeFixture($this->fixture());

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('extension-provider-v1, 15 canonical types', $result['output']);
    }

    /**
     * App stores no copied type names or shapes beside the package-owned manifest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsumerEvidenceContainsOnlyTheCoordinateDigestAndCount(): void
    {
        $consumer = $this->document($this->root . '/docs/architecture/conversion-api-profile.json');

        self::assertSame(['package', 'profile', 'digest', 'type_count'], array_keys($consumer));
        self::assertSame('kumwe/conversion', $consumer['package']);
        self::assertSame('extension-provider-v1', $consumer['profile']);
        self::assertSame(
            'sha256:c2e371b2da3d2f22c21e1be1c779dda339cb75e5070f1c594725d0e051751bd5',
            $consumer['digest'],
        );
        self::assertSame(15, $consumer['type_count']);
        self::assertArrayNotHasKey('types', $consumer);
        self::assertArrayNotHasKey('roots', $consumer);
    }

    /**
     * Changing a full reflected shape moves the independently rebuilt digest even when names do not move.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAChangedFullTypeShapeCannotHideBehindThePublishedDigest(): void
    {
        $fixture = $this->fixture();
        $first = $fixture['manifest']['profiles']['extension-provider-v1']['types'][0];
        self::assertIsString($first);
        $fixture['manifest']['types'][$first]['changed'] = true;
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('not reproducible from its full canonical type shapes', $result['output']);
        self::assertStringContainsString('does not match App consumer evidence', $result['output']);
    }

    /**
     * The embedded package digest is evidence to verify, not a string App trusts by comparison.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFalseEmbeddedProfileDigestFails(): void
    {
        $fixture = $this->fixture();
        $fixture['manifest']['profiles']['extension-provider-v1']['digest'] = 'sha256:' . str_repeat('0', 64);
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('not reproducible from its full canonical type shapes', $result['output']);
    }

    /**
     * Type order is part of the canonical profile projection and cannot drift under a recomputed digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReorderedProfileFailsEvenWithMatchingRecomputedEvidence(): void
    {
        $fixture = $this->fixture();
        $fixture['manifest']['profiles']['extension-provider-v1']['types'] = array_reverse(
            $fixture['manifest']['profiles']['extension-provider-v1']['types'],
        );
        $this->refreshDigest($fixture);
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('profile types are not canonically ordered', $result['output']);
    }

    /**
     * A duplicated member cannot preserve the advertised cardinality by occupying two list positions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADuplicateProfileTypeFails(): void
    {
        $fixture = $this->fixture();
        $types =& $fixture['manifest']['profiles']['extension-provider-v1']['types'];
        $types[count($types) - 1] = $types[0];
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('profile types contain duplicates', $result['output']);
    }

    /**
     * A manifest-only type name is not evidence that the installed package can actually supply the type.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnavailableCanonicalProfileTypeFailsReflection(): void
    {
        $fixture = $this->fixture();
        $types =& $fixture['manifest']['profiles']['extension-provider-v1']['types'];
        $removed = array_pop($types);
        self::assertIsString($removed);
        unset($fixture['manifest']['types'][$removed]);
        $missing = 'Kumwe\\Conversion\\Provider\\UnavailableFixtureType';
        $types[] = $missing;
        sort($types, SORT_STRING);
        $fixture['manifest']['types'][$missing] = ['kind' => 'interface'];
        ksort($fixture['manifest']['types'], SORT_STRING);
        $this->refreshDigest($fixture);
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('is not available through the installed autoloader', $result['output']);
        self::assertStringContainsString($missing, $result['output']);
    }

    /**
     * Composer, qa, the quality contract, and CI invoke the consumer verifier explicitly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConversionConsumerVerificationIsAQualityGate(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame(
            'php tools/verify-conversion-api.php',
            $composer['scripts']['conversion:api'] ?? null,
        );
        self::assertContains('@conversion:api', $composer['scripts']['qa'] ?? []);

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        $checks = array_values(array_filter(
            $contract['checks'] ?? [],
            static fn (mixed $check): bool => is_array($check)
                && ($check['id'] ?? null) === 'conversion-api-consumer',
        ));
        self::assertCount(1, $checks);
        self::assertSame('conversion:api', $checks[0]['composer_script'] ?? null);

        $workflow = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'composer conversion:api'));
    }

    /**
     * Build a complete profile from installed canonical types without copying the package's reviewed set.
     *
     * @return  array{manifest: array<string, mixed>, consumer: array<string, mixed>}  Synthetic evidence.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $source = $this->root . '/vendor/kumwe/conversion/src';
        $available = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1, -4);
            $available[] = 'Kumwe\\Conversion\\' . str_replace('/', '\\', $relative);
        }
        sort($available, SORT_STRING);
        self::assertGreaterThanOrEqual(15, count($available));

        $roots = array_values(array_filter(
            $available,
            static fn (string $type): bool => preg_match(
                '/\\\\Provider\\\\(?:MoneyRate|UnitConversion)Provider$/D',
                $type,
            ) === 1,
        ));
        self::assertCount(2, $roots);

        $profileTypes = $roots;
        foreach ($available as $type) {
            if (count($profileTypes) === 15) {
                break;
            }
            if (!in_array($type, $profileTypes, true)) {
                $profileTypes[] = $type;
            }
        }
        sort($profileTypes, SORT_STRING);
        $shapes = [];
        foreach ($profileTypes as $type) {
            $shapes[$type] = ['kind' => 'synthetic-reflection-shape', 'canonical_name' => $type];
        }

        $fixture = [
            'manifest' => [
                'schema' => 1,
                'package' => 'kumwe/conversion',
                'namespace' => 'Kumwe\\Conversion\\',
                'profiles' => [
                    'extension-provider-v1' => [
                        'roots' => $roots,
                        'types' => $profileTypes,
                        'digest' => '',
                    ],
                ],
                'types' => $shapes,
            ],
            'consumer' => [
                'package' => 'kumwe/conversion',
                'profile' => 'extension-provider-v1',
                'digest' => '',
                'type_count' => 15,
            ],
        ];
        $this->refreshDigest($fixture);

        return $fixture;
    }

    /**
     * Recompute the package profile and matching synthetic consumer digest after a deliberate mutation.
     *
     * @param   array{manifest: array<string, mixed>, consumer: array<string, mixed>}  $fixture  Evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function refreshDigest(array &$fixture): void
    {
        $profile =& $fixture['manifest']['profiles']['extension-provider-v1'];
        $selected = [];
        foreach ($profile['types'] as $type) {
            $selected[$type] = $fixture['manifest']['types'][$type];
        }
        $projection = [
            'schema' => 1,
            'profile' => 'extension-provider-v1',
            'roots' => $profile['roots'],
            'types' => $selected,
        ];
        $digest = 'sha256:' . hash('sha256', json_encode(
            $projection,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $profile['digest'] = $digest;
        $fixture['consumer']['digest'] = $digest;
        $fixture['consumer']['type_count'] = count($profile['types']);
    }

    /**
     * Write isolated package and consumer records, then execute the verifier.
     *
     * @param   array{manifest: array<string, mixed>, consumer: array<string, mixed>}  $fixture  Evidence.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function executeFixture(array $fixture): array
    {
        return $this->execute([
            '--manifest=' . $this->write($fixture['manifest']),
            '--consumer=' . $this->write($fixture['consumer']),
        ]);
    }

    /**
     * Execute the dependency-free consumer verifier.
     *
     * @param   list<string>  $arguments  Evidence overrides.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function execute(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-conversion-api.php'),
        );
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Write one canonical JSON fixture.
     *
     * @param   array<string, mixed>  $document  JSON object.
     *
     * @return  string  Absolute temporary path.
     *
     * @since   2.0.0
     */
    private function write(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-conversion-api-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n"));
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * Decode one repository JSON object.
     *
     * @param   string  $path  Document path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
