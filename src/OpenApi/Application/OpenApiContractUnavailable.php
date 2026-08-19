<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use RuntimeException;
use Throwable;

/**
 * Signals that no verified contract exists for the exact current generation.
 *
 * @since  2.0.0
 */
final class OpenApiContractUnavailable extends RuntimeException
{
    /**
     * Preserve the operator-only cause behind one stable public failure category.
     *
     * @param  ?Throwable  $previous  Internal metadata, compiler, or cache failure.
     *
     * @since  2.0.0
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The current OpenAPI contract is unavailable.', 0, $previous);
    }
}
