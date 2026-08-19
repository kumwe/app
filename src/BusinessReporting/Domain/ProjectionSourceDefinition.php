<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;

/**
 * One versioned event source admitted by a reproducible reporting projection.
 *
 * @since  2.0.0
 */
final readonly class ProjectionSourceDefinition
{
    /**
     * Declare one event type and the exact schema versions its builder understands.
     *
     * @param   string               $eventType       Namespaced immutable event type.
     * @param   non-empty-list<int>  $schemaVersions  Accepted positive schema versions.
     *
     * @throws  InvalidArgumentException  When the event type or version list is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(public string $eventType, public array $schemaVersions)
    {
        ReportDefinitionGuard::identifier($eventType, 'projection event type');
        if ($schemaVersions === [] || count($schemaVersions) > 16 || !array_is_list($schemaVersions)) {
            throw new InvalidArgumentException('A projection source needs one to sixteen schema versions.');
        }
        foreach ($schemaVersions as $version) {
            if (!is_int($version) || $version < 1) {
                throw new InvalidArgumentException('A projection source schema version is invalid.');
            }
        }
        if (count(array_unique($schemaVersions)) !== count($schemaVersions)) {
            throw new InvalidArgumentException('A projection source schema version is duplicated.');
        }
    }
}
