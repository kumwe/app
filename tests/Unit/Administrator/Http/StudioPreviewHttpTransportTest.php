<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the HTTP-only transport evidence without widening the canonical PreviewPort wrappers.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewHttpTransport::class)]
final class StudioPreviewHttpTransportTest extends TestCase
{
    /**
     * Port calls read exact single-valued headers and canonical decimal sequence.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPortHeadersAreDecodedWithoutEnteringTheOperationArgument(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/studio/ports/preview/render')
            ->withHeader('Origin', 'https://kumwe.test')
            ->withHeader('X-Kumwe-Studio-Preview-Channel', 'channels/preview-1')
            ->withHeader('X-Kumwe-Studio-Preview-Source', 'sources/preview-1')
            ->withHeader('X-Kumwe-Studio-Preview-Sequence', '0');

        $transport = StudioPreviewHttpTransport::fromPort($request);

        self::assertSame('https://kumwe.test', $transport->origin);
        self::assertSame('channels/preview-1', $transport->channelId);
        self::assertSame('sources/preview-1', $transport->sourceId);
        self::assertSame(0, $transport->sequence);
    }

    /**
     * An iframe navigation derives its same origin from Referer and reads only non-bearer query identities.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testDocumentNavigationDerivesOriginAndRejectsNonCanonicalSequence(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test/administrator/studio/preview/contexts/a/renders/1'
                . '?channel=channels%2Fpreview-1&source=sources%2Fpreview-1&sequence=1',
        )->withHeader('Referer', 'https://kumwe.test/administrator/studio')->withQueryParams([
            'channel' => 'channels/preview-1',
            'source' => 'sources/preview-1',
            'sequence' => '1',
        ]);

        $transport = StudioPreviewHttpTransport::fromDocument($request);

        self::assertSame('https://kumwe.test', $transport->origin);
        self::assertSame(1, $transport->sequence);

        $this->expectException(InvalidArgumentException::class);
        StudioPreviewHttpTransport::fromDocument($request->withQueryParams([
            'channel' => 'channels/preview-1',
            'source' => 'sources/preview-1',
            'sequence' => '01',
        ]));
    }
}
