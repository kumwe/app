<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Supplies unbiased operating-system randomness for production retry backoff.
 *
 * @since  2.0.0
 */
final readonly class CryptographicJitterSource implements JitterSource
{
    /**
     * Generate a cryptographically secure integer within the inclusive bounds.
     *
     * @param   int  $minimum  Inclusive lower bound accepted for the value.
     * @param   int  $maximum  Inclusive upper bound accepted for the value.
     *
     * @return  int  Cryptographically sampled integer inside the inclusive bounds.
     *
     * @since   2.0.0
     */
    public function between(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
