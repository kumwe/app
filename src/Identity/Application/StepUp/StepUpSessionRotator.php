<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

use DateTimeImmutable;
use Kumwe\CMS\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpIntent;

/**
 * Transaction-participating boundary that replaces a browser session after successful step-up.
 *
 * Implementations must revoke the old identifier, mint independent cookie and CSRF secrets, retain the
 * actor and resolved membership, and stamp the successful step-up instant in the replacement row.
 *
 * @since  2.0.0
 */
interface StepUpSessionRotator
{
    /**
     * Replace the challenged session inside the provider's transaction.
     *
     * @param   StepUpIntent       $intent      Exact old session and context being elevated.
     * @param   DateTimeImmutable  $verifiedAt  Successful verification instant to stamp.
     *
     * @return  RotatedStepUpSession  New row identity and secrets disclosed once.
     *
     * @throws  StepUpRejected  When the old session or its context is no longer current.
     *
     * @since   2.0.0
     */
    public function rotate(StepUpIntent $intent, DateTimeImmutable $verifiedAt): RotatedStepUpSession;
}
