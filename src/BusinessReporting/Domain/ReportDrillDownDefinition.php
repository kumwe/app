<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

/**
 * Declarative link from one output identity to a generated record view.
 *
 * @since  2.0.0
 */
final readonly class ReportDrillDownDefinition
{
    /**
     * Declare a drill-down without accepting a URL or executable template.
     *
     * @param  string  $recordAlias           Output alias carrying the target public record identity.
     * @param  string  $definitionIdentifier  Target business-definition handle.
     * @param  string  $viewIdentifier        Generated view contribution handle.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $recordAlias,
        public string $definitionIdentifier,
        public string $viewIdentifier,
    ) {
        ReportDefinitionGuard::handle($recordAlias, 'drill-down identity');
        ReportDefinitionGuard::identifier($definitionIdentifier, 'drill-down definition');
        ReportDefinitionGuard::identifier($viewIdentifier, 'drill-down view');
    }
}
