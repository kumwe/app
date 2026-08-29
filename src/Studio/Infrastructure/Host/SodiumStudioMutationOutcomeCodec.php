<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Host;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioMutationOutcomeCodec;
use Kumwe\App\Studio\Application\Host\StudioMutationOutcomeRejected;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\HostResult;
use SodiumException;

/**
 * XChaCha20-Poly1305 protection for exact logical Producer mutation outcomes.
 *
 * @since  2.0.0
 */
final readonly class SodiumStudioMutationOutcomeCodec implements StudioMutationOutcomeCodec
{
    /**
     * Storage format prefix; older raw result bytes are intentionally unsupported.
     *
     * @since  2.0.0
     */
    private const string VERSION = 'v1.';

    /**
     * Bound a stored envelope before attempting Base64 decoding or authenticated decryption.
     *
     * @since  2.0.0
     */
    private const int MAXIMUM_ENVELOPE_BYTES = 2097152;

    /**
     * Bind the codec to a dedicated 256-bit installation key.
     *
     * @param   string  $key  Raw key bytes derived under the Studio mutation-replay purpose.
     *
     * @throws  InvalidArgumentException  When the key is not exactly 32 bytes.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The Studio mutation outcome key must contain exactly 32 bytes.');
        }
    }

    /**
     * Seal canonical outcome bytes with a fresh nonce and complete replay-coordinate binding.
     *
     * @param   HostResult|HostError  $outcome       Canonical redacted success or committed refusal.
     * @param   string                $scopeDigest   App-namespaced lowercase SHA-256 replay scope.
     * @param   string                $intentDigest  Producer's canonical SRI SHA-256 intent digest.
     *
     * @return  string  Version-one unpadded Base64url authenticated envelope.
     *
     * @since   2.0.0
     */
    public function protect(
        HostResult|HostError $outcome,
        string $scopeDigest,
        string $intentDigest,
    ): string {
        self::assertCoordinates($scopeDigest, $intentDigest);
        $plaintext = $outcome instanceof HostResult
            ? 'R' . self::resultBytes($outcome)
            : 'E' . $outcome->toCanonicalJson();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($scopeDigest, $intentDigest),
            $nonce,
            $this->key,
        );

        return self::VERSION . self::base64UrlEncode($nonce . $ciphertext);
    }

    /**
     * Authenticate, decrypt and re-prove the exact canonical Producer outcome.
     *
     * @param   string  $protectedOutcome  Versioned authenticated storage envelope.
     * @param   string  $scopeDigest       Expected App-namespaced lowercase SHA-256 replay scope.
     * @param   string  $intentDigest      Expected Producer SRI SHA-256 intent digest.
     *
     * @return  HostResult|HostError  Exact canonical success or committed refusal.
     *
     * @throws  StudioMutationOutcomeRejected  When any byte, binding, tag, discriminator or canonical value fails.
     *
     * @since   2.0.0
     */
    public function recover(
        string $protectedOutcome,
        string $scopeDigest,
        string $intentDigest,
    ): HostResult|HostError {
        try {
            self::assertCoordinates($scopeDigest, $intentDigest);
        } catch (InvalidArgumentException) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome binding is invalid.');
        }
        if (
            !str_starts_with($protectedOutcome, self::VERSION)
            || strlen($protectedOutcome) > self::MAXIMUM_ENVELOPE_BYTES
        ) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome envelope is invalid.');
        }
        $decoded = self::base64UrlDecode(substr($protectedOutcome, strlen(self::VERSION)));
        $minimum = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 2;
        if ($decoded === null || strlen($decoded) < $minimum) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome envelope is invalid.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $sealed,
                self::associatedData($scopeDigest, $intentDigest),
                $nonce,
                $this->key,
            );
        } catch (SodiumException) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome envelope is invalid.');
        }
        if (!is_string($plaintext) || strlen($plaintext) < 2) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome envelope is invalid.');
        }

        try {
            return match ($plaintext[0]) {
                'R' => HostResult::fromCanonicalBytes(substr($plaintext, 1)),
                'E' => HostError::fromCanonicalBytes(substr($plaintext, 1)),
                default => throw new InvalidArgumentException('Unknown Studio mutation outcome discriminator.'),
            };
        } catch (InvalidArgumentException) {
            throw new StudioMutationOutcomeRejected('The Studio mutation outcome value is invalid.');
        }
    }

    /**
     * Render a Producer success into exact canonical bytes.
     *
     * @param   HostResult  $result  Canonical Producer success.
     *
     * @return  string  Exact canonical host-result JSON.
     *
     * @since   2.0.0
     */
    private static function resultBytes(HostResult $result): string
    {
        return \Kumwe\Producer\Canonical\CanonicalJson::stringify($result->toDocument());
    }

    /**
     * Validate the complete durable replay coordinates before cryptographic use.
     *
     * @param   string  $scopeDigest   App-namespaced lowercase SHA-256 replay scope.
     * @param   string  $intentDigest  Producer's canonical SRI SHA-256 intent digest.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When either digest is outside its canonical grammar.
     *
     * @since   2.0.0
     */
    private static function assertCoordinates(string $scopeDigest, string $intentDigest): void
    {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $scopeDigest) !== 1
            || preg_match('/^sha256-[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw048]=$/D', $intentDigest) !== 1
        ) {
            throw new InvalidArgumentException('The Studio mutation outcome coordinates are invalid.');
        }
    }

    /**
     * Derive unambiguous associated data from the complete replay coordinate pair.
     *
     * @param   string  $scopeDigest   App-namespaced lowercase SHA-256 replay scope.
     * @param   string  $intentDigest  Producer's canonical SRI SHA-256 intent digest.
     *
     * @return  string  Versioned collision-free associated data.
     *
     * @since   2.0.0
     */
    private static function associatedData(string $scopeDigest, string $intentDigest): string
    {
        return "kumwe/studio-mutation-outcome/v1\0" . $scopeDigest . "\0" . $intentDigest;
    }

    /**
     * Encode bytes without padding for the existing text storage column.
     *
     * @param   string  $value  Raw nonce and ciphertext bytes.
     *
     * @return  string  Canonical unpadded Base64url text.
     *
     * @since   2.0.0
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decode canonical unpadded Base64url text strictly.
     *
     * @param   string  $value  Stored Base64url segment.
     *
     * @return  string|null  Raw bytes, or null for a malformed or non-canonical segment.
     *
     * @since   2.0.0
     */
    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded) || !hash_equals($value, self::base64UrlEncode($decoded))) {
            return null;
        }

        return $decoded;
    }
}
