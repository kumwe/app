<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionWriter;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Deterministically projects the latest inspection-definition mutation without authoritative reads.
 *
 * @since  2.0.0
 */
final readonly class InspectionActivityProjectionBuilder implements ProjectionBuilder
{
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
            $definition->identifier() !== 'kumwe.asset-inspection-example.inspection-activity'
            || $event->type() !== 'core.business_record.mutated'
            || $event->schemaVersion() !== 1
        ) {
            throw new InvalidArgumentException('The inspection projection received an undeclared source event.');
        }
        $payload = $event->payload();
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
        if ($definitionId !== InspectionMutation::DEFINITION_ID) {
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
