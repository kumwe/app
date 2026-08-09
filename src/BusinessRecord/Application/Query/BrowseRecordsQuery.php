<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Query;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Request to list records of one definition, pairing the caller's context with a query specification.
 *
 * `BusinessRecordService::browse()` accepts this instead of loose arguments so the two identifiers it
 * will scope by are checked at construction, before any authorization, fencing or schema work begins —
 * a malformed definition handle can therefore never reach the query compiler. Everything about the page
 * itself is delegated: `RecordQuerySpecification` already bounds its own page size, filter depth and
 * operation count, so this object deliberately validates nothing the specification owns.
 *
 * @since  2.0.0
 */
final readonly class BrowseRecordsQuery
{
    /**
     * Assemble a browse request and validate the identifiers it will be scoped by.
     *
     * @param   ExecutionContext          $context                 Actor and site the browse runs as.
     * @param   string                    $definitionIdentifier    Definition UUID or handle naming the
     *          record type to list.
     * @param   RecordQuerySpecification  $specification           Filter, search, sort, cursor and
     *          projection describing the page wanted.
     * @param   ?string                   $organizationIdentifier  Organization to scope the listing to;
     *          required for an organization-scoped definition and null for any other.
     *
     * @throws  \InvalidArgumentException  When the definition identifier is neither a UUID nor a dotted
     *          handle, or the organization identifier is not a bounded printable identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public RecordQuerySpecification $specification,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::organization($organizationIdentifier);
    }
}
