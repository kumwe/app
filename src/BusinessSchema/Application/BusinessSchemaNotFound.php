<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use RuntimeException;

/**
 * Signals that a business-schema resource a caller named could not be resolved on the actor's site.
 *
 * One exception covers every unresolvable reference the schema layer meets — a plan, an installed schema,
 * a catalog entry, a published definition version, a dependency handle one definition names, a
 * recovery-evidence record — because the caller's remedy is the same in each case: the identifier it
 * supplied is not usable on its site. Absent rather than forbidden is the deliberate reading; purge
 * planning raises exactly this when the installation it found turns out to belong to another site. It is
 * thrown where a lookup returns null and the work cannot continue, so planning, approval, and execution
 * fail loudly instead of proceeding on a missing collaborator, and `BusinessApiResponder` renders it as a
 * 404 `business-schema-not-found` problem document.
 *
 * @since  2.0.0
 */
final class BusinessSchemaNotFound extends RuntimeException
{
    /**
     * Name the unresolved resource in the operator-facing message.
     *
     * The subject reaches API clients inside the problem document, so pass the identifier the caller
     * itself supplied and never internal detail.
     *
     * @param  string  $subject  Identifier the failed lookup used: a plan, definition, or evidence UUID,
     *         or a definition handle.
     *
     * @since  2.0.0
     */
    public function __construct(string $subject)
    {
        parent::__construct('The requested business schema resource was not found: ' . $subject);
    }
}
