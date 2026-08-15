<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\BusinessReporting\Application\ProjectionEvent;
use Kumwe\CMS\BusinessReporting\Application\ProjectionWriter;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Rebuildable projection half of the manifest-4 compatibility package.
 *
 * @since  2.0.0
 */
final readonly class ObservationProjectionBuilder implements ProjectionBuilder
{
    /**
     * Bind the builder to the rebuild contract the manifest signed.
     *
     * @param  ProjectionDefinition  $definition  Active immutable projection declaration.
     *
     * @since  2.0.0
     */
    public function __construct(private ProjectionDefinition $definition)
    {
    }

    /**
     * Return the signed projection contract implemented here.
     *
     * @return  ProjectionDefinition  The declaration handed in at construction.
     *
     * @since   2.0.0
     */
    public function definition(): ProjectionDefinition
    {
        return $this->definition;
    }

    /**
     * Replace the projected row for one observation, deterministically and without authoritative reads.
     *
     * @param   ProjectionDefinition  $definition  Exact active rebuild contract.
     * @param   ProjectionEvent       $event       Next ordered source event.
     * @param   ProjectionWriter      $writer      Replacement-generation writer.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an event contradicts the signed projection source contract.
     *
     * @since   2.0.0
     */
    public function apply(
        ProjectionDefinition $definition,
        ProjectionEvent $event,
        ProjectionWriter $writer,
    ): void {
        if (
            $definition->checksum() !== $this->definition->checksum()
            || $event->type !== 'kumwe.contract-manifest-four.observed'
            || $event->schemaVersion !== 1
        ) {
            throw new InvalidArgumentException('The compatibility projection received an undeclared source event.');
        }
        $message = $event->payload['message'] ?? null;
        if (!is_string($message) || $message === '') {
            throw new InvalidArgumentException('The compatibility projection source payload is invalid.');
        }
        $writer->put(
            ['aggregate_id' => $event->id],
            ['aggregate_id' => $event->id, 'message' => $message],
        );
    }
}
