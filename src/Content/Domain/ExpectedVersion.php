<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use InvalidArgumentException;

/**
 * Version a caller believes a content entry is at, carried into every mutating operation.
 *
 * Content writes are optimistic: nothing is locked while an editor has a form open, so each change
 * states the version it was composed against and is refused when the stored entry has moved on since.
 * Passing this value object instead of a bare integer keeps that comparison in one place, so a stale
 * editor form fails loudly as a `VersionConflict` rather than quietly overwriting another author.
 *
 * @since  2.0.0
 */
final readonly class ExpectedVersion
{
    /**
     * Capture the version the caller expects the stored entry to carry.
     *
     * @param   int  $value  Version the caller last observed; entry versions start at one.
     *
     * @throws  InvalidArgumentException  When the version is below one and so cannot name a stored entry.
     *
     * @since   2.0.0
     */
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('An expected version must be at least one.');
        }
    }

    /**
     * Expose the expected version so a repository can filter its update statement on it.
     *
     * @return  int  Version the caller expects the stored entry to still carry.
     *
     * @since   2.0.0
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * Abandon the operation unless the stored entry is still at the expected version.
     *
     * @param   int  $actual  Version the entry carries in the store at this moment.
     *
     * @return  void
     *
     * @throws  VersionConflict  When another writer advanced the entry after the caller read it.
     *
     * @since   2.0.0
     */
    public function assertMatches(int $actual): void
    {
        if ($this->value !== $actual) {
            throw new VersionConflict($this->value, $actual);
        }
    }
}
