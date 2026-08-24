<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use DomainException;

/**
 * Delivery-safe refusal from the Studio media application boundary.
 *
 * Rejected filenames, URLs, addresses, tokens and storage paths are never retained on the exception,
 * so a generic host-error presenter cannot accidentally disclose them.
 *
 * @since  2.0.0
 */
final class StudioMediaPortRejected extends DomainException
{
    /**
     * Retain only the closed host category and stable diagnostic code.
     *
     * @param  string  $category      Canonical host-error category.
     * @param  string  $failureCode   Stable non-disclosing diagnostic code.
     * @param  bool    $commitsState  Whether the safe failed lifecycle state must commit before delivery.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $category,
        public readonly string $failureCode,
        public readonly bool $commitsState = false,
    ) {
        parent::__construct('The Studio media operation was refused.');
    }
}
