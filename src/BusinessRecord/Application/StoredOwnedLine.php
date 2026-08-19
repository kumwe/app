<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * One owned line as it currently stands in storage, read back so a document write can be diffed.
 *
 * An owned line is not an independent record: it is addressed inside its owner, it carries a position
 * that is meaningful only within that collection, and it goes away when the owner does. This is the shape
 * a write path needs to decide what a submitted document changes — which lines it keeps, which it moves,
 * which it drops — and it is deliberately not a view: the values are the decoded stored values, whole and
 * unfiltered, because a document rule has to be judged over the collection that exists rather than over
 * the part of it one actor happens to be allowed to read.
 *
 * @since  2.0.0
 */
final readonly class StoredOwnedLine
{
    /**
     * Capture one stored line, as its owner's collection currently holds it.
     *
     * @param  string                $recordKey  Internal storage key of the line row, unique within the
     *         line table and stable while the line exists.
     * @param  string                $recordId   Caller-facing identity of the line, which is the storage
     *         key itself under the UUID identity strategy and the reference field's value otherwise.
     * @param  int                   $position   Slot the line occupies in its owner's collection, counted
     *         from zero and dense across the whole collection.
     * @param  int                   $version    Optimistic-lock version the line row currently carries.
     * @param  array<string, mixed>  $values     Decoded line values keyed by field handle, as stored.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $recordKey,
        public string $recordId,
        public int $position,
        public int $version,
        public array $values,
    ) {
    }
}
