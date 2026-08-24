<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Typed, non-disclosing refusal at the Studio host authority boundary.
 *
 * @since  2.0.0
 */
final class StudioHostAccessRefused extends RuntimeException
{
    /**
     * Carry only delivery-safe category and diagnostic identifiers across the application boundary.
     *
     * @param  string  $diagnosticCode  Stable delivery-safe diagnostic code.
     * @param  string  $category        Canonical host error category.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $diagnosticCode, public readonly string $category)
    {
        parent::__construct('The Studio host request was refused.');
    }
}
