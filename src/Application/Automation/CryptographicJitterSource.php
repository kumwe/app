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
    /** @inheritDoc */
    public function between(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
