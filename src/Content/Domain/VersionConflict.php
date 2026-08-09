<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use RuntimeException;

/**
 * Signals that a content write quoted a version the stored record no longer carries.
 *
 * Content entries and content model definitions are written optimistically: nothing is locked while an
 * editor holds a form open, so every change states the version it was composed against and is refused
 * outright when another writer got there first. Raising this rather than applying the write blind is
 * what stops two administrator screens from silently overwriting each other. The message names both
 * versions, and the JSON API maps it to `412 Precondition Failed`, so the remedy is always to reload
 * the record and retry rather than to retry the same payload.
 *
 * @since  2.0.0
 */
final class VersionConflict extends RuntimeException
{
    /**
     * Build the conflict from the two versions that failed to match.
     *
     * @param  int  $expected  Version the caller composed its change against.
     * @param  int  $actual    Version the record carries in the store now; adapters report zero when the
     *         row has been removed entirely.
     *
     * @since  2.0.0
     */
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(sprintf('Expected version %d, but the current version is %d.', $expected, $actual));
    }
}
