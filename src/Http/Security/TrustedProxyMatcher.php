<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

use InvalidArgumentException;

/**
 * Matches peer addresses against an operator-controlled IPv4/IPv6 trust boundary.
 */
final readonly class TrustedProxyMatcher
{
    /**
     * @var list<array{network: string, prefix: int, bits: int}>
     */
    private array $networks;

    /**
     * @param list<string> $ranges IPv4/IPv6 addresses or CIDR ranges
     */
    public function __construct(array $ranges)
    {
        $networks = [];

        foreach ($ranges as $range) {
            $networks[] = $this->parseRange($range);
        }

        $this->networks = $networks;
    }

    public function matches(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        $bits = strlen($packed) * 8;

        foreach ($this->networks as $network) {
            if ($network['bits'] !== $bits) {
                continue;
            }

            if ($this->prefixMatches($packed, $network['network'], $network['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{network: string, prefix: int, bits: int}
     */
    private function parseRange(string $range): array
    {
        $range = trim($range);

        if ($range === '') {
            throw new InvalidArgumentException('Trusted proxy ranges must not be empty.');
        }

        if (substr_count($range, '/') > 1) {
            throw new InvalidArgumentException(sprintf('Trusted proxy range "%s" is invalid.', $range));
        }

        $parts = explode('/', $range, 2);
        $address = $parts[0];
        $prefix = $parts[1] ?? null;
        $packed = @inet_pton($address);

        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('Trusted proxy range "%s" has an invalid address.', $range));
        }

        $bits = strlen($packed) * 8;

        if ($prefix === null) {
            $prefixLength = $bits;
        } elseif ($prefix === '' || preg_match('/^(?:0|[1-9][0-9]*)$/D', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Trusted proxy range "%s" has an invalid prefix.', $range));
        } else {
            $prefixLength = (int) $prefix;
        }

        if ($prefixLength > $bits) {
            throw new InvalidArgumentException(sprintf('Trusted proxy range "%s" has an invalid prefix.', $range));
        }

        return [
            'network' => $this->mask($packed, $prefixLength),
            'prefix' => $prefixLength,
            'bits' => $bits,
        ];
    }

    private function prefixMatches(string $address, string $network, int $prefix): bool
    {
        return hash_equals($network, $this->mask($address, $prefix));
    }

    private function mask(string $address, int $prefix): string
    {
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $masked = substr($address, 0, $wholeBytes);

        if ($remainingBits > 0) {
            $maskedByte = ord($address[$wholeBytes]) & (0xFF << (8 - $remainingBits));
            $masked .= chr(max(0, min(255, $maskedByte)));
            ++$wholeBytes;
        }

        return $masked . str_repeat("\0", strlen($address) - $wholeBytes);
    }
}
