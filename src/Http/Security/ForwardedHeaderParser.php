<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Security;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses proxy metadata only after the immediate network peer has been trusted.
 *
 * `Forwarded` and the `X-Forwarded-*` family are ordinary request headers that any client can invent,
 * so they are evidence only when the hop that delivered them is one the operator trusts.
 * `TrustedProxyMiddleware` establishes that about the connecting peer and then hands the request here.
 * This parser walks the recorded chain from the application back towards the client, stops at the
 * first hop outside the trust boundary, and reports that hop as the client. Anything ambiguous,
 * malformed, or self-contradictory yields null as a unit, so the caller falls back to the raw
 * connection instead of acting on half-trusted metadata.
 *
 * @since  2.0.0
 */
final readonly class ForwardedHeaderParser
{
    /**
     * Bind the parser to the trust boundary it consults for every hop it walks.
     *
     * @param  TrustedProxyMatcher  $trustedProxies  Boundary deciding which recorded hops are proxies.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustedProxyMatcher $trustedProxies)
    {
    }

    /**
     * Recover the original client request from whichever forwarding headers are present.
     *
     * The standard `Forwarded` header wins outright when present and the `X-Forwarded-*` family is
     * read only in its absence, so one chain can never be described twice in disagreeing ways.
     * Parsing is all-or-nothing: a single malformed element discards the whole set.
     *
     * @param   ServerRequestInterface  $request  Request whose forwarding headers are to be read.
     * @param   string                  $peer     Address of the connecting hop, already trusted.
     *
     * @return  ?ForwardedRequest  The recovered client view, or null when nothing usable was sent.
     *
     * @since   2.0.0
     */
    public function parse(ServerRequestInterface $request, string $peer): ?ForwardedRequest
    {
        try {
            $forwarded = $request->getHeaderLine('Forwarded');

            if ($forwarded !== '') {
                return $this->standardized($forwarded, $peer);
            }

            $for = $request->getHeaderLine('X-Forwarded-For');

            if ($for !== '') {
                return $this->legacy($request, $for, $peer);
            }
        } catch (InvalidArgumentException) {
            // Ambiguous or malformed metadata is ignored as one atomic unit.
        }

        return null;
    }

    /**
     * Read the RFC 7239 `Forwarded` header into a client view.
     *
     * Every element must identify its own incoming peer and may not repeat a parameter name, so a
     * chain that cannot be read unambiguously is refused rather than guessed at. `proto` and `host`
     * are taken from the element belonging to the selected hop, never from a nearer one.
     *
     * @param   string  $header  Raw `Forwarded` header value, elements separated by commas.
     * @param   string  $peer    Address of the connecting hop, already trusted.
     *
     * @return  ForwardedRequest  The client view built from the selected element.
     *
     * @throws  InvalidArgumentException  When the header is malformed, ambiguous, or incomplete.
     *
     * @since   2.0.0
     */
    private function standardized(string $header, string $peer): ForwardedRequest
    {
        $elements = [];

        foreach ($this->split($header, ',') as $rawElement) {
            $parameters = [];

            foreach ($this->split($rawElement, ';') as $rawParameter) {
                $separator = strpos($rawParameter, '=');

                if ($separator === false) {
                    throw new InvalidArgumentException('Forwarded parameters must contain an equals sign.');
                }

                $name = strtolower(trim(substr($rawParameter, 0, $separator)));

                if (preg_match("/^[!#$%&'*+.^_`|~0-9a-z-]+$/D", $name) !== 1 || isset($parameters[$name])) {
                    throw new InvalidArgumentException('Forwarded contains an invalid or duplicate parameter.');
                }

                $parameters[$name] = $this->value(substr($rawParameter, $separator + 1));
            }

            if (!isset($parameters['for'])) {
                throw new InvalidArgumentException('Every Forwarded element must identify its incoming peer.');
            }

            $elements[] = $parameters;
        }

        $addresses = array_map(fn (array $element): string => $this->address($element['for']), $elements);
        $selected = $this->clientIndex($addresses, $peer);
        $element = $elements[$selected];
        $scheme = isset($element['proto']) ? $this->scheme($element['proto']) : null;
        $authority = isset($element['host']) ? $this->authority($element['host']) : null;

        return new ForwardedRequest(
            $addresses[$selected],
            $scheme,
            $authority['host'] ?? null,
            $authority['port'] ?? null,
            $authority !== null,
        );
    }

    /**
     * Read the `X-Forwarded-*` family into a client view.
     *
     * These headers describe the chain in parallel lists rather than per element, so a companion
     * header must carry either one value for the whole chain or exactly one value per address; any
     * other length cannot be attributed to a hop and is refused. A port from `X-Forwarded-Port` must
     * also agree with a port embedded in `X-Forwarded-Host`.
     *
     * @param   ServerRequestInterface  $request  Request supplying the companion forwarding headers.
     * @param   string                  $for      Raw `X-Forwarded-For` value, addresses comma separated.
     * @param   string                  $peer     Address of the connecting hop, already trusted.
     *
     * @return  ForwardedRequest  The client view built from the selected position in the chain.
     *
     * @throws  InvalidArgumentException  When a value is malformed or the header lists disagree.
     *
     * @since   2.0.0
     */
    private function legacy(ServerRequestInterface $request, string $for, string $peer): ForwardedRequest
    {
        $addresses = array_map(
            fn (string $value): string => $this->address(trim($value)),
            $this->split($for, ','),
        );
        $selected = $this->clientIndex($addresses, $peer);
        $schemeValue = $this->legacyValue($request->getHeaderLine('X-Forwarded-Proto'), $selected, count($addresses));
        $hostValue = $this->legacyValue($request->getHeaderLine('X-Forwarded-Host'), $selected, count($addresses));
        $portValue = $this->legacyValue($request->getHeaderLine('X-Forwarded-Port'), $selected, count($addresses));
        $scheme = $schemeValue !== null ? $this->scheme($schemeValue) : null;
        $authority = $hostValue !== null ? $this->authority($hostValue) : null;
        $port = $portValue !== null ? $this->port($portValue) : ($authority['port'] ?? null);

        if ($portValue !== null && $authority !== null && $authority['port'] !== null && $authority['port'] !== $port) {
            throw new InvalidArgumentException('Forwarded host and port values disagree.');
        }

        return new ForwardedRequest(
            $addresses[$selected],
            $scheme,
            $authority['host'] ?? null,
            $port,
            $authority !== null || $portValue !== null,
        );
    }

    /**
     * Select the first untrusted address when walking from the application back towards the client.
     *
     * The walk starts at the connecting peer and steps inward one recorded hop at a time, stopping as
     * soon as it reaches an address outside the trust boundary. A chain whose hops are all trusted
     * therefore resolves to its outermost entry, the address closest to the real client.
     *
     * @param   non-empty-list<string>  $addresses  Recorded hops in chain order, client-most first.
     * @param   string                  $peer       Address of the connecting hop, already trusted.
     *
     * @return  int  Index into `$addresses` of the hop to be treated as the client.
     *
     * @since   2.0.0
     */
    private function clientIndex(array $addresses, string $peer): int
    {
        $current = $peer;
        $selected = count($addresses) - 1;

        for ($index = count($addresses) - 1; $index >= 0; --$index) {
            if (!$this->trustedProxies->matches($current)) {
                break;
            }

            $selected = $index;
            $current = $addresses[$index];
        }

        return $selected;
    }

    /**
     * Pick the companion header value that belongs to the selected hop.
     *
     * A single value is read as describing the whole chain; otherwise the list must line up one to
     * one with the recorded addresses, because a partial list cannot be attributed to a hop safely.
     *
     * @param   string  $header        Raw companion header value, or an empty string when absent.
     * @param   int     $selected      Index of the hop chosen as the client.
     * @param   int     $addressCount  Number of addresses recorded in the forwarding chain.
     *
     * @return  ?string  The value covering the selected hop, or null when the header was absent.
     *
     * @throws  InvalidArgumentException  When the list length matches neither one nor the chain length.
     *
     * @since   2.0.0
     */
    private function legacyValue(string $header, int $selected, int $addressCount): ?string
    {
        if ($header === '') {
            return null;
        }

        $values = array_map('trim', $this->split($header, ','));

        if (count($values) === 1) {
            return $values[0];
        }

        if (count($values) !== $addressCount) {
            throw new InvalidArgumentException('Forwarded header lists have incompatible lengths.');
        }

        return $values[$selected];
    }

    /**
     * Split a forwarded host value into the host name and port the request URI will carry.
     *
     * A value carrying a path, backslash, or userinfo is refused outright rather than trimmed, so a
     * smuggled authority cannot reach the URI the application later builds its links from.
     *
     * @param   string  $value  Forwarded host, optionally bracketed for IPv6 and port-suffixed.
     *
     * @return  array{host: string, port: ?int}  Lowercase host under `host`; `port` is null when none.
     *
     * @throws  InvalidArgumentException  When the value is not a bare host, IP literal, or `host:port`.
     *
     * @since   2.0.0
     */
    private function authority(string $value): array
    {
        $value = trim($value);
        $port = null;

        if ($value === '' || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '@')) {
            throw new InvalidArgumentException('The forwarded host is malformed.');
        }

        if ($value[0] === '[') {
            $closing = strpos($value, ']');

            if ($closing === false) {
                throw new InvalidArgumentException('The forwarded IPv6 host is malformed.');
            }

            $host = substr($value, 1, $closing - 1);
            $remainder = substr($value, $closing + 1);

            if ($remainder !== '') {
                if (!str_starts_with($remainder, ':')) {
                    throw new InvalidArgumentException('The forwarded IPv6 host is malformed.');
                }

                $port = $this->port(substr($remainder, 1));
            }

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('The forwarded IPv6 host is malformed.');
            }
        } else {
            $host = $value;

            if (substr_count($value, ':') === 1) {
                [$host, $rawPort] = explode(':', $value, 2);
                $port = $this->port($rawPort);
            }

            $host = strtolower(rtrim($host, '.'));

            if (
                $host === ''
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            ) {
                throw new InvalidArgumentException('The forwarded host is malformed.');
            }
        }

        return ['host' => strtolower($host), 'port' => $port];
    }

    /**
     * Reduce one recorded hop to the bare IP address that identifies it.
     *
     * RFC 7239 obfuscated identifiers and the literal `unknown` are refused rather than skipped: a hop
     * that will not name itself cannot be weighed against the trust boundary, and silently dropping it
     * would let a client shorten the chain at will.
     *
     * @param   string  $value  Recorded hop, optionally bracketed for IPv6 and port-suffixed.
     *
     * @return  string  The hop's IP address in presentation form, without brackets or port.
     *
     * @throws  InvalidArgumentException  When the hop is obfuscated, unknown, or not an IP address.
     *
     * @since   2.0.0
     */
    private function address(string $value): string
    {
        $value = trim($value);

        if ($value === '' || strcasecmp($value, 'unknown') === 0 || str_starts_with($value, '_')) {
            throw new InvalidArgumentException('Forwarded client addresses must be concrete IP addresses.');
        }

        if ($value[0] === '[') {
            $closing = strpos($value, ']');

            if ($closing === false) {
                throw new InvalidArgumentException('The forwarded client address is malformed.');
            }

            $address = substr($value, 1, $closing - 1);
            $remainder = substr($value, $closing + 1);

            if ($remainder !== '' && preg_match('/^:[0-9]+$/D', $remainder) !== 1) {
                throw new InvalidArgumentException('The forwarded client port is malformed.');
            }
        } elseif (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $address = $value;
        } elseif (substr_count($value, ':') === 1) {
            [$address, $rawPort] = explode(':', $value, 2);

            if ($this->port($rawPort) < 1) {
                throw new InvalidArgumentException('The forwarded client port is malformed.');
            }
        } else {
            throw new InvalidArgumentException('The forwarded client address is malformed.');
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('The forwarded client address is malformed.');
        }

        return $address;
    }

    /**
     * Normalise a forwarded protocol to the scheme the request URI will carry.
     *
     * @param   string  $value  Forwarded protocol token, in any letter case.
     *
     * @return  string  Either `http` or `https`, lowercase.
     *
     * @throws  InvalidArgumentException  When the token names anything other than HTTP or HTTPS.
     *
     * @since   2.0.0
     */
    private function scheme(string $value): string
    {
        $scheme = strtolower(trim($value));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Only HTTP and HTTPS forwarded schemes are supported.');
        }

        return $scheme;
    }

    /**
     * Convert a forwarded port to an integer, refusing anything outside the valid range.
     *
     * @param   string  $value  Forwarded port text.
     *
     * @return  int  Port number between 1 and 65535.
     *
     * @throws  InvalidArgumentException  When the text is not decimal or lies outside the range.
     *
     * @since   2.0.0
     */
    private function port(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^[1-9][0-9]{0,4}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The forwarded port is malformed.');
        }

        $port = (int) $value;

        if ($port > 65_535) {
            throw new InvalidArgumentException('The forwarded port is outside the valid range.');
        }

        return $port;
    }

    /**
     * Split a header without treating delimiters inside quoted strings as separators.
     *
     * Control characters are rejected here rather than downstream, so a smuggled byte cannot survive
     * into a value the request URI or `Host` header is later rebuilt from.
     *
     * @param   string  $value      Raw header value to split.
     * @param   string  $delimiter  Single character separating parts outside quoted strings.
     *
     * @return  non-empty-list<string>  The trimmed parts, quoting preserved, in header order.
     *
     * @throws  InvalidArgumentException  When a part is empty, a quote never closes, or a control byte appears.
     *
     * @since   2.0.0
     */
    private function split(string $value, string $delimiter): array
    {
        $parts = [];
        $part = '';
        $quoted = false;
        $escaped = false;

        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $character = $value[$index];

            if ($escaped) {
                $part .= $character;
                $escaped = false;
                continue;
            }

            if ($quoted && $character === '\\') {
                $part .= $character;
                $escaped = true;
                continue;
            }

            if ($character === '"') {
                $quoted = !$quoted;
                $part .= $character;
                continue;
            }

            if (!$quoted && $character === $delimiter) {
                $parts[] = $this->nonEmpty($part);
                $part = '';
                continue;
            }

            if (ord($character) < 0x20 || ord($character) === 0x7F) {
                throw new InvalidArgumentException('Forwarded headers contain invalid control characters.');
            }

            $part .= $character;
        }

        if ($quoted || $escaped) {
            throw new InvalidArgumentException('Forwarded headers contain an unterminated quoted string.');
        }

        $parts[] = $this->nonEmpty($part);

        return $parts;
    }

    /**
     * Decode one `Forwarded` parameter value, whether it arrived as a token or a quoted string.
     *
     * @param   string  $value  Raw text following the parameter's equals sign.
     *
     * @return  string  The decoded value, with quoting and backslash escapes removed.
     *
     * @throws  InvalidArgumentException  When the value is empty, not a valid token, or badly quoted.
     *
     * @since   2.0.0
     */
    private function value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Forwarded parameter values must not be empty.');
        }

        if ($value[0] !== '"') {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z:\[\]-]+$/D", $value) !== 1) {
                throw new InvalidArgumentException('Forwarded contains an invalid token value.');
            }

            return $value;
        }

        if (strlen($value) < 2 || !str_ends_with($value, '"')) {
            throw new InvalidArgumentException('Forwarded contains an invalid quoted value.');
        }

        $decoded = preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1));

        if (!is_string($decoded) || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            throw new InvalidArgumentException('Forwarded contains an invalid quoted value.');
        }

        return $decoded;
    }

    /**
     * Trim one part of a header list and refuse it when nothing is left.
     *
     * @param   string  $value  One part produced by splitting a header list.
     *
     * @return  string  The trimmed part, guaranteed to be non-empty.
     *
     * @throws  InvalidArgumentException  When the part is empty or holds only whitespace.
     *
     * @since   2.0.0
     */
    private function nonEmpty(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Forwarded header lists must not contain empty values.');
        }

        return $value;
    }
}
