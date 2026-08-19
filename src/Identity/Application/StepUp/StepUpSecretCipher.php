<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

/**
 * Authenticated encryption boundary for TOTP secrets at rest.
 *
 * @since  2.0.0
 */
interface StepUpSecretCipher
{
    /**
     * Seal a raw authenticator secret against a credential-specific associated-data value.
     *
     * @param   string  $plaintext       Raw high-entropy TOTP secret.
     * @param   string  $associatedData  Stable credential and actor binding stored outside the envelope.
     *
     * @return  string  Versioned authenticated ciphertext safe to persist.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): string;

    /**
     * Open an envelope only when its authentication tag and associated-data binding agree.
     *
     * @param   string  $ciphertext      Versioned stored envelope.
     * @param   string  $associatedData  Expected credential and actor binding.
     *
     * @return  string  Original raw TOTP secret.
     *
     * @throws  StepUpRejected  When decoding or authentication fails.
     *
     * @since   2.0.0
     */
    public function decrypt(string $ciphertext, string $associatedData): string;
}
