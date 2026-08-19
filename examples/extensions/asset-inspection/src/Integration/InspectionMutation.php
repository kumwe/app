<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Domain\EventEnvelope;
use KumweExample\AssetInspection\Definitions;

/**
 * Validates the exact safe core mutation payload used by every example integration handler.
 *
 * @since  2.0.0
 */
final class InspectionMutation
{
    /**
     * Validate a core record mutation and report whether it belongs to the example inspection definition.
     *
     * @param   EventEnvelope  $event  Domain, integration, or projection source event to classify.
     *
     * @return  bool  True for the component's inspection definition and false for another valid definition.
     *
     * @throws  InvalidArgumentException  When direct invocation bypasses the registered core event contract.
     *
     * @since   2.0.0
     */
    public static function belongsToInspection(EventEnvelope $event): bool
    {
        if ($event->eventType() !== 'core.business_record.mutated' || $event->schemaVersion() !== 1) {
            throw new InvalidArgumentException('The inspection handler requires core record mutation schema one.');
        }
        $payload = $event->payload();
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['changed_fields', 'definition_id', 'definition_version', 'operation']) {
            throw new InvalidArgumentException('The core record mutation payload has an invalid shape.');
        }
        $changedFields = $payload['changed_fields'] ?? null;
        if (
            !is_string($payload['definition_id'] ?? null)
            || !is_int($payload['definition_version'] ?? null)
            || $payload['definition_version'] < 1
            || !is_string($payload['operation'] ?? null)
            || !is_array($changedFields)
            || !array_is_list($changedFields)
            || count($changedFields) > 256
        ) {
            throw new InvalidArgumentException('The core record mutation payload values are invalid.');
        }
        foreach ($changedFields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
                throw new InvalidArgumentException('A changed field handle is invalid.');
            }
        }

        return $payload['definition_id'] === Definitions::INSPECTION_DEFINITION_ID;
    }

    /**
     * Prevent construction of the stateless classifier.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
