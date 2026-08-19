<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\StepUp;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\StepUp\StepUpRandomSource;
use Ramsey\Uuid\Uuid;

/**
 * Operating-system CSPRNG and UUIDv7 source for production step-up material.
 *
 * @since  2.0.0
 */
final readonly class NativeStepUpRandomSource implements StepUpRandomSource
{
    /**
     * Produce a bounded random byte string through PHP's operating-system CSPRNG.
     *
     * @param   int  $length  Requested byte count, 1 through 4096.
     *
     * @return  string  Exactly the requested number of bytes.
     *
     * @throws  InvalidArgumentException  When the request is outside the bound.
     *
     * @since   2.0.0
     */
    public function bytes(int $length): string
    {
        if ($length < 1 || $length > 4096) {
            throw new InvalidArgumentException('A step-up random byte request is outside its bound.');
        }

        return random_bytes($length);
    }

    /**
     * Produce a canonical time-ordered UUIDv7.
     *
     * @return  string  Canonical UUIDv7.
     *
     * @since   2.0.0
     */
    public function uuid(): string
    {
        return Uuid::uuid7()->toString();
    }
}
