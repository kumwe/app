<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Release;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Infrastructure\Release\StudioContextualAuthoringQualification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves contextual Studio is enabled only by exact protocol, browser, and PHP evidence.
 *
 * @since  2.0.0
 */
#[CoversClass(PinnedStudioContextualAuthoringAvailability::class)]
#[CoversClass(StudioContextualAuthoringQualification::class)]
#[CoversClass(StudioContextualAuthoringReadiness::class)]
#[CoversClass(StudioContextualAuthoringFallbackReason::class)]
final class PinnedStudioContextualAuthoringAvailabilityTest extends TestCase
{
    /**
     * Complete public package family required by the coordinated release record.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array STUDIO_PACKAGES = [
        '@kumwe/studio',
        '@kumwe/studio-core',
        '@kumwe/studio-media',
        '@kumwe/studio-preview',
        '@kumwe/studio-protocol',
        '@kumwe/studio-renderer-web',
        '@kumwe/studio-rich-text',
        '@kumwe/studio-testkit',
    ];

    /**
     * Canonical identities required by the positive protocol fixture.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array SCHEMA_IDS = [
        'authoring-target.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-target.schema.json',
        'authoring-session.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-session.schema.json',
        'authoring-save.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-save.schema.json',
        'reusable-content-type.schema.json' => 'https://schemas.kumwe.org/studio/v1/reusable-content-type.schema.json',
        'host-operations.schema.json' => 'https://schemas.kumwe.org/studio/v1/host-operations.schema.json',
    ];

    /**
     * Canonical definition names required by the contextual protocol.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array SCHEMA_DEFINITIONS = [
        'authoring-target.schema.json' => [
            'presentationState',
            'saveOutcome',
            'startKind',
            'eligibility',
            'declaration',
            'resolveRequest',
            'resolution',
        ],
        'authoring-session.schema.json' => [
            'startSource',
            'startRequest',
            'artifactCoordinates',
            'artifactState',
            'capabilities',
            'presentation',
            'snapshot',
        ],
        'authoring-save.schema.json' => [
            'saveItemDraft',
            'saveNewTypeVersionDraft',
            'saveAsNewTypeDraft',
            'saveIntent',
            'savePlan',
            'saveItemRequest',
            'saveNewTypeVersionRequest',
            'saveAsNewTypeRequest',
            'saveResult',
        ],
        'reusable-content-type.schema.json' => [
            'reference',
            'authoringPolicy',
            'definition',
            'summary',
            'listQuery',
            'listPage',
        ],
        'host-operations.schema.json' => [
            'operationCapability',
            'operationRoute',
            'portCapability',
            'portName',
        ],
    ];

    /**
     * Root definitions admitted by each contextual schema.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array ROOT_REFERENCES = [
        'authoring-target.schema.json' => ['#/$defs/declaration'],
        'authoring-session.schema.json' => ['#/$defs/snapshot'],
        'authoring-save.schema.json' => [
            '#/$defs/saveIntent',
            '#/$defs/savePlan',
            '#/$defs/saveItemRequest',
            '#/$defs/saveNewTypeVersionRequest',
            '#/$defs/saveAsNewTypeRequest',
            '#/$defs/saveResult',
        ],
        'reusable-content-type.schema.json' => ['#/$defs/definition'],
        'host-operations.schema.json' => [],
    ];

    /**
     * Temporary fixture root built by a positive-path test.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $fixtureRoot = null;

    /**
     * The withdrawn RC stays unavailable even though its release record claims authoring-web.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentPinnedRcFailsClosedOnMissingContextualProtocol(): void
    {
        $root = dirname(__DIR__, 5);
        $release = (string) file_get_contents($root . '/resources/studio-contract/studio-release.json');
        self::assertStringContainsString('studio.profile/authoring-web', $release);

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * Complete protocol and browser evidence still cannot bypass App's PHP-adapter qualification.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHostAdapterReadinessIsAnIndependentRequiredBoundary(): void
    {
        $root = $this->contextualFixture();

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
    }

    /**
     * Qualified synthetic protocol evidence cannot enable a missing packaged browser runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrowserRuntimeIsAnIndependentRequiredBoundary(): void
    {
        $root = $this->contextualFixture();
        $qualification = $this->qualification($root);
        self::assertTrue(unlink($root . '/public/assets/build/js/studio-contextual-fixture.js'));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, $qualification))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable, $readiness->reason);
    }

    /**
     * Explicitly qualified synthetic evidence exercises the ready path without claiming a real release.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitlyQualifiedSyntheticEvidenceCanExerciseReadyPath(): void
    {
        $root = $this->contextualFixture();
        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            $this->qualification($root),
        ))->current();

        self::assertTrue($readiness->available);
        self::assertNull($readiness->reason);
        self::assertSame([
            'available' => true,
            'fallback' => 'structured-form',
            'reason' => null,
        ], $readiness->toArray());
    }

    /**
     * Evidence changed after App qualification remains unavailable even when its path is still valid.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQualificationRefusesDeploymentEvidenceChangedAfterReview(): void
    {
        $root = $this->contextualFixture();
        $qualification = $this->qualification($root);
        self::assertNotFalse(file_put_contents(
            $root . '/public/assets/build/js/studio-contextual-fixture.js',
            "export const changedAfterQualification = true;\n",
        ));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, $qualification))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
    }

    /**
     * A qualification cannot carry a moving release label or a non-SHA-256 coordinate.
     *
     * @param   string  $release  Candidate exact release.
     * @param   string  $digest   Candidate release-record digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidQualifications')]
    public function testQualificationRequiresExactImmutableCoordinates(string $release, string $digest): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StudioContextualAuthoringQualification(
            $release,
            $digest,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
        );
    }

    /**
     * Supply one invalid semantic release and one invalid digest to the qualification guard.
     *
     * @return  iterable<string, array{string, string}>  Named refusal cases.
     *
     * @since   2.0.0
     */
    public static function invalidQualifications(): iterable
    {
        yield 'moving release label' => ['latest', str_repeat('a', 64)];
        yield 'non SHA-256 digest' => ['0.1.0-test.1', 'sha256-not-hex'];
    }

