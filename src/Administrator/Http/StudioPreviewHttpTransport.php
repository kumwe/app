<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Strict HTTP extraction of preview evidence kept outside canonical operation arguments.
 *
 * @since  2.0.0
 */
final class StudioPreviewHttpTransport
{
    /**
     * Decode custom same-origin host-port headers.
     *
     * @param   ServerRequestInterface  $request  Authenticated preview port request.
     *
     * @return  StudioPreviewTransport  Strict browser transport evidence.
     *
     * @throws  InvalidArgumentException  When a header is absent, repeated or malformed.
     *
     * @since   2.0.0
     */
    public static function fromPort(ServerRequestInterface $request): StudioPreviewTransport
    {
        return new StudioPreviewTransport(
            self::singleHeader($request, 'Origin'),
            self::singleHeader($request, 'X-Kumwe-Studio-Preview-Channel'),
            self::singleHeader($request, 'X-Kumwe-Studio-Preview-Source'),
            self::sequence(self::singleHeader($request, 'X-Kumwe-Studio-Preview-Sequence')),
        );
    }

    /**
     * Decode an authenticated iframe navigation whose custom evidence travels in its query.
     *
     * Browser navigations do not permit custom headers. The query identities are non-bearer values:
     * the administrator cookie, live host-session scope and single-use grant remain the authority.
     *
     * @param   ServerRequestInterface  $request  Authenticated same-origin iframe navigation.
     *
     * @return  StudioPreviewTransport  Strict browser transport evidence.
     *
     * @throws  InvalidArgumentException  When referrer/origin or query evidence is malformed.
     *
     * @since   2.0.0
     */
    public static function fromDocument(ServerRequestInterface $request): StudioPreviewTransport
    {
        $query = $request->getQueryParams();
        $channel = $query['channel'] ?? null;
        $source = $query['source'] ?? null;
        $sequence = $query['sequence'] ?? null;
        if (!is_string($channel) || !is_string($source) || !is_string($sequence)) {
            throw new InvalidArgumentException('The Studio preview document query is invalid.');
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            $referrer = self::singleHeader($request, 'Referer');
            $scheme = parse_url($referrer, PHP_URL_SCHEME);
            $host = parse_url($referrer, PHP_URL_HOST);
            $port = parse_url($referrer, PHP_URL_PORT);
            if (!is_string($scheme) || !is_string($host)) {
                throw new InvalidArgumentException('The Studio preview document referrer is invalid.');
            }
            $origin = strtolower($scheme) . '://' . strtolower($host) . (is_int($port) ? ':' . $port : '');
        }

        return new StudioPreviewTransport($origin, $channel, $source, self::sequence($sequence));
    }

    /**
     * Decode an authenticated same-origin preview subresource request.
     *
     * A stylesheet fetch can omit both Origin and Referer under the preview's no-referrer policy. The
     * trusted request URI therefore supplies the target origin; the live session, grant, channel and source
     * remain independently revalidated and this read consumes no protocol sequence.
     *
     * @param   ServerRequestInterface  $request  Authenticated same-origin preview asset request.
     *
     * @return  StudioPreviewTransport  Strict target-origin and query identity evidence.
     *
     * @throws  InvalidArgumentException  When URI or query evidence is malformed.
     *
     * @since   2.0.0
     */
    public static function fromStylesheet(ServerRequestInterface $request): StudioPreviewTransport
    {
        $query = $request->getQueryParams();
        $channel = $query['channel'] ?? null;
        $source = $query['source'] ?? null;
        if (!is_string($channel) || !is_string($source)) {
            throw new InvalidArgumentException('The Studio preview stylesheet query is invalid.');
        }
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($scheme === '' || $host === '') {
            throw new InvalidArgumentException('The Studio preview stylesheet origin is invalid.');
        }
        $origin = strtolower($scheme) . '://' . strtolower($host) . ($port === null ? '' : ':' . $port);

        return new StudioPreviewTransport($origin, $channel, $source, 0);
    }

    /**
     * Read exactly one non-empty header value.
     *
     * @param   ServerRequestInterface  $request  HTTP request carrying the evidence.
     * @param   string                  $name     Exact header name.
     *
     * @return  string  Sole header value.
     *
     * @throws  InvalidArgumentException  When absent, blank or repeated.
     *
     * @since   2.0.0
     */
    private static function singleHeader(ServerRequestInterface $request, string $name): string
    {
        $values = $request->getHeader($name);
        if (count($values) !== 1 || trim($values[0]) === '') {
            throw new InvalidArgumentException(sprintf('The %s header is invalid.', $name));
        }

        return trim($values[0]);
    }

    /**
     * Decode a canonical zero-based decimal sequence without coercion.
     *
     * @param   string  $value  Candidate decimal spelling.
     *
     * @return  int  Non-negative sequence.
     *
     * @throws  InvalidArgumentException  When the spelling is non-canonical or overflows.
     *
     * @since   2.0.0
     */
    private static function sequence(string $value): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            throw new InvalidArgumentException('The Studio preview sequence is invalid.');
        }
        $sequence = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($sequence)) {
            throw new InvalidArgumentException('The Studio preview sequence is invalid.');
        }

        return $sequence;
    }
}
