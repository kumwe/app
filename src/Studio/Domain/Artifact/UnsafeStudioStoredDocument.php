<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Artifact;

use RuntimeException;

/**
 * Typed fail-closed refusal raised by the neutral stored-document policy.
 *
 * @since  2.0.0
 */
final class UnsafeStudioStoredDocument extends RuntimeException
{
    /**
     * Carry only the closed rejection reason across the domain boundary.
     *
     * @param  StudioStoredDocumentRejection  $rejection  Stable non-disclosing rejection reason.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly StudioStoredDocumentRejection $rejection)
    {
        parent::__construct('The Studio document is not safe to store.');
    }
}
