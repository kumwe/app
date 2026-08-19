<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Integration;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\App\BusinessReporting\Application\ProjectionEvent;
use Kumwe\App\BusinessReporting\Application\ProjectionWriter;
use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Deterministically derives one item reporting row from each declared event.
 *
 * @since  2.0.0
 */
final readonly class ItemProjectionBuilder implements ProjectionBuilder
{
    /**
     * Bind the executable builder to its signed projection declaration.
     *
     * @param  ProjectionDefinition  $definition  Exact signed projection contract.
     *
     * @since  2.0.0
     */
    public function __construct(private ProjectionDefinition $definition)
    {
    }

    /**
     * Return the exact signed contract implemented by this builder.
     *
     * @return  ProjectionDefinition  Immutable rebuild declaration.
     *
     * @since   2.0.0
     */
    public function definition(): ProjectionDefinition
    {
        return $this->definition;
    }

    /**
     * Replace the event's derived row without external reads or clock access.
     *
     * @param   ProjectionDefinition  $definition  Exact active projection contract.
     * @param   ProjectionEvent       $event       Sequence-ordered item-observed input.
     * @param   ProjectionWriter      $writer      Replacement generation writer.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the event type, version, or payload is outside the contract.
     *
     * @since   2.0.0
     */
    public function apply(
        ProjectionDefinition $definition,
        ProjectionEvent $event,
        ProjectionWriter $writer,
    ): void {
        $payload = $event->payload;
        $itemId = $payload['item_id'] ?? null;
        $title = $payload['title'] ?? null;
        if (
            $definition->identifier() !== $this->definition->identifier()
            || $event->type !== '@@EXTENSION_DOTTED@@.item_observed'
            || $event->schemaVersion !== 1
            || !is_string($itemId)
            || $itemId === ''
            || mb_strlen($itemId) > 191
            || !is_string($title)
            || $title === ''
            || mb_strlen($title) > 191
        ) {
            throw new InvalidArgumentException('The item projection event is outside its signed contract.');
        }
        $writer->put(['item_id' => $itemId], ['item_id' => $itemId, 'title' => $title]);
    }
}
