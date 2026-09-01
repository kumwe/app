<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Release;

use Closure;
use InvalidArgumentException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Infrastructure\Release\StudioContextualAuthoringQualification;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves contextual Studio is enabled only by Producer and exact App deployment evidence.
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
     * The pinned beta publishes the contextual protocol yet stays unavailable without a browser runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentPinnedBetaStopsClosedAtTheMissingContextualBrowserRuntime(): void
    {
        $root = dirname(__DIR__, 5);
        $release = (string) file_get_contents($root . '/resources/studio-contract/studio-release.json');
        self::assertStringContainsString('"claimedProfiles": []', $release);
        self::assertNotContains('authoring-target', StudioDocumentSchemaRegistry::DOCUMENT_KINDS);
        self::assertTrue(OperationRegistry::isCapability('studio.operation/authoring.resolve-target'));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable, $readiness->reason);
        self::assertSame([
            'available' => false,
            'fallback' => 'structured-form',
            'reason' => 'browser-runtime-unavailable',
        ], $readiness->toArray());
    }

    /**
     * Missing App-owned release evidence cannot fall through to browser or host qualification.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingAppDeploymentEvidenceFailsAtProtocolBoundary(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-studio-missing-' . bin2hex(random_bytes(8));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * A qualification carries only App-owned immutable deployment coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQualificationCarriesExactAppEvidence(): void
    {
        $qualification = new StudioContextualAuthoringQualification(
            '0.1.0-rc.1',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
        );

        self::assertSame('0.1.0-rc.1', $qualification->release);
        self::assertSame(str_repeat('a', 64), $qualification->releaseRecordSha256);
        self::assertSame(str_repeat('b', 64), $qualification->pinRecordSha256);
        self::assertSame(str_repeat('c', 64), $qualification->browserManifestSha256);
        self::assertSame(str_repeat('d', 64), $qualification->browserEntrySha256);
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
     * Packaged JavaScript file the temporary deployments publish for the contextual Vite entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string BROWSER_ENTRY = 'studio-contextual-Test0001.js';

    /**
     * Stream scheme mirroring one real deployment whose directories stat as directories yet refuse enumeration.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UNLISTABLE_SCHEME = 'kumwe-unlistable';

    /**
     * Temporary App deployment roots built by the running test and removed after it.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $deployments = [];

    /**
     * Remove every temporary deployment and the unlistable stream wrapper the test registered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->deployments as $root) {
            self::removeTree($root);
        }
        $this->deployments = [];
        if (in_array(self::UNLISTABLE_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::UNLISTABLE_SCHEME);
        }
    }

    /**
     * One exact deployment whose release, pin, browser, and host evidence all match enables contextual authoring.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAQualifiedExactDeploymentEnablesContextualAuthoring(): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, self::qualification($root)))->current();

        self::assertTrue($readiness->available);
        self::assertNull($readiness->reason);
        self::assertSame([
            'available' => true,
            'fallback' => 'structured-form',
            'reason' => null,
        ], $readiness->toArray());
    }

    /**
     * A packaged contextual browser entry without App-owned qualification stops closed at the host adapter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackagedBrowserRuntimeWithoutQualificationStopsAtTheHostAdapter(): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
        self::assertSame('host-adapter-unavailable', $readiness->toArray()['reason']);
    }

    /**
     * A qualification naming any other release or evidence digest cannot open the host adapter.
     *
     * @param   array<string, string>  $drift  Qualification coordinates replaced with non-matching values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedQualifications')]
    public function testQualificationMustMatchEveryDeployedEvidenceByte(array $drift): void
    {
        $root = $this->deployment();
        $qualification = self::qualification($root, $drift);

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, $qualification))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
    }

    /**
     * Supply one drifted coordinate per qualification member.
     *
     * @return  iterable<string, array{array<string, string>}>  Named qualification drifts.
     *
     * @since   2.0.0
     */
    public static function driftedQualifications(): iterable
    {
        yield 'another exact release' => [['release' => '0.1.0-beta.2']];
        yield 'release-record digest' => [['releaseRecordSha256' => str_repeat('0', 64)]];
        yield 'pin-record digest' => [['pinRecordSha256' => str_repeat('0', 64)]];
        yield 'browser-manifest digest' => [['browserManifestSha256' => str_repeat('0', 64)]];
        yield 'browser-entry digest' => [['browserEntrySha256' => str_repeat('0', 64)]];
    }

    /**
     * Rebuilding the contextual browser entry after qualification closes the host adapter again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARebuiltBrowserEntryInvalidatesTheHostQualification(): void
    {
        $root = $this->deployment();
        $qualification = self::qualification($root);
        file_put_contents(
            $root . '/public/assets/build/js/' . self::BROWSER_ENTRY,
            "export const mount = () => 'rebuilt';\n",
        );

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, $qualification))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
    }

    /**
     * Drifted App release, pin, or tarball evidence fails at the protocol boundary even when qualified.
     *
     * @param   Closure  $drift  Mutation applied to one otherwise fully qualified deployment root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedDeployments')]
    public function testDriftedAppDeploymentEvidenceFailsAtTheProtocolBoundary(Closure $drift): void
    {
        $root = $this->deployment();
        $drift($root);

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, self::qualification($root)))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * Supply one deployment mutation per App pin refusal.
     *
     * @return  iterable<string, array{Closure}>  Named deployment drifts.
     *
     * @since   2.0.0
     */
    public static function driftedDeployments(): iterable
    {
        $release = static fn (Closure $mutate): Closure => static function (string $root) use ($mutate): void {
            self::rewriteJson($root . '/resources/studio-contract/studio-release.json', $mutate);
        };
        $pin = static fn (Closure $mutate): Closure => static function (string $root) use ($mutate): void {
            self::rewriteJson($root . '/resources/studio-contract/PIN.json', $mutate);
        };

        yield 'release record not JSON' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/studio-release.json', '{"kind": "studio-release",');
        }];
        yield 'release record list-shaped' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/studio-release.json', '["studio-release"]');
        }];
        yield 'pin record keyed by a number' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/PIN.json', '{"1": "kumwe-studio"}');
        }];
        yield 'release packages listed instead of keyed' => [$release(static function (array $record): array {
            $record['packages'] = array_values($record['packages']);

            return $record;
        })];
        yield 'release profiles keyed instead of listed' => [$release(static function (array $record): array {
            $record['claimedProfiles'] = ['contextual' => 'kumwe/contextual-authoring'];

            return $record;
        })];
        yield 'release package named by a number' => [$release(static function (array $record): array {
            $record['packages'] = ['7' => '0.1.0-beta.3'] + $record['packages'];

            return $record;
        })];
        yield 'release package version not a string' => [$release(static function (array $record): array {
            $record['packages']['@kumwe/studio'] = 1;

            return $record;
        })];
        yield 'release contract version drifted' => [$release(static function (array $record): array {
            $record['contractVersion'] = '0.2-draft';

            return $record;
        })];
        yield 'release bytes reformatted' => [static function (string $root): void {
            $path = $root . '/resources/studio-contract/studio-release.json';
            $before = (string) file_get_contents($path);
            self::rewriteJson($path, static fn (array $record): array => $record);
            self::assertNotSame($before, file_get_contents($path));
        }];
        yield 'pin release-record digest drifted' => [$pin(static function (array $record): array {
            $record['release_record']['sha256'] = str_repeat('0', 64);

            return $record;
        })];
        yield 'pin binding another record file' => [$pin(static function (array $record): array {
            $record['release_record']['file'] = 'PIN.json';

            return $record;
        })];
        yield 'pin package family incomplete' => [$pin(static function (array $record): array {
            unset($record['pinned']['@kumwe/studio-testkit']);

            return $record;
        })];
        yield 'pin package family expanded' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio-extra'] = $record['pinned']['@kumwe/studio'];

            return $record;
        })];
        yield 'pin package version drifted' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio']['version'] = '0.1.0-beta.2';

            return $record;
        })];
        yield 'pin tarball leaving the packages directory' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio']['file'] = '../' . $record['pinned']['@kumwe/studio']['file'];

            return $record;
        })];
        yield 'pin tarball shared by two packages' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio-core'] = $record['pinned']['@kumwe/studio'];

            return $record;
        })];
        yield 'pin tarball digest uppercased' => [$pin(static function (array $record): array {
            $digest = $record['pinned']['@kumwe/studio']['npm_tarball_sha256'];
            $record['pinned']['@kumwe/studio']['npm_tarball_sha256'] = strtoupper($digest);

            return $record;
        })];
        yield 'tarball bytes drifted' => [static function (string $root): void {
            file_put_contents(self::pinnedTarball($root, '@kumwe/studio-media'), 'drifted tarball bytes');
        }];
        yield 'stray tarball in packages' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/packages/kumwe-studio-0.1.0-beta.2.tgz', 'stray');
        }];
        yield 'stray directory in packages' => [static function (string $root): void {
            self::assertTrue(mkdir($root . '/resources/studio-contract/packages/notes'));
        }];
    }

    /**
     * A packages directory that cannot be enumerated is refused rather than trusted from its pin alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnlistablePackagesDirectoryFailsAtTheProtocolBoundary(): void
    {
        $root = $this->deployment();
        self::assertTrue(stream_wrapper_register(self::UNLISTABLE_SCHEME, self::unlistableDeploymentWrapper()));
        $gate = new PinnedStudioContextualAuthoringAvailability(
            self::UNLISTABLE_SCHEME . '://' . $root,
            self::qualification($root),
        );
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];

            return true;
        });
        try {
            $readiness = $gate->current();
        } finally {
            restore_error_handler();
        }

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
        self::assertNotSame([], $warnings);
        foreach ($warnings as [$level, $message]) {
            self::assertSame(E_WARNING, $level);
            self::assertStringStartsWith('scandir(', $message);
        }
        self::assertStringContainsString('/resources/studio-contract/packages', $warnings[0][1]);
    }

    /**
     * Build one temporary App deployment mirroring the real release pin, tarballs, and a contextual browser build.
     *
     * @return  string  Absolute temporary deployment root.
     *
     * @since   2.0.0
     */
    private function deployment(): string
    {
        $app = dirname(__DIR__, 5);
        $root = sys_get_temp_dir() . '/kumwe-studio-deployment-' . bin2hex(random_bytes(8));
        $this->deployments[] = $root;
        $contract = $root . '/resources/studio-contract';
        $build = $root . '/public/assets/build';
        foreach ([$contract . '/packages', $build . '/.vite', $build . '/js'] as $directory) {
            self::assertTrue(mkdir($directory, 0755, true));
        }
        foreach (['studio-release.json', 'PIN.json'] as $record) {
            self::assertTrue(copy($app . '/resources/studio-contract/' . $record, $contract . '/' . $record));
        }
        foreach (self::json($contract . '/PIN.json')['pinned'] as $package) {
            self::assertTrue(copy(
                $app . '/resources/studio-contract/packages/' . $package['file'],
                $contract . '/packages/' . $package['file'],
            ));
        }
        self::writeJson($build . '/.vite/manifest.json', [
            'assets/administrator/components/studio-contextual.ts' => [
                'file' => 'js/' . self::BROWSER_ENTRY,
                'name' => 'studio-contextual',
                'src' => 'assets/administrator/components/studio-contextual.ts',
                'isDynamicEntry' => true,
            ],
        ]);
        file_put_contents($build . '/js/' . self::BROWSER_ENTRY, "export const mount = () => 'contextual';\n");

        return $root;
    }

    /**
     * Qualify one temporary deployment from its exact current evidence bytes, optionally drifting members.
     *
     * @param   string                 $root   Temporary deployment root.
     * @param   array<string, string>  $drift  Qualification members replaced after digesting the deployment.
     *
     * @return  StudioContextualAuthoringQualification  App-owned qualification of that deployment.
     *
     * @since   2.0.0
     */
    private static function qualification(string $root, array $drift = []): StudioContextualAuthoringQualification
    {
        $contract = $root . '/resources/studio-contract';
        $build = $root . '/public/assets/build';
        $record = json_decode((string) file_get_contents($contract . '/studio-release.json'), true);
        $coordinates = [
            'release' => is_array($record) && is_string($record['release'] ?? null) ? $record['release'] : '0.0.0',
            'releaseRecordSha256' => (string) hash_file('sha256', $contract . '/studio-release.json'),
            'pinRecordSha256' => (string) hash_file('sha256', $contract . '/PIN.json'),
            'browserManifestSha256' => (string) hash_file('sha256', $build . '/.vite/manifest.json'),
            'browserEntrySha256' => (string) hash_file('sha256', $build . '/js/' . self::BROWSER_ENTRY),
        ];

        return new StudioContextualAuthoringQualification(...array_replace($coordinates, $drift));
    }

    /**
     * Resolve the tarball one temporary deployment pins for a package.
     *
     * @param   string  $root     Temporary deployment root.
     * @param   string  $package  Public Studio package name.
     *
     * @return  string  Absolute pinned tarball path.
     *
     * @since   2.0.0
     */
    private static function pinnedTarball(string $root, string $package): string
    {
        $contract = $root . '/resources/studio-contract';

        return $contract . '/packages/' . self::json($contract . '/PIN.json')['pinned'][$package]['file'];
    }

    /**
     * Decode one object-shaped JSON evidence file.
     *
     * @param   string  $path  Absolute JSON path.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private static function json(string $path): array
    {
        $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * Write one JSON evidence file.
     *
     * @param   string                $path      Absolute JSON path.
     * @param   array<string, mixed>  $document  Document to encode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function writeJson(string $path, array $document): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        self::assertIsInt(file_put_contents($path, json_encode($document, $flags) . "\n"));
    }

    /**
     * Rewrite one JSON evidence file through a mutation of its decoded document.
     *
     * @param   string   $path    Absolute JSON path.
     * @param   Closure  $mutate  Mutation receiving and returning the decoded document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function rewriteJson(string $path, Closure $mutate): void
    {
        self::writeJson($path, $mutate(self::json($path)));
    }

    /**
     * Delete one temporary deployment tree without following links.
     *
     * @param   string  $path  Absolute file or directory path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Name a stream wrapper mirroring real files and stats while refusing to enumerate any directory.
     *
     * @return  string  Wrapper class name for `stream_wrapper_register()`.
     *
     * @since   2.0.0
     */
    private static function unlistableDeploymentWrapper(): string
    {
        // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP fixes stream wrapper method names.
        return (new class {
            /**
             * Stream context PHP assigns to every wrapper instance.
             *
             * @var    mixed
             * @since  2.0.0
             */
            public mixed $context = null;

            /**
             * Open handle on the mirrored real file.
             *
             * @var    mixed
             * @since  2.0.0
             */
            private mixed $handle = null;

            /**
             * Map one wrapper URL to the real path it mirrors.
             *
             * @param   string  $url  Wrapper URL.
             *
             * @return  string  Real absolute path.
             *
             * @since   2.0.0
             */
            private static function real(string $url): string
            {
                return substr($url, (int) strpos($url, '://') + 3);
            }

            /**
             * Stat the mirrored real path.
             *
             * @param   string  $path   Wrapper URL.
             * @param   int     $flags  Stat flags.
             *
             * @return  array<int|string, int>|false  Real stat, or false when absent.
             *
             * @since   2.0.0
             */
            public function url_stat(string $path, int $flags): array|false
            {
                unset($flags);

                return @stat(self::real($path));
            }

            /**
             * Open the mirrored real file for reading.
             *
             * @param   string   $path        Wrapper URL.
             * @param   string   $mode        Open mode.
             * @param   int      $options     Open options.
             * @param   ?string  $openedPath  Unused opened-path output.
             *
             * @return  bool  True when the real file opened.
             *
             * @since   2.0.0
             */
            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                unset($options, $openedPath);
                $handle = @fopen(self::real($path), $mode);
                if (!is_resource($handle)) {
                    return false;
                }
                $this->handle = $handle;

                return true;
            }

            /**
             * Read from the mirrored real file.
             *
             * @param   int  $count  Maximum bytes to read.
             *
             * @return  string|false  Bytes read, or false on failure.
             *
             * @since   2.0.0
             */
            public function stream_read(int $count): string|false
            {
                return fread($this->handle, $count);
            }

            /**
             * Report whether the mirrored real file is exhausted.
             *
             * @return  bool  True at end of file.
             *
             * @since   2.0.0
             */
            public function stream_eof(): bool
            {
                return feof($this->handle);
            }

            /**
             * Stat the open mirrored real file.
             *
             * @return  array<int|string, int>|false  Handle stat.
             *
             * @since   2.0.0
             */
            public function stream_stat(): array|false
            {
                return fstat($this->handle);
            }

            /**
             * Close the mirrored real file.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function stream_close(): void
            {
                fclose($this->handle);
                $this->handle = null;
            }

            /**
             * Refuse to enumerate any directory, however real it stats.
             *
             * @param   string  $path     Wrapper URL.
             * @param   int     $options  Open options.
             *
             * @return  bool  Always false.
             *
             * @since   2.0.0
             */
            public function dir_opendir(string $path, int $options): bool
            {
                unset($path, $options);

                return false;
            }
        })::class;
        // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    }
}
