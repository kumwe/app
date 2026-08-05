<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses proxy metadata only after the immediate network peer has been trusted.
 */
final readonly class ForwardedHeaderParser
{
    public function __construct(private TrustedProxyMatcher $trustedProxies)
    {
    }

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
     * @param non-empty-list<string> $addresses
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
     * @return array{host: string, port: ?int}
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

    private function scheme(string $value): string
    {
        $scheme = strtolower(trim($value));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Only HTTP and HTTPS forwarded schemes are supported.');
        }

        return $scheme;
    }

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
     * @return non-empty-list<string>
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

    private function nonEmpty(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Forwarded header lists must not contain empty values.');
        }

        return $value;
    }
}
