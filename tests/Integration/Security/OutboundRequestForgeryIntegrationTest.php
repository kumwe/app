<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Security;

use Kumwe\App\Extension\Infrastructure\Trust\StreamRevocationFeedSource;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Infrastructure\Media\NativeStudioExternalAddressResolver;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that no outbound fetcher can be pointed at the host's own network.
 *
 * A real listener is opened on the loopback interface and every alternate spelling of an internal address
 * a URL parser, resolver or socket might accept is handed to the production-composed Studio external media
 * importer: loopback in decimal, hexadecimal, octal and short forms, IPv4-mapped IPv6, the unspecified
 * address, link-local cloud metadata, special-use names, credentials smuggled before the host, and public
 * names whose DNS answers point back at loopback, which are refused after resolution or, where the runner
 * has no resolver, because they do not resolve. Every candidate must be refused with a category that names
 * no address, and the listener must never see a connection — the proof that the refusal happened before the
 * socket, not after it. The revocation feed transport, the other outbound fetcher, refuses every non-TLS
 * scheme the same way before connecting.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioExternalMediaFetcher::class)]
#[CoversClass(StudioExternalUrlPolicy::class)]
#[CoversClass(NativeStudioExternalAddressResolver::class)]
#[CoversClass(StreamRevocationFeedSource::class)]
final class OutboundRequestForgeryIntegrationTest extends TestCase
{
    /**
     * Every internal-address spelling is refused before a connection reaches the loopback listener.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoInternalAddressSpellingReachesTheLoopbackListener(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $fetcher = $container->get(StudioExternalMediaFetcher::class);
        self::assertInstanceOf(StudioExternalMediaFetcher::class, $fetcher);
        [$listener, $port] = $this->listen();

        try {
            $candidates = [
                'http://127.0.0.1:%d/image.png',
                'https://127.0.0.1:%d/image.png',
                'https://localhost:%d/image.png',
                'https://LOCALHOST.:%d/image.png',
                'https://2130706433:%d/image.png',
                'https://0x7f000001:%d/image.png',
                'https://0177.0.0.1:%d/image.png',
                'https://127.1:%d/image.png',
                'https://0.0.0.0:%d/image.png',
                'https://[::1]:%d/image.png',
                'https://[::ffff:127.0.0.1]:%d/image.png',
                'https://[::ffff:7f00:1]:%d/image.png',
                'https://[0:0:0:0:0:0:0:1]:%d/image.png',
                'https://user:password@127.0.0.1:%d/image.png',
                'https://images.example.com@127.0.0.1:%d/image.png',
                'https://127.0.0.1%%2f@images.example.com:%d/image.png',
                'https://service.localhost:%d/image.png',
                'https://intranet.internal:%d/image.png',
                'https://169.254.169.254:%d/latest/meta-data/',
                'https://[fe80::1]:%d/image.png',
                'https://[fd00::1]:%d/image.png',
                'https://10.0.0.1:%d/image.png',
                'https://192.168.1.1:%d/image.png',
                'https://100.64.0.1:%d/image.png',
                'https://127.0.0.1.nip.io:%d/image.png',
                'https://localtest.me:%d/image.png',
                'ftp://127.0.0.1:%d/image.png',
                'file:///etc/passwd?%d',
                '//127.0.0.1:%d/image.png',
            ];
            foreach ($candidates as $pattern) {
                $candidate = sprintf($pattern, $port);
                try {
                    $fetcher->fetch($candidate);
                    self::fail('The candidate was fetched: ' . $candidate);
                } catch (StudioMediaPortRejected $refusal) {
                    self::assertContains($refusal->category, ['validation-failed', 'unavailable'], $candidate);
                    self::assertStringNotContainsString((string) $port, $refusal->getMessage(), $candidate);
                    self::assertStringNotContainsString('127.0.0.1', $refusal->getMessage(), $candidate);
                }
                self::assertFalse($this->accepted($listener), 'No connection may reach the host: ' . $candidate);
            }
        } finally {
            fclose($listener);
        }
    }

    /**
     * The revocation feed transport refuses every non-TLS origin without opening a connection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRevocationFeedTransportRefusesEveryNonTlsOriginBeforeConnecting(): void
    {
        [$listener, $port] = $this->listen();
        $source = new StreamRevocationFeedSource();

        try {
            foreach (
                [
                    'http://127.0.0.1:%d/feed.json',
                    'ftp://127.0.0.1:%d/feed.json',
                    'php://filter/resource=http://127.0.0.1:%d/feed.json',
                    'file:///etc/passwd#%d',
                    'data://text/plain;base64,e30=#%d',
                    'relative/feed-%d.json',
                    'HTTPS://127.0.0.1:%d/feed.json',
                ] as $pattern
            ) {
                $origin = sprintf($pattern, $port);
                try {
                    $source->fetch($origin);
                    self::fail('The origin was fetched: ' . $origin);
                } catch (RuntimeException $refusal) {
                    self::assertStringContainsString('https:// URL or an absolute path', $refusal->getMessage());
                }
                self::assertFalse($this->accepted($listener), $origin);
            }
        } finally {
            fclose($listener);
        }
    }

    /**
     * Open a non-blocking loopback listener on an ephemeral port.
     *
     * @return  array{resource, int}  Listening socket and its port.
     *
     * @throws  RuntimeException  When no listener can be opened.
     *
     * @since   2.0.0
     */
    private function listen(): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if (!is_resource($listener)) {
            throw new RuntimeException('The loopback listener could not be opened.');
        }
        stream_set_blocking($listener, false);
        $name = stream_socket_get_name($listener, false);
        $port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;
        if ($port < 1) {
            throw new RuntimeException('The loopback listener has no port.');
        }

        return [$listener, $port];
    }

    /**
     * Report whether a connection has reached the listener, accepting and closing it if so.
     *
     * @param   resource  $listener  Listening socket.
     *
     * @return  bool  Whether any connection was pending.
     *
     * @since   2.0.0
     */
    private function accepted($listener): bool
    {
        $connection = @stream_socket_accept($listener, 0);
        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return false;
    }
}
