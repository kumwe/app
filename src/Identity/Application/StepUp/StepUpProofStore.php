<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

use Kumwe\CMS\Identity\Domain\StepUp\StepUpVerification;

/**
 * Durable replay-fence port for fresh step-up proofs.
 *
 * @since  2.0.0
 */
interface StepUpProofStore
{
    /**
     * Persist a proof nonce digest and every context binding inside the verification transaction.
     *
     * @param   StepUpVerification  $verification  Fresh result already bound to its rotated session.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the proof cannot be durably recorded.
     *
     * @since   2.0.0
     */
    public function issue(StepUpVerification $verification): void;
}
