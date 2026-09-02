<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves App refuses an exact Producer release that implements a different Studio coordinate.
 *
 * Producer and App publish their evidence independently, so equality is tested rather than assumed from
 * the Composer pin. The accepted fixture agrees on release bytes, protocol, corpus, packages, per-package
 * npm tarball digests, and profiles; each negative case changes one coordinate and requires a hard failure
 * with no compatibility translation.
 *
 * @since   2.0.0
 */
#[CoversNothing]
final class ProducerStudioAlignmentGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Temporary JSON files created by a test.
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
     * Remove temporary evidence.
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
     * Independently published records for the same coordinated release pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAlignedEvidencePasses(): void
    {
        $result = $this->executeDocuments($this->documents());

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('release 0.1.0-rc.1, protocol 0.1.0-draft.2', $result['output']);
    }

    /**
     * Producer cannot name a different coordinated Studio release.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerReleaseMismatchFails(): void
    {
        $documents = $this->documents();
        $documents['producer_pin']['source']['release'] = '0.1.0-beta.2';
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Producer source release differs', $result['output']);
    }

    /**
     * Producer cannot speak a different Studio wire protocol under the same package pin.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerProtocolMismatchFails(): void
    {
        $documents = $this->documents();
        $documents['producer_pin']['protocol_version'] = '0.1.0-draft.1';
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Producer protocol version differs', $result['output']);
    }

    /**
     * Producer cannot claim conformance against a different Studio corpus.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerCorpusMismatchFails(): void
    {
        $documents = $this->documents();
        $documents['producer_pin']['corpus_manifest_digest'] = 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Producer corpus manifest digest differs', $result['output']);
    }

    /**
     * Producer cannot omit one of the profiles App says the coordinated release supports.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerClaimedProfileMismatchFails(): void
    {
        $documents = $this->documents();
        array_pop($documents['producer_pin']['claimed_profiles']);
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Producer claimed profiles differs', $result['output']);
    }

    /**
     * Producer cannot record different npm tarball bytes for a package App pins at the same version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerTarballDigestMismatchFails(): void
    {
        $documents = $this->documents();
        $documents['producer_pin']['package_provenance'][0]['sha256'] = str_repeat('d2', 32);
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'Producer npm tarball SHA-256 for @kumwe/studio differs',
            $result['output'],
        );
    }

    /**
     * Producer cannot omit the provenance record for a package App pins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingProducerPackageProvenanceFails(): void
    {
        $documents = $this->documents();
        $documents['producer_pin']['package_provenance'] = [];
        $result = $this->executeDocuments($documents);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'Producer package provenance is missing for @kumwe/studio.',
            $result['output'],
        );
    }

    /**
     * Build a minimal but complete pair of independently published Studio evidence records.
     *
     * @return  array{app_pin: array<string, mixed>, app_release: array<string, mixed>,
     *          producer_pin: array<string, mixed>, producer_release: array<string, mixed>}
     *
     * @since   2.0.0
     */
    private function documents(): array
    {
        $release = [
            'contractVersion' => '0.1-draft',
            'kind' => 'studio-release',
            'release' => '0.1.0-rc.1',
            'packages' => ['@kumwe/studio' => '0.1.0-rc.1'],
            'protocolVersion' => '0.1.0-draft.2',
            'corpusManifestDigest' => 'sha256-4/ChS3pCA32CoZ+BjvL2tj2RGOFJNdqXwuqO8gWDxTs=',
            'claimedProfiles' => ['studio.profile/engine-core', 'studio.profile/host-baseline'],
        ];
        $releaseBytes = $this->encode($release);
        $releaseHash = hash('sha256', $releaseBytes);
        $releaseRecord = [
            'release' => '0.1.0-rc.1',
            'file' => 'studio-release.json',
            'sha256' => $releaseHash,
        ];
        $tarball = str_repeat('c1', 32);

        return [
            'app_pin' => [
                'release_record' => $releaseRecord,
                'pinned' => ['@kumwe/studio' => ['version' => '0.1.0-rc.1', 'npm_tarball_sha256' => $tarball]],
            ],
            'app_release' => $release,
            'producer_pin' => [
                'pin' => 'kumwe-producer-studio-contract',
                'source' => [
                    'repository' => 'https://github.com/kumwe/studio',
                    'kind' => 'provenance-backed-npm-release',
                    'release' => '0.1.0-rc.1',
                    'commit' => str_repeat('ab', 20),
                ],
                'release_record' => $releaseRecord,
                'protocol_version' => '0.1.0-draft.2',
                'corpus_manifest_digest' => $release['corpusManifestDigest'],
                'claimed_profiles' => $release['claimedProfiles'],
                'packages' => $release['packages'],
                'package_provenance' => [
                    ['name' => '@kumwe/studio', 'version' => '0.1.0-rc.1', 'sha256' => $tarball],
                ],
                'files' => [['file' => 'studio-release.json', 'sha256' => $releaseHash]],
            ],
            'producer_release' => $release,
        ];
    }

    /**
     * Write the four evidence records and execute the standalone alignment verifier.
     *
     * @param   array{app_pin: array<string, mixed>, app_release: array<string, mixed>,
     *          producer_pin: array<string, mixed>, producer_release: array<string, mixed>}  $documents  Evidence.
     *
     * @return  array{status: int, output: string}  Exit status and output.
     *
     * @since   2.0.0
     */
    private function executeDocuments(array $documents): array
    {
        $arguments = [];
        foreach (
            [
                'app-pin' => 'app_pin',
                'app-release' => 'app_release',
                'producer-pin' => 'producer_pin',
                'producer-release' => 'producer_release',
            ] as $argument => $key
        ) {
            $arguments[] = '--' . $argument . '=' . $this->write($documents[$key]);
        }

        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-producer-studio-alignment.php'),
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
     * Write one evidence record with deterministic bytes.
     *
     * @param   array<string, mixed>  $document  Evidence document.
     *
     * @return  string  Temporary path.
     *
     * @since   2.0.0
     */
    private function write(array $document): string
    {
        $path = sys_get_temp_dir() . '/kumwe-studio-alignment-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($path, $this->encode($document)));
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * Encode an evidence document exactly as the temporary publisher writes it.
     *
     * @param   array<string, mixed>  $document  Evidence document.
     *
     * @return  string  Deterministic JSON bytes.
     *
     * @since   2.0.0
     */
    private function encode(array $document): string
    {
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);

        return $encoded . "\n";
    }
}
