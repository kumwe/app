<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

use InvalidArgumentException;

final readonly class TrustedHostMatcher
{
    /**
     * @param non-empty-list<string> $patterns
     */
    public function __construct(private array $patterns)
    {
        foreach ($patterns as $pattern) {
            $this->assertValidPattern($pattern);
        }
    }

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

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('The IPv6 Host header is malformed.');
            }

            return $address;
        }

        $host = explode(':', $host, 2)[0];
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
