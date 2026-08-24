<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use ArrayObject;
use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioMediaAssetProjector;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaGrantToken;
use Kumwe\App\Studio\Application\Media\StudioMediaService;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;
use Kumwe\App\Studio\Application\Media\StudioMediaUploadRepository;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpResponse;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Infrastructure\Media\FilesystemStudioMediaStagingStorage;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Typed mutable asset map shared by the media-storage callbacks in the lifecycle fixture.
 *
 * @extends  ArrayObject<string, MediaAsset>
 *
 * @since  2.0.0
 */
final class StudioMediaAssetMap extends ArrayObject
{
}

/**
 * Exercises every Studio media operation as one scoped lifecycle over the real private staging adapter.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaService::class)]
#[CoversClass(StudioMediaAssetProjector::class)]
#[CoversClass(StudioMediaCursorCodec::class)]
#[CoversClass(StudioExternalMediaFetcher::class)]
#[CoversClass(FilesystemStudioMediaStagingStorage::class)]
#[CoversClass(MediaService::class)]
final class StudioMediaLifecycleTest extends TestCase
{
    /**
     * Authorize, transfer, complete, read, page, abort and import under one live Studio scope.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCompleteSevenOperationLifecycleIsScopedAuditedAndPortable(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-studio-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $assets = new StudioMediaAssetMap();
        $assetPaths = [];
        $storage = self::createStub(MediaStorage::class);
        $storage->method('all')->willReturnCallback(
            static function (SiteContext $site) use ($assets): array {
                return array_reverse(array_values($assets->getArrayCopy()));
            },
        );
        $storage->method('find')->willReturnCallback(
            static function (SiteContext $site, string $id) use ($assets): ?MediaAsset {
                return $assets->offsetExists($id) ? $assets->offsetGet($id) : null;
            },
        );
        $storage->method('store')->willReturnCallback(
            static function (
                SiteContext $site,
                string $source,
                string $originalName,
                int $maximumBytes,
                DateTimeImmutable $createdAt,
            ) use (
                $assets,
                &$assetPaths,
                $root,
            ): MediaAsset {
                $number = count($assets) + 1;
                $path = $root . '/asset-' . $number . '.jpg';
                self::assertTrue(copy($source, $path));
                $id = sprintf('00000000-0000-4000-8000-%012d', $number);
                $asset = new MediaAsset(
                    $id,
                    $originalName,
                    'image/jpeg',
                    (int) filesize($path),
                    $createdAt,
                    $path,
                );
                $assets->offsetSet($id, $asset);
                $assetPaths[] = $path;

                return $asset;
            },
        );
        $storage->method('delete')->willReturnCallback(
            static function (SiteContext $site, string $id) use ($assets): void {
                $assets->offsetUnset($id);
            },
        );
        $events = [];
        $audit = self::createStub(AuditRecorder::class);
        $audit->method('record')->willReturnCallback(
            static function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $transactions->method('afterCommit')->willReturnCallback(
            static function (callable $operation): void {
                $operation();
            },
        );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-24T12:00:00+00:00'));
        $sessions = [];
        $uploads = self::createStub(StudioMediaUploadRepository::class);
        $uploads->method('add')->willReturnCallback(
            static function (StudioMediaUploadSession $session) use (&$sessions): void {
                $sessions[$session->id] = $session;
            },
        );
        $uploads->method('find')->willReturnCallback(
            static function (
                string $id,
                string $actorId,
                string $siteId,
                string $contextKey,
                string $generation,
            ) use (&$sessions): ?StudioMediaUploadSession {
                $session = $sessions[$id] ?? null;
                if (
                    !$session instanceof StudioMediaUploadSession
                    || $session->actorId !== $actorId
                    || $session->siteId !== $siteId
                    || $session->contextKey !== $contextKey
                    || $session->generation !== $generation
                ) {
                    return null;
                }

                return $session;
            },
        );
        $uploads->method('save')->willReturnCallback(
            static function (StudioMediaUploadSession $session, int $expected) use (&$sessions): bool {
                $current = $sessions[$session->id] ?? null;
                if (!$current instanceof StudioMediaUploadSession || $current->version !== $expected) {
                    return false;
                }
                $sessions[$session->id] = $session;

                return true;
            },
        );
        $signatures = self::createStub(StudioMediaSignatureVerifier::class);
        $signatures->method('verify')->willReturn('image/jpeg');
        $resolver = self::createStub(StudioExternalAddressResolver::class);
        $resolver->method('resolve')->willReturn(['93.184.216.34']);
        $transport = self::createStub(StudioPinnedHttpTransport::class);
        $transport->method('get')->willReturnCallback(
            static function () use ($root): StudioPinnedHttpResponse {
                $path = $root . '/external-' . bin2hex(random_bytes(6));
                self::assertNotFalse(file_put_contents($path, "\xff\xd8\xff\xe0external"));

                return new StudioPinnedHttpResponse(
                    200,
                    ['content-encoding' => 'identity', 'content-type' => 'image/jpeg'],
                    $path,
                    (int) filesize($path),
                );
            },
        );
        $service = new StudioMediaService(
            new MediaService(
                $storage,
                AuthorizationContext::gateway(),
                $audit,
                $clock,
                10_000,
                $transactions,
            ),
            $uploads,
            new FilesystemStudioMediaStagingStorage($root . '/staging'),
            new StudioMediaUploadPolicy(['image/jpeg'], 10_000, false),
            $signatures,
            new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                $resolver,
                $transport,
                $signatures,
                ['image/jpeg'],
                10_000,
            ),
            new StudioMediaAssetProjector(),
            new StudioMediaCursorCodec(str_repeat('c', 32)),
            new StudioMediaGrantToken(str_repeat('g', 32)),
            $transactions,
            $audit,
            $clock,
            'https://app.example.invalid',
        );
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read', 'content.update'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-media-lifecycle',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-media-lifecycle',
        );
        $snapshot = new StudioHostSessionSnapshot(
            new StudioHostSession(
                'contexts/media-lifecycle',
                $context->actorId(),
                $context->site()->identifier(),
                null,
                null,
                'administrator',
                str_repeat('a', 64),
                StudioSessionMode::Content,
                StudioResourceKind::Content,
                'content-lifecycle',
                'generation-lifecycle',
            ),
            ['studio.permission/read', 'studio.permission/upload-media'],
            'generation-lifecycle',
            true,
            false,
            false,
        );

        try {
            $bytes = "\xff\xd8\xff\xe0direct";
            $grant = $service->authorizeUpload($context, $snapshot, new StudioMediaUploadRequest(
                'direct.jpg',
                'image/jpeg',
                strlen($bytes),
                'studio.media/content',
            ));
            self::assertIsString($grant->uploadId);
            self::assertInstanceOf(stdClass::class, $grant->headers);
            self::assertIsString($grant->headers->{'X-Studio-Upload-Token'});
            $service->receive(
                $context,
                $grant->uploadId,
                'contexts/media-lifecycle',
                'generation-lifecycle',
                $grant->headers->{'X-Studio-Upload-Token'},
                'image/jpeg',
                (new StreamFactory())->createStream($bytes),
            );
            $completed = $service->completeUpload($context, $snapshot, $grant->uploadId);
            self::assertIsString($completed->id);
            self::assertSame('ready', $completed->state);
            self::assertInstanceOf(stdClass::class, $service->get($context, $completed->id));
            self::assertSame($completed->id, $service->uploadStatus($context, $completed->id)->id);

            $cancelGrant = $service->authorizeUpload($context, $snapshot, new StudioMediaUploadRequest(
                'cancelled.jpg',
                'image/jpeg',
                strlen($bytes),
                'studio.media/content',
            ));
            self::assertIsString($cancelGrant->uploadId);
            $service->abortUpload($context, $snapshot, $cancelGrant->uploadId);
            $imported = $service->importExternal($context, 'https://cdn.example/photo.jpg');
            self::assertIsString($imported->id);

            $firstPage = $service->list($context, (object) ['limit' => 1]);
            self::assertIsArray($firstPage->assets);
            self::assertCount(1, $firstPage->assets);
            self::assertIsString($firstPage->nextCursor);
            $secondPage = $service->list($context, (object) [
                'cursor' => $firstPage->nextCursor,
                'limit' => 1,
            ]);
            self::assertIsArray($secondPage->assets);
            self::assertCount(1, $secondPage->assets);

            $actions = array_map(static fn (AuditEvent $event): string => $event->action(), $events);
            foreach (
                [
                'studio.media.authorize',
                'studio.media.transfer',
                'studio.media.complete',
                'studio.media.abort',
                'studio.media.import',
                ] as $action
            ) {
                self::assertContains($action, $actions);
            }
        } finally {
            $paths = glob($root . '/*');
            foreach (is_array($paths) ? array_reverse($paths) : [] as $path) {
                if (is_dir($path)) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            foreach ($assetPaths as $path) {
                @unlink($path);
            }
            @rmdir($root . '/staging');
            @rmdir($root);
        }
    }
}
