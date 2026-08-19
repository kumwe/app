<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

/**
 * One typed field in a derived, non-authoritative reporting projection.
 *
 * @since  2.0.0
 */
final readonly class ProjectionFieldDefinition
{
    /**
     * Declare one field that a deterministic projection builder may write.
     *
     * @param  string           $name      Stable field handle.
     * @param  ReportValueType  $type      Scalar type accepted by the projection writer.
     * @param  bool             $nullable  Whether a rebuilt row may omit the value.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $name,
        public ReportValueType $type,
        public bool $nullable = false,
    ) {
        ReportDefinitionGuard::handle($name, 'projection field');
    }
}
