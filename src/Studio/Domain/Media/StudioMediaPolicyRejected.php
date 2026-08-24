<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use DomainException;

/**
 * Safe stable rejection emitted by the canonical Studio upload policy.
 *
 * @since  2.0.0
 */
final class StudioMediaPolicyRejected extends DomainException
{
    /**
     * Carry a canonical media failure code without retaining rejected request values.
     *
     * @param  string  $failureCode  Closed media diagnostic code.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('The Studio media upload was refused by policy.');
    }
}
