<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

/**
 * One-way keyed digest boundary for high-entropy recovery codes.
 *
 * @since  2.0.0
 */
interface StepUpRecoveryCodeHasher
{
    /**
     * Derive the stable digest stored and later consumed atomically.
     *
     * @param   string  $normalizedCode  Lowercase hexadecimal recovery code without separators.
     *
     * @return  string  Lowercase fixed-length hexadecimal digest.
     *
     * @since   2.0.0
     */
    public function digest(string $normalizedCode): string;
}
