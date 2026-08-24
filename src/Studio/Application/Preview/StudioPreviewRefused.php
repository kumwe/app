<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use RuntimeException;

/**
 * Delivery-safe preview refusal translated into the canonical host-error shape by the dispatcher.
 *
 * @since  2.0.0
 */
final class StudioPreviewRefused extends RuntimeException
{
    /**
     * Retain only a closed category and stable diagnostic code.
     *
     * @param  string  $category        Canonical host-error category.
     * @param  string  $diagnosticCode  Stable non-disclosing preview diagnostic.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $category, public readonly string $diagnosticCode)
    {
        parent::__construct($diagnosticCode);
    }
}
