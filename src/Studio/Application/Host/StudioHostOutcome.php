<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use stdClass;

/**
 * Canonical Studio host response paired with its HTTP transport status.
 *
 * @since  2.0.0
 */
final readonly class StudioHostOutcome
{
    /**
     * Pair a schema-valid protocol document with its transport status.
     *
     * @param  int       $status    HTTP status corresponding to the canonical outcome category.
     * @param  stdClass  $document  Host result or host error document.
     *
     * @since  2.0.0
     */
    public function __construct(public int $status, public stdClass $document)
    {
    }
}
