<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use RuntimeException;

/**
 * RFC 6238 code calculation for drills that have to present a real second factor.
 *
 * Acceptance drills cannot type into an authenticator, so they compute what one would show. Two
 * entry points exist because a drill meets the secret in two shapes: as the Base32 text an
 * enrollment returns once, and as the raw bytes a restored credential yields after decryption.
 * Neither ever leaves this class, and both zero their working copy.
 *
 * @since  2.0.0
 */
final class TotpCodes
{
    /**
     * Alphabet RFC 4648 Base32 uses, in the order that gives each character its five-bit value.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Calculate the code for an unpadded Base32 enrollment secret and exact counter.
     *
     * @param   string  $base32   Unpadded enrollment secret returned once by the provider.
     * @param   int     $counter  Non-negative thirty-second moving factor.
     *
     * @return  string  Zero-padded six-digit code.
     *
     * @throws  RuntimeException  When the secret is not canonical Base32 or the counter is negative.
     *
     * @since   2.0.0
     */
    public static function fromBase32(string $base32, int $counter): string
    {
        $buffer = 0;
        $bits = 0;
        $secret = '';
        foreach (str_split(strtoupper($base32)) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new RuntimeException('The enrollment secret is not canonical Base32.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $secret .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return self::fromRawSecret($secret, $counter);
    }

    /**
     * Calculate the code for raw secret bytes and exact counter.
     *
     * @param   string  $secret   Raw shared secret, as decrypted from a stored credential.
     * @param   int     $counter  Non-negative thirty-second moving factor.
     *
     * @return  string  Zero-padded six-digit code.
     *
     * @throws  RuntimeException  When the secret is empty or the counter is negative.
     *
     * @since   2.0.0
     */
    public static function fromRawSecret(string $secret, int $counter): string
    {
        if ($secret === '' || $counter < 0) {
            throw new RuntimeException('The enrollment secret or counter is invalid.');
        }
        $working = $secret;
        $digest = hash_hmac(
            'sha1',
            pack('N2', intdiv($counter, 4_294_967_296), $counter % 4_294_967_296),
            $working,
            true,
        );
        sodium_memzero($working);
        $offset = ord($digest[strlen($digest) - 1]) & 15;
        $binary = ((ord($digest[$offset]) & 127) << 24)
            | ((ord($digest[$offset + 1]) & 255) << 16)
            | ((ord($digest[$offset + 2]) & 255) << 8)
            | (ord($digest[$offset + 3]) & 255);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Prevent instantiation; the type exists only to namespace the calculation.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
