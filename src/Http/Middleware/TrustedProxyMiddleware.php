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

/**
 * Resolves the real client address and public URI when a request arrives through a trusted proxy.
 *
 * Forwarding headers are attacker-controlled unless the immediate peer is known, so they are read only
 * when `REMOTE_ADDR` falls inside the configured trust boundary. Whatever the outcome, every
 * forwarding header is then stripped from the request, which makes this middleware the single place
 * the decision is made: nothing further down the pipeline can re-derive a client address or a host
 * from headers already judged here. Downstream code reads `ATTRIBUTE_CLIENT_ADDRESS` and the
 * normalised URI instead, which is why this runs ahead of host checking, rate limiting and
 * authentication.
 *
 * @since  2.0.0
 */
final readonly class TrustedProxyMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute carrying the resolved client address for rate limiting and audit records.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ATTRIBUTE_CLIENT_ADDRESS = 'kumwe.client_address';

    /**
     * Headers removed from every request once forwarding metadata has been resolved or rejected.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const FORWARDED_HEADERS = [
        'Forwarded',
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
    ];

    /**
     * Parser that reads forwarding headers, sharing the trust boundary used to admit them.
     *
     * @var    ForwardedHeaderParser
     * @since  2.0.0
     */
    private ForwardedHeaderParser $parser;

    /**
     * Fix the trust boundary and build the parser that shares it.
     *
     * @param  TrustedProxyMatcher  $trustedProxies  Address ranges whose forwarding headers may be believed.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustedProxyMatcher $trustedProxies)
    {
        $this->parser = new ForwardedHeaderParser($trustedProxies);
    }

    /**
     * Resolve the client address, normalise the URI, and strip forwarding headers before delegating.
     *
     * The attribute is always populated: an untrusted, missing or unparseable peer yields the literal
     * `unknown` rather than a missing attribute, so downstream code never has to guess. Header
     * stripping happens on every path, including the untrusted one, so a spoofed forwarding header
     * cannot survive past this point.
     *
     * @param   ServerRequestInterface   $request  Request carrying `REMOTE_ADDR` and any forwarding headers.
     * @param   RequestHandlerInterface  $handler  Next handler, called with the normalised request.
     *
     * @return  ResponseInterface  Whatever the rest of the pipeline returns.
     *
     * @since   2.0.0
     */
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

    /**
     * Rewrite the URI and `Host` header to the public address the client actually reached.
     *
     * When the proxy changed the scheme but supplied no explicit authority, the inherited port is
     * dropped instead of kept, so an `https` URI does not end up advertising the internal `http` port.
     *
     * @param   ServerRequestInterface  $request    Request whose URI and host are being rewritten.
     * @param   ForwardedRequest        $forwarded  Client view parsed from the trusted proxy's headers.
     *
     * @return  ServerRequestInterface  The request with public URI, `Host` header and client address set.
     *
     * @since   2.0.0
     */
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

    /**
     * Render a host and port as a `Host` header value.
     *
     * @param   string  $host  Host name or IP address; an IPv6 literal is bracketed before use.
     * @param   ?int    $port  Port to append, or null when the scheme's default port applies.
     *
     * @return  string  The header value, with a port appended only when one was given.
     *
     * @since   2.0.0
     */
    private function hostHeader(string $host, ?int $port): string
    {
        $host = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return $port === null ? $host : $host . ':' . $port;
    }
}
