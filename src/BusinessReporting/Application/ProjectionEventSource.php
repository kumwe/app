<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Ordered source of immutable events for one projection rebuild.
 *
 * @since  2.0.0
 */
interface ProjectionEventSource
{
    /**
     * Read the next bounded sequence page after a checkpoint.
     *
     * @param   ProjectionDefinition  $definition     Projection whose declared sources constrain the read.
     * @param   int                   $afterSequence  Last fully applied sequence, zero at the start.
     * @param   int                   $limit          Maximum events requested from the source.
     *
     * @return  list<ProjectionEvent>  Events strictly ascending without duplicates; unrelated global sequences may
     *          create gaps.
     *
     * @since   2.0.0
     */
    public function next(ProjectionDefinition $definition, int $afterSequence, int $limit): array;
}
