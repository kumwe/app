<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Media;

use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Infrastructure\Media\NativeStudioExternalAddressResolver;
use Kumwe\App\Studio\Infrastructure\Media\SocketStudioPinnedHttpTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises the pinned socket parser without relying on an external network service.
 *
 * @since  2.0.0
 */
#[CoversClass(SocketStudioPinnedHttpTransport::class)]
#[CoversClass(NativeStudioExternalAddressResolver::class)]
#[CoversClass(StudioMediaPortRejected::class)]
final class SocketStudioPinnedHttpTransportTest extends TestCase
{
    /**
     * Literal resolution, bounded response parsing and connection failures remain deterministic and safe.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPinnedTransportParsesOnlyBoundedUnambiguousResponses(): void
    {
        self::assertSame(
            ['8.8.8.8'],
            (new NativeStudioExternalAddressResolver())->resolve('8.8.8.8'),
        );
        $headers = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'headers');
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        self::assertNotFalse(fwrite(
            $source,
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nContent-Type: image/jpeg\r\n\r\n",
        ));
        rewind($source);
        $parsed = $headers->invoke(null, $source, microtime(true) + 5);
        self::assertIsArray($parsed);
        self::assertSame(200, $parsed[0]);
        self::assertIsArray($parsed[1]);
        self::assertSame('4', $parsed[1]['content-length']);

        $body = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'body');
        $payload = fopen('php://temp', 'w+b');
        $target = fopen('php://temp', 'w+b');
        self::assertIsResource($payload);
        self::assertIsResource($target);
        self::assertNotFalse(fwrite($payload, 'test'));
        rewind($payload);
        self::assertSame(4, $body->invoke(
            null,
            $payload,
            $target,
            ['content-length' => '4'],
            4,
            microtime(true) + 5,
        ));
        rewind($target);
        self::assertSame('test', stream_get_contents($target));

        $chunked = fopen('php://temp', 'w+b');
        $decoded = fopen('php://temp', 'w+b');
        self::assertIsResource($chunked);
        self::assertIsResource($decoded);
        self::assertNotFalse(fwrite($chunked, "4\r\ntest\r\n0\r\n\r\n"));
        rewind($chunked);
        self::assertSame(4, $body->invoke(
            null,
            $chunked,
            $decoded,
            ['transfer-encoding' => 'chunked'],
            4,
            microtime(true) + 5,
        ));

        $ambiguous = fopen('php://temp', 'w+b');
        self::assertIsResource($ambiguous);
        self::assertNotFalse(fwrite(
            $ambiguous,
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nContent-Length: 4\r\n\r\n",
        ));
        rewind($ambiguous);
        try {
            $headers->invoke(null, $ambiguous, microtime(true) + 5);
            self::fail('Duplicate framing headers must be refused.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-response-refused', $failure->failureCode);
        }

        $transport = new SocketStudioPinnedHttpTransport(sys_get_temp_dir());
        try {
            $transport->get('https://example.invalid:1/file', '127.0.0.1', 16, 1);
            self::fail('An unavailable pinned endpoint must be reported safely.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-fetch-failed', $failure->failureCode);
            self::assertStringNotContainsString('127.0.0.1', $failure->getMessage());
        } finally {
            fclose($source);
            fclose($payload);
            fclose($target);
            fclose($chunked);
            fclose($decoded);
            fclose($ambiguous);
        }
    }
}
