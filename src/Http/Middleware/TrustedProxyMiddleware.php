<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Kumwe\CMS\Http\Security\ForwardedHeaderParser;
use Kumwe\CMS\Http\Security\ForwardedRequest;
use Kumwe\CMS\Http\Security\TrustedProxyMatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class TrustedProxyMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE_CLIENT_ADDRESS = 'kumwe.client_address';

    private const FORWARDED_HEADERS = [
        'Forwarded',
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
    ];

    private ForwardedHeaderParser $parser;

    public function __construct(private TrustedProxyMatcher $trustedProxies)
    {
        $this->parser = new ForwardedHeaderParser($trustedProxies);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $forwarded = null;

        $request = $request->withAttribute(
            self::ATTRIBUTE_CLIENT_ADDRESS,
            is_string($peer) && filter_var($peer, FILTER_VALIDATE_IP) !== false ? $peer : 'unknown',
        );

        if (is_string($peer) && $this->trustedProxies->matches($peer)) {
            $forwarded = $this->parser->parse($request, $peer);
        }

        foreach (self::FORWARDED_HEADERS as $header) {
            $request = $request->withoutHeader($header);
        }

        if ($forwarded !== null) {
            $request = $this->normalize($request, $forwarded);
        }

        return $handler->handle($request);
    }

    private function normalize(ServerRequestInterface $request, ForwardedRequest $forwarded): ServerRequestInterface
    {
        $uri = $request->getUri();
        $schemeChanged = $forwarded->scheme !== null && $forwarded->scheme !== $uri->getScheme();

        if ($forwarded->scheme !== null) {
            $uri = $uri->withScheme($forwarded->scheme);
        }

        if ($forwarded->host !== null) {
            $uri = $uri->withHost($forwarded->host);
        }

        if ($forwarded->authoritySupplied) {
            $uri = $uri->withPort($forwarded->port);
        } elseif ($schemeChanged && $uri->getPort() !== null) {
            $uri = $uri->withPort(null);
        }

        $host = $this->hostHeader($uri->getHost(), $uri->getPort());

        return $request
            ->withUri($uri, false)
            ->withHeader('Host', $host)
            ->withAttribute(self::ATTRIBUTE_CLIENT_ADDRESS, $forwarded->clientAddress);
    }

    private function hostHeader(string $host, ?int $port): string
    {
        $host = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return $port === null ? $host : $host . ':' . $port;
    }
}
