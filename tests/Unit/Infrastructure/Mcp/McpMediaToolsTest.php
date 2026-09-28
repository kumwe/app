<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryMediaStorage;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Content\Application\ContentNotFound;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Proves the MCP media reads answer the REST shapes and the media tools refuse before any write is fenced.
 *
 * Uploads and deletes run through the database-backed mutation guard and are proven end to end by the media
 * machine-equivalence integration test; here the reads run against the real `MediaService` over an in-memory
 * store, and every refusal a handler raises before the guard is pinned to its exception, which the retained
 * MCP vocabulary maps to a stable code.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
final class McpMediaToolsTest extends TestCase
{
    /**
     * List and get answer the REST page and asset shapes; a missing asset is `resource.not_found`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadsAnswerTheRestShapes(): void
    {
        $storage = new InMemoryMediaStorage();
        $source = tempnam(sys_get_temp_dir(), 'kumwe-mcp-media-test-');
        self::assertIsString($source);
        file_put_contents($source, "%PDF-1.7 parity");
        $asset = $storage->store(
            SiteContext::default(),
            $source,
            'Brochure.pdf',
            1_000_000,
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
        );
        unlink($source);
        $handlers = $this->handlers(['content.read'], $storage);

        $page = $handlers->listMedia('brochure', 'document', 1, 10);

        self::assertSame(
            ['items' => [$asset->toArray()], 'total' => 1, 'page' => 1, 'pages' => 1, 'per_page' => 10],
            $page,
        );
        self::assertSame(0, $handlers->listMedia(kind: 'image')['total']);
        self::assertSame($asset->toArray(), $handlers->getMedia($asset->id));
        $this->expectException(ContentNotFound::class);
        $handlers->getMedia('absent');
    }

    /**
     * Bad input, missing capabilities and an uncomposed library are refused before the mutation guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrecedeTheMutationGuard(): void
    {
        $all = ['content.read', 'content.update', 'content.delete'];
        $refusals = [
            [InvalidArgumentException::class, fn () => $this->handlers($all)->listMedia(kind: 'video')],
            [
                InvalidArgumentException::class,
                fn () => $this->handlers($all)->uploadMedia('media-upload-00000001', 'a.png', '***'),
            ],
            [InsufficientCapability::class, fn () => $this->handlers(['content.read'])->deleteMedia(
                'media-delete-00000001',
                'absent',
            )],
            [InsufficientCapability::class, fn () => $this->handlers(['content.read'])->uploadMedia(
                'media-upload-00000002',
                'a.png',
                base64_encode('x'),
            )],
            [InsufficientCapability::class, fn () => $this->handlers([])->listMedia()],
            [InvalidArgumentException::class, fn () => $this->handlers($all, null)->getMedia('absent')],
        ];

        foreach ($refusals as $index => [$expected, $call]) {
            try {
                $call();
                self::fail(sprintf('Refusal %d was not raised.', $index));
            } catch (Throwable $refusal) {
                self::assertInstanceOf($expected, $refusal, (string) $index);
            }
        }
    }

    /**
     * Build handlers bound to a bearer context with the given capabilities.
     *
     * @param   list<string>               $capabilities  Capabilities the MCP credential holds.
     * @param   InMemoryMediaStorage|null  $storage       Library store, or null for a server without media.
     *
     * @return  KumweMcpHandlers  Bound handlers.
     *
     * @since   2.0.0
     */
    private function handlers(
        array $capabilities,
        ?InMemoryMediaStorage $storage = new InMemoryMediaStorage(),
    ): KumweMcpHandlers {
        $media = $storage === null ? null : new MediaService(
            $storage,
            AuthorizationContext::gateway(),
            new RecordingAuditRecorder(),
            new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
            1_000_000,
        );

        return McpHandlersFixture::create(new McpCapabilityCatalog(), media: $media)
            ->forContext(AuthorizationContext::human($capabilities));
    }
}
