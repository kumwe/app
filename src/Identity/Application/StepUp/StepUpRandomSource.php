<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

/**
 * Cryptographically secure random source used for enrollment, recovery, and proof secrets.
 *
 * @since  2.0.0
 */
interface StepUpRandomSource
{
    /**
     * Produce an unpredictable byte string.
     *
     * @param   int  $length  Number of bytes, always positive and bounded by the caller.
     *
     * @return  string  Exactly the requested number of random bytes.
     *
     * @since   2.0.0
     */
    public function bytes(int $length): string;

    /**
     * Produce a canonical UUID for a persisted or audited record.
     *
     * @return  string  Canonical random or time-ordered UUID.
     *
     * @since   2.0.0
     */
    public function uuid(): string;
}
