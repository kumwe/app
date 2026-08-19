<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

/**
 * Raised when entry data fails the JSON Schema published by its content type.
 *
 * `JsonSchemaValidator::assertValid()` gathers every violation before it throws rather than stopping
 * at the first, so one rejected save can be turned into a complete field-by-field error response.
 * Callers that render that response read `$violations`; the exception message is only the flattened
 * form of the same list, kept readable for logs.
 *
 * @since  2.0.0
 */
final class InvalidContentData extends \DomainException
{
    /**
     * Build the failure from the violations the validator collected in one pass.
     *
     * @param  list<string>  $violations  Violation messages, each prefixed with the JSON path it applies to.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Content data does not satisfy its published schema: ' . implode('; ', $violations));
    }
}
