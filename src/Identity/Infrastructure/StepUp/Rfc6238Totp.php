<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\StepUp\TotpAlgorithm;

/**
 * RFC 6238 TOTP calculator with a bounded clock-drift window and constant-time code comparison.
 *
 * @since  2.0.0
 */
final readonly class Rfc6238Totp implements TotpAlgorithm
{
    /**
     * RFC 4648 alphabet used by authenticator provisioning.
     *
     * @var    string
     * @since  2.0.0
     */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Configure the interoperable TOTP profile.
     *
     * @param   int     $period     Seconds per time-step, from 15 through 120.
     * @param   int     $digits     Decimal code width, 6 or 8.
     * @param   int     $window     Number of adjacent steps accepted on each side, at most 2.
     * @param   string  $algorithm  RFC HMAC algorithm: sha1, sha256, or sha512.
     *
     * @throws  InvalidArgumentException  When the profile falls outside those bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        private int $period = 30,
        private int $digits = 6,
        private int $window = 1,
        private string $algorithm = 'sha1',
    ) {
        if ($period < 15 || $period > 120 || !in_array($digits, [6, 8], true) || $window < 0 || $window > 2) {
            throw new InvalidArgumentException('The TOTP period, width, or drift window is invalid.');
        }
        if (!in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new InvalidArgumentException('The TOTP HMAC algorithm is unsupported.');
        }
    }

    /**
     * Encode raw bytes as unpadded RFC 4648 Base32.
     *
     * @param   string  $secret  Raw authenticator secret.
     *
     * @return  string  Uppercase unpadded Base32.
     *
     * @throws  InvalidArgumentException  When the raw secret is empty.
     *
     * @since   2.0.0
     */
    public function encodeSecret(string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('A TOTP secret cannot be empty.');
        }

        $encoded = '';
        $buffer = 0;
        $bits = 0;
        $bytes = unpack('C*', $secret);
        if ($bytes === false) {
            throw new InvalidArgumentException('A TOTP secret could not be encoded.');
        }
        foreach ($bytes as $byte) {
            if (!is_int($byte)) {
                throw new InvalidArgumentException('A TOTP secret contains an invalid byte.');
            }
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $encoded .= self::BASE32[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    /**
     * Match a candidate against the current and configured adjacent counters.
     *
     * @param   string             $secret  Raw enrolled secret.
     * @param   string             $code    Fixed-width decimal candidate.
     * @param   DateTimeImmutable  $now     Trusted current instant.
     *
     * @return  ?int  Matching non-negative counter, or null without revealing why it failed.
     *
     * @since   2.0.0
     */
    public function verify(string $secret, string $code, DateTimeImmutable $now): ?int
    {
        if ($secret === '' || preg_match('/^[0-9]{' . $this->digits . '}$/D', $code) !== 1) {
            return null;
        }

        $current = intdiv($now->getTimestamp(), $this->period);
        for ($offset = -$this->window; $offset <= $this->window; ++$offset) {
            $counter = $current + $offset;
            if ($counter >= 0 && hash_equals($this->code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    /**
     * Calculate one HOTP value at a counter as required by RFC 6238.
     *
     * @param   string  $secret   Raw HMAC key.
     * @param   int     $counter  Non-negative 64-bit moving factor.
     *
     * @return  string  Zero-padded decimal code.
     *
     * @since   2.0.0
     */
    private function code(string $secret, int $counter): string
    {
        $high = intdiv($counter, 4_294_967_296);
        $low = $counter % 4_294_967_296;
        $digest = hash_hmac($this->algorithm, pack('N2', $high, $low), $secret, true);
        $offset = ord($digest[strlen($digest) - 1]) & 15;
        $binary = ((ord($digest[$offset]) & 127) << 24)
            | ((ord($digest[$offset + 1]) & 255) << 16)
            | ((ord($digest[$offset + 2]) & 255) << 8)
            | (ord($digest[$offset + 3]) & 255);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }
}
