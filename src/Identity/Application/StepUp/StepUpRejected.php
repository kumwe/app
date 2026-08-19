<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

use RuntimeException;

/**
 * Non-enumerating refusal shared by invalid, expired, replayed, and concurrently spent challenges.
 *
 * @since  2.0.0
 */
final class StepUpRejected extends RuntimeException
{
    /**
     * Build the single safe message every rejected step-up attempt exposes.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The step-up credential is invalid, expired, or already used.');
    }
}
