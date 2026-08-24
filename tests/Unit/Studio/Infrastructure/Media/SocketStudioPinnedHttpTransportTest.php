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

        $resources = [];
        $stream = static function (string $content = '') use (&$resources) {
            $resource = fopen('php://temp', 'w+b');
            self::assertIsResource($resource);
            if ($content !== '') {
                self::assertNotFalse(fwrite($resource, $content));
                rewind($resource);
            }
            $resources[] = $resource;

            return $resource;
        };
        $refusal = static function (callable $operation, string $code, string $label): void {
            try {
                $operation();
                self::fail(sprintf('The %s transport input was accepted.', $label));
            } catch (StudioMediaPortRejected $failure) {
                self::assertSame($code, $failure->failureCode, $label);
            }
        };

        $refusal(
            static fn () => $headers->invoke(
                null,
                $stream("NOT-HTTP\r\n\r\n"),
                microtime(true) + 5,
            ),
            'studio.media/external-response-refused',
            'invalid status line',
        );
        $largeHeaders = "HTTP/1.1 200 OK\r\n";
        foreach (range(1, 5) as $index) {
            $largeHeaders .= 'X-Large-' . $index . ': ' . str_repeat('a', 8_000) . "\r\n";
        }
        $largeHeaders .= "\r\n";
        $refusal(
            static fn () => $headers->invoke(null, $stream($largeHeaders), microtime(true) + 5),
            'studio.media/external-header-limit',
            'oversized header block',
        );
        foreach (
            [
                'bare line feed' => "HTTP/1.1 200 OK\r\n\n",
                'obsolete folding' => "HTTP/1.1 200 OK\r\n folded\r\n\r\n",
                'missing separator' => "HTTP/1.1 200 OK\r\nNo-Separator\r\n\r\n",
                'invalid header name' => "HTTP/1.1 200 OK\r\nBad Name: value\r\n\r\n",
                'invalid header value' => "HTTP/1.1 200 OK\r\nX-Test: value\x01\r\n\r\n",
            ] as $label => $response
        ) {
            $refusal(
                static fn () => $headers->invoke(null, $stream($response), microtime(true) + 5),
                'studio.media/external-response-refused',
                $label,
            );
        }

        $refusal(
            static fn () => $body->invoke(
                null,
                $stream(),
                $stream(),
                ['transfer-encoding' => 'gzip'],
                16,
                microtime(true) + 5,
            ),
            'studio.media/external-response-refused',
            'unsupported transfer encoding',
        );
        $refusal(
            static fn () => $body->invoke(
                null,
                $stream(),
                $stream(),
                ['transfer-encoding' => 'chunked', 'content-length' => '0'],
                16,
                microtime(true) + 5,
            ),
            'studio.media/external-response-refused',
            'ambiguous body framing',
        );
        foreach (['01', '17'] as $length) {
            $refusal(
                static fn () => $body->invoke(
                    null,
                    $stream(),
                    $stream(),
                    ['content-length' => $length],
                    16,
                    microtime(true) + 5,
                ),
                'studio.media/external-size-limit',
                'invalid content length ' . $length,
            );
        }
        $refusal(
            static fn () => $body->invoke(
                null,
                $stream('test'),
                $stream(),
                [],
                3,
                microtime(true) + 5,
            ),
            'studio.media/external-size-limit',
            'EOF-delimited body over quota',
        );
        $refusal(
            static fn () => $body->invoke(
                null,
                $stream('test'),
                $stream(),
                ['content-length' => '5'],
                8,
                microtime(true) + 5,
            ),
            'studio.media/external-response-refused',
            'truncated fixed-length body',
        );

        $chunkParser = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'chunked');
        foreach (
            [
                'invalid chunk size' => ["z\r\n", 16, 'studio.media/external-response-refused'],
                'chunk over quota' => ["5\r\nhello\r\n0\r\n\r\n", 4, 'studio.media/external-size-limit'],
                'chunk trailer' => ["0\r\nTrailer: value\r\n", 16, 'studio.media/external-response-refused'],
                'truncated chunk' => ["4\r\nte", 16, 'studio.media/external-fetch-failed'],
                'invalid chunk terminator' => ["4\r\ntest\n", 16, 'studio.media/external-response-refused'],
            ] as $label => [$encoded, $limit, $code]
        ) {
            $refusal(
                static fn () => $chunkParser->invoke(
                    null,
                    $stream($encoded),
                    $stream(),
                    $limit,
                    microtime(true) + 5,
                ),
                $code,
                $label,
            );
        }

        $line = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'line');
        $refusal(
            static fn () => $line->invoke(null, $stream("line\n"), 0, microtime(true) + 5),
            'studio.media/external-response-refused',
            'zero line limit',
        );
        $refusal(
            static fn () => $line->invoke(null, $stream('unterminated'), 32, microtime(true) + 5),
            'studio.media/external-response-refused',
            'unterminated line',
        );
        $before = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'before');
        $refusal(
            static fn () => $before->invoke(null, microtime(true) - 1),
            'studio.media/external-timeout',
            'expired deadline',
        );
        $readOnly = fopen('php://memory', 'rb');
        self::assertIsResource($readOnly);
        $resources[] = $readOnly;
        $append = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'append');
        $refusal(
            static fn () => $append->invoke(null, $readOnly, 'data'),
            'studio.media/external-fetch-failed',
            'incomplete private-file write',
        );
        $send = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'send');
        $refusal(
            static fn () => $send->invoke(null, $readOnly, "GET / HTTP/1.1\r\n\r\n", microtime(true) + 5),
            'studio.media/external-fetch-failed',
            'incomplete socket write',
        );

        $targetMethod = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'target');
        self::assertSame('/', $targetMethod->invoke(null, []));
        self::assertSame('/asset?id=7', $targetMethod->invoke(null, ['path' => '/asset', 'query' => 'id=7']));

        $blockedParent = tempnam(sys_get_temp_dir(), 'kumwe-studio-http-');
        self::assertIsString($blockedParent);
        $blockedTransport = new SocketStudioPinnedHttpTransport($blockedParent . '/downloads');
        $ensureRoot = new ReflectionMethod(SocketStudioPinnedHttpTransport::class, 'ensureRoot');
        $refusal(
            static fn () => $ensureRoot->invoke($blockedTransport),
            'studio.media/external-fetch-failed',
            'uncreatable private root',
        );

        $transport = new SocketStudioPinnedHttpTransport(sys_get_temp_dir());
        try {
            $refusal(
                static fn () => $transport->get('not-a-url', '127.0.0.1', 16, 1),
                'studio.media/external-url-refused',
                'URL without an authority',
            );
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
            foreach ($resources as $resource) {
                fclose($resource);
            }
            unlink($blockedParent);
        }
    }
}
