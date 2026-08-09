<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

use InvalidArgumentException;

/**
 * Decides whether an incoming `Host` header names a site this installation may answer as.
 *
 * Kumwe builds absolute URLs from the request host — password-reset links, canonical tags, redirects —
 * so an unchecked `Host` header lets a client poison caches and mint links that point at an attacker.
 * `TrustedHostMiddleware` consults this matcher before any handler runs and answers 400 when it says
 * no. Patterns come from operator configuration and are validated once at construction, so a typo
 * fails at boot rather than turning into a silent deny on every request.
 *
 * @since  2.0.0
 */
final readonly class TrustedHostMatcher
{
    /**
     * Compile the operator's trusted host list, refusing an unusable pattern up front.
     *
     * @param   non-empty-list<string>  $patterns  Exact host names, or one leading `*.` wildcard for sub-domains.
     *
     * @throws  InvalidArgumentException  When a pattern is empty, misuses `*`, or is not a host.
     *
     * @since   2.0.0
     */
    public function __construct(private array $patterns)
    {
        foreach ($patterns as $pattern) {
            $this->assertValidPattern($pattern);
        }
    }

    /**
     * Report whether a raw `Host` header value names a trusted site.
     *
     * The header is normalised before comparison — lowercased, port and trailing dot stripped, IPv6
     * brackets removed — and exact patterns are compared with `hash_equals` so the check is not a
     * timing oracle. A `*.` pattern matches sub-domains only, never the bare domain beneath it.
     *
     * @param   string  $hostHeader  Raw `Host` header value as received, port and all.
     *
     * @return  bool  True when the host matches a configured pattern.
     *
     * @throws  InvalidArgumentException  When the header is not a syntactically valid host.
     *
     * @since   2.0.0
     */
    public function matches(string $hostHeader): bool
    {
        $host = $this->normalizeHost($hostHeader);

        foreach ($this->patterns as $pattern) {
            $normalizedPattern = strtolower(rtrim($pattern, '.'));

            if ($normalizedPattern[0] !== '*') {
                if (hash_equals($normalizedPattern, $host)) {
                    return true;
                }

                continue;
            }

            $suffix = substr($normalizedPattern, 1);

            if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a raw `Host` header to the bare, lowercase host used for comparison.
     *
     * Rejecting rather than trimming a header that carries a path, a backslash, or userinfo keeps a
     * smuggled authority from being compared as if it were a plain host name.
     *
     * @param   string  $hostHeader  Raw header value, optionally bracketed and port-suffixed.
     *
     * @return  string  Lowercase host name or IP literal, without brackets, port, or trailing dot.
     *
     * @throws  InvalidArgumentException  When the value carries a path, userinfo, bad port, or bad host.
     *
     * @since   2.0.0
     */
    private function normalizeHost(string $hostHeader): string
    {
        $host = trim(strtolower($hostHeader));

        if ($host === '' || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')) {
            throw new InvalidArgumentException('The Host header is malformed.');
        }

        if ($host[0] === '[') {
            $closingBracket = strpos($host, ']');

            if ($closingBracket === false) {
                throw new InvalidArgumentException('The IPv6 Host header is malformed.');
            }

            $address = substr($host, 1, $closingBracket - 1);
            $remainder = substr($host, $closingBracket + 1);

            if (
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
                || $remainder !== '' && (!$this->validPortSuffix($remainder))
            ) {
                throw new InvalidArgumentException('The IPv6 Host header is malformed.');
            }

            return $address;
        }

        if (substr_count($host, ':') > 1) {
            throw new InvalidArgumentException('IPv6 Host headers must use brackets.');
        }

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);

            if (!$this->validPort($port)) {
                throw new InvalidArgumentException('The Host header port is malformed.');
            }
        }

        $host = rtrim($host, '.');

        if (
            $host === ''
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('The Host header is malformed.');
        }

        return $host;
    }

    /**
     * Check that the text trailing an IPv6 literal is a well-formed `:port` suffix.
     *
     * @param   string  $suffix  Everything after the closing bracket of the IPv6 literal.
     *
     * @return  bool  True when the suffix is a colon followed by a valid port.
     *
     * @since   2.0.0
     */
    private function validPortSuffix(string $suffix): bool
    {
        return str_starts_with($suffix, ':') && $this->validPort(substr($suffix, 1));
    }

    /**
     * Check that a port is a decimal number in range, with no leading zero.
     *
     * @param   string  $port  Port text taken from the header.
     *
     * @return  bool  True when the port parses and falls between 1 and 65535.
     *
     * @since   2.0.0
     */
    private function validPort(string $port): bool
    {
        return preg_match('/^[1-9][0-9]{0,4}$/D', $port) === 1 && (int) $port <= 65_535;
    }

    /**
     * Refuse a configured pattern that could never be matched safely.
     *
     * A wildcard is only meaningful as a single leading `*.`, so anything else is an operator mistake
     * rather than a broader rule. Checking at construction means the mistake surfaces at boot instead
     * of rejecting every request once traffic arrives.
     *
     * @param   string  $pattern  Configured pattern, before normalisation.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the pattern is empty, misuses `*`, or is not a host.
     *
     * @since   2.0.0
     */
    private function assertValidPattern(string $pattern): void
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));

        if ($pattern === '' || substr_count($pattern, '*') > 1 || str_contains(substr($pattern, 1), '*')) {
            throw new InvalidArgumentException(sprintf('Trusted host pattern "%s" is invalid.', $pattern));
        }

        $candidate = str_starts_with($pattern, '*.') ? substr($pattern, 2) : $pattern;

        if (
            filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($candidate, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException(sprintf('Trusted host pattern "%s" is invalid.', $pattern));
        }
    }
}
