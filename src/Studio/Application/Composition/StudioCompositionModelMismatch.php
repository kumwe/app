<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Refuses an immutable Blueprint whose locked Content model no longer matches the authorized projection.
 *
 * @since  2.0.0
 */
final class StudioCompositionModelMismatch extends RuntimeException
{
    /**
     * Create the non-disclosing refusal used when any model coordinate drifts.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The Studio Blueprint Content-model lock requires an explicit migration.');
    }
}
