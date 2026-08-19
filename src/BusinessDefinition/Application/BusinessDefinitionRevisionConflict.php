<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use RuntimeException;
use Throwable;

/**
 * Signals that a definition write quoted a draft revision the catalog no longer carries.
 *
 * Business definitions are edited optimistically: nothing is locked while an administrator holds a form
 * open or an import is in flight, so every draft save and every publication states the revision it was
 * composed against and is refused outright when another writer got there first. Raising this instead of
 * writing blind is what keeps two administrator sessions from silently overwriting each other's modelling
 * work. Both revisions are carried as properties as well as named in the message, and the JSON API renders
 * it as `412 Precondition Failed`, so the remedy is always to reload the draft and recompose the change
 * rather than to retry the same payload.
 *
 * @since  2.0.0
 */
final class BusinessDefinitionRevisionConflict extends RuntimeException
{
    /**
     * Build the conflict from the revision the caller quoted and the one the catalog holds.
     *
     * @param  int         $expected  Draft revision the write was composed against; zero when the caller
     *         quoted none at all.
     * @param  int         $actual    Draft revision the catalog holds now; zero when it holds no definition
     *         under that handle.
     * @param  ?Throwable  $previous  Driver failure that exposed the conflict, when a concurrent writer
     *         created the definition first and the clash surfaced as a unique-constraint violation.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly int $expected, public readonly int $actual, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'Business-definition draft revision %d is stale; current revision is %d.',
            $expected,
            $actual,
        ), 0, $previous);
    }
}
