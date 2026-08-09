<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\StepUp;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\StepUp\StepUpRecoveryCodeHasher;

/**
 * Keyed BLAKE2b digests for high-entropy recovery codes.
 *
 * @since  2.0.0
 */
final readonly class SodiumStepUpRecoveryCodeHasher implements StepUpRecoveryCodeHasher
{
    /**
     * Bind recovery-code digests to a dedicated installation key.
     *
     * @param   string  $key  Raw 16-to-64-byte key from the installation secret provider.
     *
     * @throws  InvalidArgumentException  When the key length is unsupported by libsodium.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (
            strlen($key) < SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN
            || strlen($key) > SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX
        ) {
            throw new InvalidArgumentException('The recovery-code digest key length is invalid.');
        }
    }

    /**
     * Produce a fixed 256-bit keyed digest.
     *
     * @param   string  $normalizedCode  Lowercase hexadecimal code without separators.
     *
     * @return  string  Sixty-four lowercase hexadecimal characters.
     *
     * @throws  InvalidArgumentException  When the normalized code is not a 128-bit hexadecimal value.
     *
     * @since   2.0.0
     */
    public function digest(string $normalizedCode): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $normalizedCode) !== 1) {
            throw new InvalidArgumentException('A normalized recovery code is invalid.');
        }

        return bin2hex(sodium_crypto_generichash($normalizedCode, $this->key, 32));
    }
}
