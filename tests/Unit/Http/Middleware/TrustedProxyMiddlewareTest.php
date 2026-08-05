<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Http\Middleware;

use Kumwe\CMS\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\CMS\Http\Security\ForwardedHeaderParser;
use Kumwe\CMS\Http\Security\ForwardedRequest;
use Kumwe\CMS\Http\Security\TrustedProxyMatcher;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(TrustedProxyMiddleware::class)]
#[CoversClass(ForwardedHeaderParser::class)]
#[CoversClass(ForwardedRequest::class)]
final class TrustedProxyMiddlewareTest extends TestCase
{
    public function testUntrustedPeerCannotSpoofForwardedRequestProperties(): void
    {
        $captured = $this->process(
            $this->request('198.51.100.10')
                ->withHeader('Forwarded', 'for=203.0.113.5;proto=https;host=attacker.test')
                ->withHeader('X-Forwarded-For', '203.0.113.6')
                ->withHeader('X-Forwarded-Host', 'attacker.test'),
            ['10.0.0.0/8'],
        );

        self::assertSame('198.51.100.10', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('http', $captured->getUri()->getScheme());
        self::assertSame('internal.test', $captured->getUri()->getHost());
        self::assertSame('', $captured->getHeaderLine('Forwarded'));
        self::assertSame('', $captured->getHeaderLine('X-Forwarded-For'));
        self::assertSame('', $captured->getHeaderLine('X-Forwarded-Host'));
    }

    public function testStandardizedHeaderNormalizesTheRequest(): void
    {
        $captured = $this->process(
            $this->request('10.20.0.10')->withHeader(
                'Forwarded',
                'for=203.0.113.5;proto=https;host="kumwe.test:8443"',
            ),
            ['10.20.0.10'],
        );

        self::assertSame('203.0.113.5', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('https', $captured->getUri()->getScheme());
        self::assertSame('kumwe.test', $captured->getUri()->getHost());
        self::assertSame(8443, $captured->getUri()->getPort());
        self::assertSame('kumwe.test:8443', $captured->getHeaderLine('Host'));
    }

    public function testStandardizedHeaderWalksACompletelyTrustedProxyChain(): void
    {
        $captured = $this->process(
            $this->request('10.0.0.30')->withHeader(
                'Forwarded',
                'for=198.51.100.9;proto=https;host=portal.kumwe.test, '
                . 'for=10.0.0.20;proto=http;host=proxy.internal',
            ),
            ['10.0.0.0/8'],
        );

        self::assertSame('198.51.100.9', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('https://portal.kumwe.test/path', (string) $captured->getUri());
    }

    public function testSpoofedPrefixBeforeFirstUntrustedHopIsNotConsumed(): void
    {
        $captured = $this->process(
            $this->request('10.0.0.30')->withHeader(
                'Forwarded',
                'for=192.0.2.66;proto=http;host=attacker.test, '
                . 'for=198.51.100.9;proto=https;host=portal.kumwe.test',
            ),
            ['10.0.0.0/8'],
        );

        self::assertSame('198.51.100.9', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('portal.kumwe.test', $captured->getUri()->getHost());
        self::assertSame('https', $captured->getUri()->getScheme());
    }

    public function testLegacyHeadersNormalizeAlignedProxyChainValues(): void
    {
        $captured = $this->process(
            $this->request('fd00::30')
                ->withHeader('X-Forwarded-For', '2001:db8::99, fd00::20')
                ->withHeader('X-Forwarded-Proto', 'https, http')
                ->withHeader('X-Forwarded-Host', '[2001:db8:1::10], proxy.internal')
                ->withHeader('X-Forwarded-Port', '443, 8080'),
            ['fd00::/8'],
        );

        self::assertSame('2001:db8::99', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('https', $captured->getUri()->getScheme());
        self::assertSame('2001:db8:1::10', $captured->getUri()->getHost());
        self::assertNull($captured->getUri()->getPort());
        self::assertSame('[2001:db8:1::10]', $captured->getHeaderLine('Host'));
    }

    public function testQuotedIpv6ForwardedNodeIsSupported(): void
    {
        $captured = $this->process(
            $this->request('2001:db8:1::10')->withHeader(
                'Forwarded',
                'for="[2001:db8:ffff::5]:54321";proto=https;host="[2001:db8:2::5]"',
            ),
            ['2001:db8:1::/64'],
        );

        self::assertSame('2001:db8:ffff::5', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('2001:db8:2::5', $captured->getUri()->getHost());
        self::assertSame('[2001:db8:2::5]', $captured->getHeaderLine('Host'));
    }

    public function testMalformedTrustedMetadataIsDiscardedAtomically(): void
    {
        $captured = $this->process(
            $this->request('10.20.0.10')
                ->withHeader('X-Forwarded-For', '203.0.113.5, 10.0.0.2')
                ->withHeader('X-Forwarded-Proto', 'https, http, https')
                ->withHeader('X-Forwarded-Host', 'attacker.test'),
            ['10.0.0.0/8'],
        );

        self::assertSame('10.20.0.10', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('http://internal.test/path', (string) $captured->getUri());
        self::assertSame('', $captured->getHeaderLine('X-Forwarded-For'));
    }

    public function testStandardizedHeaderTakesPrecedenceOverLegacyHeaders(): void
    {
        $captured = $this->process(
            $this->request('10.20.0.10')
                ->withHeader('Forwarded', 'for=203.0.113.5;proto=https;host=portal.kumwe.test')
                ->withHeader('X-Forwarded-For', '192.0.2.66')
                ->withHeader('X-Forwarded-Proto', 'http')
                ->withHeader('X-Forwarded-Host', 'attacker.test'),
            ['10.0.0.0/8'],
        );

        self::assertSame('203.0.113.5', $captured->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS));
        self::assertSame('https://portal.kumwe.test/path', (string) $captured->getUri());
    }

    public function testAncillaryLegacyHeadersWithoutForwardedForAreIgnored(): void
    {
        $captured = $this->process(
            $this->request('10.20.0.10')
                ->withHeader('X-Forwarded-Proto', 'https')
                ->withHeader('X-Forwarded-Host', 'attacker.test'),
            ['10.0.0.0/8'],
        );

        self::assertSame('http://internal.test/path', (string) $captured->getUri());
        self::assertSame('', $captured->getHeaderLine('X-Forwarded-Proto'));
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function process(ServerRequestInterface $request, array $trustedProxies): ServerRequestInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new TextResponse('', 204);
            }
        };

        (new TrustedProxyMiddleware(new TrustedProxyMatcher($trustedProxies)))->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $handler->request);

        return $handler->request;
    }

    private function request(string $peer): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://internal.test/path',
            ['REMOTE_ADDR' => $peer],
        );
    }
}
