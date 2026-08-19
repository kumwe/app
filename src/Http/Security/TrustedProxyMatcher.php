<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Security;

use InvalidArgumentException;

/**
 * Matches peer addresses against an operator-controlled IPv4/IPv6 trust boundary.
 *
 * Forwarding headers are client-controlled unless the hop that delivered them is one the operator put
 * in front of the application, so this matcher gates every decision taken from that metadata:
 * `TrustedProxyMiddleware` asks it about the connecting peer, and `ForwardedHeaderParser` asks it again
 * for each recorded hop it walks back through. Ranges are parsed and pre-masked once at construction,
 * so a per-request check is a fixed-length byte comparison that never re-reads configuration.
 *
 * @since  2.0.0
 */
final readonly class TrustedProxyMatcher
{
    /**
     * Configured ranges reduced to their masked network address, ready for per-request comparison.
     *
     * @var    list<array{network: string, prefix: int, bits: int}>
     * @since  2.0.0
     */
    private array $networks;

    /**
     * Parse and mask the configured trust boundary, refusing an unusable range at boot.
     *
     * @param   list<string>  $ranges  IPv4/IPv6 addresses or CIDR ranges; an empty list trusts nobody.
     *
     * @throws  InvalidArgumentException  When a range is empty, is not an IP, or has a bad prefix.
     *
     * @since   2.0.0
     */
    public function __construct(array $ranges)
    {
        $networks = [];

        foreach ($ranges as $range) {
            $networks[] = $this->parseRange($range);
        }

        $this->networks = $networks;
    }

    /**
     * Report whether an address falls inside the configured trust boundary.
     *
     * An unparseable address is answered as untrusted rather than raising, so a hostile peer value can
     * only ever narrow what the caller is willing to believe. IPv4 and IPv6 never cross-match, because
     * a range is only consulted when its address width equals the candidate's.
     *
     * @param   string  $address  Peer address in presentation form, IPv4 or IPv6, without a port.
     *
     * @return  bool  True when the address lies inside one of the configured ranges.
     *
     * @since   2.0.0
     */
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
     * Turn one configured range into the masked form the request path compares against.
     *
     * A bare address without a slash is treated as a host route: its prefix covers every bit, so it
     * matches only itself.
     *
     * @param   string  $range  Configured entry: a bare address, or an address with a `/prefix`.
     *
     * @return  array{network: string, prefix: int, bits: int}  Masked network, prefix, address width.
     *
     * @throws  InvalidArgumentException  When the entry is empty, is not an IP, or has a bad prefix.
     *
     * @since   2.0.0
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

    /**
     * Decide whether a packed address shares a network's leading bits.
     *
     * The comparison runs through `hash_equals` so that how far a candidate got before diverging is
     * not observable in timing.
     *
     * @param   string  $address  Packed binary address, as returned by `inet_pton`.
     * @param   string  $network  Packed binary network address, already masked.
     * @param   int     $prefix   Number of leading bits that must agree.
     *
     * @return  bool  True when the address lies inside the network.
     *
     * @since   2.0.0
     */
    private function prefixMatches(string $address, string $network, int $prefix): bool
    {
        return hash_equals($network, $this->mask($address, $prefix));
    }

    /**
     * Clear every bit of a packed address beyond the prefix length.
     *
     * @param   string  $address  Packed binary address, as returned by `inet_pton`.
     * @param   int     $prefix   Number of leading bits to keep.
     *
     * @return  string  Packed address of the same length with its host bits zeroed.
     *
     * @since   2.0.0
     */
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
