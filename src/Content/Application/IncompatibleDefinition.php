<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

/**
 * Raised when publishing the next version of a definition would strand content already stored under it.
 *
 * `ContentModelService` compares the published schema against the proposed one before writing, and a
 * change that drops a property, tightens a type or narrows an enum leaves existing entries no longer
 * satisfying their own content type. Refusing by default is what keeps a definition edit from
 * invalidating live content by accident; an operator who accepts the consequence republishes with the
 * breaking opt-in, and the list carried here is copied into the audit event so the decision stays
 * recoverable. Every difference is reported at once rather than only the first, so a fix can be planned
 * in one pass.
 *
 * @since  2.0.0
 */
final class IncompatibleDefinition extends \DomainException
{
    /**
     * Report every breaking difference the compatibility check found.
     *
     * @param  list<string>  $breakingChanges  One operator-readable sentence per incompatible difference.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly array $breakingChanges)
    {
        parent::__construct('Definition contains breaking changes: ' . implode('; ', $breakingChanges));
    }
}
