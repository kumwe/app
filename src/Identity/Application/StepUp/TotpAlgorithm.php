<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

use DateTimeImmutable;

/**
 * RFC 6238 calculation port kept separate from credential orchestration.
 *
 * @since  2.0.0
 */
interface TotpAlgorithm
{
    /**
     * Encode a raw authenticator secret for manual entry and provisioning URIs.
     *
     * @param   string  $secret  Raw high-entropy bytes.
     *
     * @return  string  Unpadded RFC 4648 Base32 representation.
     *
     * @since   2.0.0
     */
    public function encodeSecret(string $secret): string;

    /**
     * Find the accepted time-step for a candidate within the configured drift window.
     *
     * @param   string             $secret  Raw enrolled secret.
     * @param   string             $code    Fixed-width decimal candidate.
     * @param   DateTimeImmutable  $now     Trusted current time.
     *
     * @return  ?int  Matching counter, or null for any malformed or incorrect candidate.
     *
     * @since   2.0.0
     */
    public function verify(string $secret, string $code, DateTimeImmutable $now): ?int;
}
