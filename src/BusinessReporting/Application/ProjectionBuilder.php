<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Deterministic event-to-derived-row function for one projection contract.
 *
 * @since  2.0.0
 */
interface ProjectionBuilder
{
    /**
     * Return the exact signed contract implemented by this builder.
     *
     * @return  ProjectionDefinition  Manifest-comparable immutable declaration.
     *
     * @since   2.0.0
     */
    public function definition(): ProjectionDefinition;

    /**
     * Apply one declared and version-compatible event without external reads or clock access.
     *
     * @param   ProjectionDefinition  $definition  Exact immutable rebuild contract.
     * @param   ProjectionEvent       $event       Next sequence-ordered input.
     * @param   ProjectionWriter      $writer      Replacement generation receiving derived writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function apply(
        ProjectionDefinition $definition,
        ProjectionEvent $event,
        ProjectionWriter $writer,
    ): void;
}
