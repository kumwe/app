<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\BusinessReporting\Application\ProjectionEvent;
use Kumwe\CMS\BusinessReporting\Application\ProjectionWriter;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use KumweExample\AssetInspection\Definitions;

/**
 * Deterministically projects the latest inspection-definition mutation without authoritative reads.
 *
 * @since  2.0.0
 */
final readonly class InspectionActivityProjectionBuilder implements ProjectionBuilder
{
    /**
     * Bind the builder to its exact signed rebuild contract.
     *
     * @param  ProjectionDefinition  $definition  Active immutable projection declaration.
     *
     * @since  2.0.0
     */
    public function __construct(private ProjectionDefinition $definition)
    {
    }

    /**
     * Return the exact signed projection contract implemented here.
     *
     * @return  ProjectionDefinition  Rebuildable schema-one core mutation projection.
     *
     * @since   2.0.0
     */
    public function definition(): ProjectionDefinition
    {
        return $this->definition;
    }

    /**
     * Replace the single inspection activity row from a sequence-ordered source event.
     *
     * @param   ProjectionDefinition  $definition  Exact active rebuild contract.
     * @param   ProjectionEvent       $event       Next ordered core mutation source event.
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
            || $event->type !== 'core.business_record.mutated'
            || $event->schemaVersion !== 1
        ) {
            throw new InvalidArgumentException('The inspection projection received an undeclared source event.');
        }
        $payload = $event->payload;
        $definitionId = $payload['definition_id'] ?? null;
        $definitionVersion = $payload['definition_version'] ?? null;
        $operation = $payload['operation'] ?? null;
        $changedFields = $payload['changed_fields'] ?? null;
        if (
            !is_string($definitionId)
            || !is_int($definitionVersion)
            || $definitionVersion < 1
            || !is_string($operation)
            || $operation === ''
            || !is_array($changedFields)
            || !array_is_list($changedFields)
        ) {
            throw new InvalidArgumentException('The inspection projection source payload is invalid.');
        }
        if ($definitionId !== Definitions::INSPECTION_DEFINITION_ID) {
            return;
        }
        $writer->put(
            ['definition_id' => $definitionId],
            [
                'definition_id' => $definitionId,
                'definition_version' => $definitionVersion,
                'last_operation' => $operation,
            ],
        );
    }
}