    /**
     * A matching self-declared digest cannot turn an empty file into a canonical Studio schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSelfDeclaredDigestCannotMakeEmptySchemaCanonical(): void
    {
        $root = $this->contextualFixture();
        $schemaPath = $root . '/resources/studio-contract/protocol/schemas/authoring-target.schema.json';
        $emptySchema = "{}\n";
        self::assertNotFalse(file_put_contents($schemaPath, $emptySchema));

        $manifestPath = $root . '/resources/studio-contract/protocol/schemas/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $schemaEntries = $manifest['schemas'] ?? null;
        self::assertIsArray($schemaEntries);
        foreach ($schemaEntries as &$entry) {
            if (is_array($entry) && ($entry['file'] ?? null) === 'authoring-target.schema.json') {
                $entry['digest'] = $this->sri($emptySchema);
            }
        }
        unset($entry);
        $manifest['schemas'] = $schemaEntries;
        self::assertNotFalse(file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            $this->qualification($root),
        ))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * Remove only the deterministic files and directories this test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->fixtureRoot === null) {
            return;
        }
        foreach (
            [
                '/resources/studio-contract/PIN.json',
                '/resources/studio-contract/studio-release.json',
                '/resources/studio-contract/protocol/studio-release.json',
                '/resources/studio-contract/protocol/package.json',
                '/resources/studio-contract/protocol/schemas/authoring-target.schema.json',
                '/resources/studio-contract/protocol/schemas/authoring-session.schema.json',
                '/resources/studio-contract/protocol/schemas/authoring-save.schema.json',
                '/resources/studio-contract/protocol/schemas/reusable-content-type.schema.json',
                '/resources/studio-contract/protocol/schemas/host-operations.schema.json',
                '/resources/studio-contract/protocol/schemas/manifest.json',
                '/public/assets/build/.vite/manifest.json',
                '/public/assets/build/js/studio-contextual-fixture.js',
            ] as $file
        ) {
            @unlink($this->fixtureRoot . $file);
        }
        foreach (self::STUDIO_PACKAGES as $package) {
            @unlink($this->fixtureRoot . '/resources/studio-contract/packages/' . $this->packageFile($package));
        }
        foreach (
            [
                '/public/assets/build/.vite',
                '/public/assets/build/js',
                '/public/assets/build',
                '/public/assets',
                '/public',
                '/resources/studio-contract/protocol/schemas',
                '/resources/studio-contract/protocol',
                '/resources/studio-contract/packages',
                '/resources/studio-contract',
                '/resources',
                '',
            ] as $directory
        ) {
            @rmdir($this->fixtureRoot . $directory);
        }
        $this->fixtureRoot = null;
    }

    /**
     * Build minimal synthetic evidence for the gate's explicitly qualified path.
     *
     * @return  string  Absolute fixture root.
     *
     * @since   2.0.0
     */
    private function contextualFixture(): string
    {
        if ($this->fixtureRoot !== null) {
            return $this->fixtureRoot;
        }
        $this->fixtureRoot = sys_get_temp_dir() . '/kumwe-studio-readiness-' . bin2hex(random_bytes(8));
        $schemas = $this->fixtureRoot . '/resources/studio-contract/protocol/schemas';
        $packages = $this->fixtureRoot . '/resources/studio-contract/packages';
        $manifest = $this->fixtureRoot . '/public/assets/build/.vite';
        $javascript = $this->fixtureRoot . '/public/assets/build/js';
        foreach ([$schemas, $packages, $manifest, $javascript] as $directory) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        $operations = [
            'studio.operation/authoring.resolve-target' => 'authoring/resolve-target',
            'studio.operation/authoring.list-types' => 'authoring/list-types',
            'studio.operation/authoring.start' => 'authoring/start',
            'studio.operation/authoring.plan-save' => 'authoring/plan-save',
            'studio.operation/authoring.save-item' => 'authoring/save-item',
            'studio.operation/authoring.save-new-type-version' => 'authoring/save-new-type-version',
            'studio.operation/authoring.save-as-new-type' => 'authoring/save-as-new-type',
        ];
        $schemaManifest = [];
        foreach (self::SCHEMA_IDS as $file => $id) {
            $definitions = array_fill_keys(
                self::SCHEMA_DEFINITIONS[$file],
                ['type' => 'object'],
            );
            if ($file === 'host-operations.schema.json') {
                $definitions['operationCapability'] = ['enum' => array_keys($operations)];
                $definitions['operationRoute'] = ['enum' => array_values($operations)];
                $definitions['portCapability'] = ['enum' => ['studio.port/authoring']];
                $definitions['portName'] = ['enum' => ['authoring']];
            }
            $schemaDocument = [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                '$id' => $id,
                '$defs' => $definitions,
            ];
            $rootReferences = self::ROOT_REFERENCES[$file];
            if (count($rootReferences) === 1) {
                $schemaDocument['$ref'] = $rootReferences[0];
            } elseif ($rootReferences !== []) {
                $schemaDocument['oneOf'] = array_map(
                    static fn (string $reference): array => ['$ref' => $reference],
                    $rootReferences,
                );
            }
            $bytes = json_encode(
                $schemaDocument,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
            self::assertNotFalse(file_put_contents($schemas . '/' . $file, $bytes));
            $schemaManifest[] = [
                'digest' => $this->sri($bytes),
                'file' => $file,
                'id' => $id,
            ];
        }
        self::assertNotFalse(file_put_contents(
            $schemas . '/manifest.json',
            json_encode([
                'contractVersion' => '0.1-draft',
                'epoch' => 'https://schemas.kumwe.org/studio/v1/',
                'generator' => [
                    'name' => '@kumwe/studio/schema-manifest',
                    'version' => '1.0.0',
                ],
                'kind' => 'schema-manifest',
                'schemas' => $schemaManifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        $releaseName = '0.1.0-test.1';
        $releasePackages = array_fill_keys(self::STUDIO_PACKAGES, $releaseName);
        $releaseBytes = json_encode([
            'contractVersion' => '0.1-draft',
            'kind' => 'studio-release',
            'release' => $releaseName,
            'packages' => $releasePackages,
            'protocolVersion' => '0.1.0-draft.3',
            'corpusManifestDigest' => $this->sri('fixture-corpus'),
            'claimedProfiles' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $contractRoot = $this->fixtureRoot . '/resources/studio-contract';
        self::assertNotFalse(file_put_contents($contractRoot . '/studio-release.json', $releaseBytes));
        self::assertNotFalse(file_put_contents($contractRoot . '/protocol/studio-release.json', $releaseBytes));
        self::assertNotFalse(file_put_contents(
            $contractRoot . '/protocol/package.json',
            json_encode([
                'name' => '@kumwe/studio-protocol',
                'version' => $releaseName,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        $pins = [];
        foreach (self::STUDIO_PACKAGES as $package) {
            $file = $this->packageFile($package);
            $bytes = $package . "\n";
            self::assertNotFalse(file_put_contents($packages . '/' . $file, $bytes));
            $pins[$package] = [
                'version' => $releaseName,
                'file' => $file,
                'npm_tarball_sha256' => hash('sha256', $bytes),
            ];
        }
        self::assertNotFalse(file_put_contents(
            $contractRoot . '/PIN.json',
            json_encode([
                'release_record' => [
                    'release' => $releaseName,
                    'file' => 'studio-release.json',
                    'sha256' => hash('sha256', $releaseBytes),
                ],
                'pinned' => $pins,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        self::assertNotFalse(file_put_contents(
            $manifest . '/manifest.json',
            json_encode([
                'assets/administrator/components/studio-contextual.ts' => [
                    'file' => 'js/studio-contextual-fixture.js',
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertNotFalse(file_put_contents($javascript . '/studio-contextual-fixture.js', "export {};\n"));

        return $this->fixtureRoot;
    }

    /**
     * Explicitly trust the exact synthetic evidence created by this isolated unit fixture.
     *
     * Production does not derive this value from its own files: reviewed ContainerFactory wiring
     * must carry the independently selected release coordinates, and currently supplies null.
     *
     * @param   string  $root  Synthetic fixture root.
     *
     * @return  StudioContextualAuthoringQualification  Exact fixture receipt.
     *
     * @since   2.0.0
     */
    private function qualification(string $root): StudioContextualAuthoringQualification
    {
        $contractRoot = $root . '/resources/studio-contract';
        $release = json_decode(
            (string) file_get_contents($contractRoot . '/studio-release.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($release);
        $releaseName = $release['release'] ?? null;
        self::assertIsString($releaseName);

        return new StudioContextualAuthoringQualification(
            $releaseName,
            $this->sha256($contractRoot . '/studio-release.json'),
            $this->sha256($contractRoot . '/PIN.json'),
            $this->sha256($contractRoot . '/protocol/schemas/manifest.json'),
            $this->sha256($root . '/public/assets/build/.vite/manifest.json'),
            $this->sha256($root . '/public/assets/build/js/studio-contextual-fixture.js'),
        );
    }

    /**
     * Read one exact fixture digest or fail the test before constructing a qualification.
     *
     * @param   string  $path  Fixture file to hash.
     *
     * @return  string  Lower-case hexadecimal SHA-256.
     *
     * @since   2.0.0
     */
    private function sha256(string $path): string
    {
        $digest = hash_file('sha256', $path);
        self::assertIsString($digest);

        return $digest;
    }

    /**
     * Derive a deterministic safe fixture tarball name from one package name.
     *
     * @param   string  $package  Public Studio package name.
     *
     * @return  string  Basename used by the synthetic pin.
     *
     * @since   2.0.0
     */
    private function packageFile(string $package): string
    {
        return str_replace(['@kumwe/', '/'], ['', '-'], $package) . '.tgz';
    }

    /**
     * Compute Studio's SRI spelling for deterministic fixture bytes.
     *
     * @param   string  $bytes  Fixture bytes to digest.
     *
     * @return  string  `sha256-<base64>` digest.
     *
     * @since   2.0.0
     */
    private function sri(string $bytes): string
    {
        return 'sha256-' . base64_encode(hash('sha256', $bytes, true));
    }
}
