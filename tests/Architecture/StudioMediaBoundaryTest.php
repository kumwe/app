<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Holds AP-5 to the App Media boundary, exact adapter wire shapes and hardened external-fetch posture.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioMediaBoundaryTest extends TestCase
{
    /**
     * The Studio application boundary reuses Media and imports no driver or BusinessRecord shortcut.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMediaApplicationBoundaryIsDriverFreeAndUsesTheExistingMediaModule(): void
    {
        $application = $this->source('src/Studio/Application/Media');

        self::assertStringContainsString('Kumwe\App\Media\Application\MediaService', $application);
        self::assertStringNotContainsString('Doctrine\\DBAL\\', $application);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $application);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessDefinition\\', $application);
    }

    /**
     * The public host binding recognizes all operations and exact nested wrapper names.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAllSevenMediaOperationsAndHttpWrappersAreExplicit(): void
    {
        $port = $this->contents('src/Studio/Application/Media/StudioMediaHostPort.php');
        foreach (
            [
                'abort-upload' => 'uploadId',
                'authorize-upload' => 'request',
                'complete-upload' => 'uploadId',
                'get' => 'assetId',
                'import-external' => 'url',
                'list' => 'query',
                'upload-status' => 'assetId',
            ] as $operation => $wrapper
        ) {
            self::assertStringContainsString('studio.operation/media.' . $operation, $port);
            self::assertStringContainsString("'" . $wrapper . "'", $port);
        }
    }

    /**
     * External imports classify lexically, pin DNS, revalidate redirects and verify bounded bytes.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testExternalFetchBoundaryCarriesEveryRequiredSecurityStage(): void
    {
        $fetcher = $this->contents('src/Studio/Application/Media/StudioExternalMediaFetcher.php');
        $transport = $this->contents('src/Studio/Infrastructure/Media/SocketStudioPinnedHttpTransport.php');

        self::assertStringContainsString('StudioExternalUrlPolicy', $fetcher);
        self::assertStringContainsString('permitsResolvedAddress', $fetcher);
        self::assertStringContainsString('redirect(', $fetcher);
        self::assertStringContainsString('StudioMediaSignatureVerifier', $fetcher);
        self::assertStringContainsString('pinnedAddress', $transport);
        self::assertStringContainsString("'peer_name'", $transport);
        self::assertStringContainsString('maximumBytes', $transport);
        self::assertStringNotContainsString('$errorMessage', $fetcher);
    }

    /**
     * Phase 7 can aggregate every Studio media security control without inventing evidence after release.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testP7CSecurityQualificationInventoryNamesEveryMediaControl(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/qualification/studio-media-security-evidence.json';
        $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertArrayHasKey('candidate', $document);
        self::assertNull($document['candidate']);
        $assessment = $document['assessment'] ?? null;
        self::assertIsArray($assessment);
        $security = $assessment['security'] ?? null;
        self::assertIsArray($security);
        self::assertSame('not_evaluated', $security['status'] ?? null);
        $aggregate = $document['central_aggregate'] ?? null;
        self::assertIsArray($aggregate);
        self::assertSame('pending', $aggregate['status'] ?? null);
        $verification = $document['verification'] ?? null;
        self::assertIsArray($verification);
        self::assertSame([
            'STUDIO-MEDIA-LEXICAL-SSRF',
            'STUDIO-MEDIA-DNS-PINNING',
            'STUDIO-MEDIA-REDIRECT-REVALIDATION',
            'STUDIO-MEDIA-RESPONSE-VERIFICATION',
        ], array_column($verification, 'id'));
        foreach ($verification as $entry) {
            self::assertIsArray($entry);
            self::assertSame('not_run', $entry['status'] ?? null);
            $workItems = $entry['work_item_ids'] ?? null;
            $findings = $entry['blocker_finding_ids'] ?? null;
            $artifacts = $entry['artifact_paths'] ?? null;
            self::assertIsArray($workItems);
            self::assertIsArray($findings);
            self::assertIsArray($artifacts);
            self::assertContains('P7-C', $workItems);
            self::assertContains('V2-STU-005', $findings);
            foreach ($artifacts as $artifact) {
                self::assertIsString($artifact);
                self::assertFileExists(dirname(__DIR__, 2) . '/' . $artifact);
            }
        }
    }

    /**
     * Every media lifecycle write uses the platform audit port while catalog operations remain reads.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMediaLifecycleMutationsUseTheTransactionalAuditBoundary(): void
    {
        $service = $this->contents('src/Studio/Application/Media/StudioMediaService.php');

        self::assertStringContainsString('AuditRecorder', $service);
        foreach (
            [
                'authorizeUpload' => ['authorizeWithinTransaction', 'studio.media.authorize'],
                'abortUpload' => ['abortWithinTransaction', 'studio.media.abort'],
                'completeUpload' => ['completeWithinTransaction', 'studio.media.complete'],
                'importExternal' => ['importWithinTransaction', 'studio.media.import'],
            ] as $entry => [$withinTransaction, $action]
        ) {
            self::assertStringContainsString(
                '$this->transactions->transactional(',
                self::method($service, $entry),
                $entry,
            );
            self::assertStringContainsString("'" . $action . "'", self::method($service, $withinTransaction));
        }
        self::assertStringContainsString("'studio.media.transfer'", self::method($service, 'receiveWithinTransaction'));
        self::assertStringContainsString('TransactionManager', $service);
        self::assertStringContainsString('context_digest', $service);
        self::assertStringContainsString('generation_digest', $service);
    }

    /**
     * Only the composition root connects media drivers, the central dispatcher and the binary route.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testContainerFactoryOwnsTheOnlyProductionCompositionAndTransferRoute(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertSame(1, substr_count(
            $container,
            "'/administrator/studio/media/uploads/{upload}'",
        ));
        self::assertStringContainsString('new DoctrineStudioMediaUploadRepository(', $container);
        self::assertStringContainsString('new SocketStudioPinnedHttpTransport(', $container);
        self::assertStringContainsString('new StudioMediaHostPort(', $container);
        self::assertStringContainsString('StudioMediaUploadMigration(', $container);
    }

    /**
     * Concatenate PHP sources beneath one repository-relative directory.
     *
     * @param   string  $path  Repository-relative directory.
     *
     * @return  string  Concatenated source.
     *
     * @since  2.0.0
     */
    private function source(string $path): string
    {
        $directory = dirname(__DIR__, 2) . '/' . $path;
        $source = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $source .= $contents;
            }
        }

        return $source;
    }

    /**
     * Read one required repository-relative source file.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  Exact source bytes.
     *
     * @since  2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Slice one documented method from a source file for narrow structural assertions.
     *
     * @param   string  $source  Complete class source.
     * @param   string  $name    Exact method name to locate.
     *
     * @return  string  Source from the signature to the following member block.
     *
     * @since  2.0.0
     */
    private static function method(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($start);
        $end = strpos($source, "\n    /**", $start);

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
    }
}
