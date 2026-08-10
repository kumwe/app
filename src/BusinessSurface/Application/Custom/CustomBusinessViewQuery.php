<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Validated, delivery-neutral request passed to an extension-specific business view handler.
 *
 * The standard record-query tree remains the only filter, search, sort, cursor, include, and projection
 * grammar. The additional parameter map is bounded here and checked against the signed view contract by
 * `CustomBusinessViewHandlerRegistry` before it invokes extension code.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessViewQuery
{
    /**
     * Custom parameters whose names and values passed the shared structural guard.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Assemble a custom view query from already authenticated application values.
     *
     * @param   ExecutionContext          $context                 Actor, site, membership, and provenance.
     * @param   string                    $definitionIdentifier    UUID or handle of the published entity type.
     * @param   string                    $view                    View handle declared inside that definition.
     * @param   RecordQuerySpecification  $records                 Bounded record query shared by every adapter.
     * @param   array<string, mixed>      $parameters              Contract-specific query parameters.
     * @param   ?string                   $organizationIdentifier  Expected organization scope, or null.
     * @param   ?string                   $recordId                Public record identity for detail-like views.
     *
     * @throws  \InvalidArgumentException  When an identifier or parameter payload is malformed or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $view,
        public RecordQuerySpecification $records,
        array $parameters = [],
        public ?string $organizationIdentifier = null,
        public ?string $recordId = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::handle($view, 'view');
        RecordRequestGuard::organization($organizationIdentifier);
        if ($recordId !== null) {
            RecordRequestGuard::record($recordId);
        }
        CustomBusinessPayload::assertObject($parameters, 'view query');
        $this->parameters = $parameters;
    }
}
