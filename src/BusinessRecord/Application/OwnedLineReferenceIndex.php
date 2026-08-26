<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Throwable;

/**
 * Every entity reference a document's lines name, resolved once for the whole collection.
 *
 * Resolving a reference costs a mutation-fence lock, a definition resolution and a row lookup. Doing that
 * per line is what turned a thousand-line document into thousands of avoidable round trips: the work is
 * identical for every line naming the same target, and the lookups differ only in the value. This index is
 * that work done once — the per-target part once per target, the per-value part once per distinct value —
 * so the line loop that consumes it issues no statement at all.
 *
 * It deliberately carries failures rather than throwing them. A target a caller may not reach fails once
 * during indexing, and the line loop replays that failure at the first line that actually names the field,
 * which is where the un-batched code raised it. Batching therefore changes what a document costs and not
 * what it answers.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineReferenceIndex
{
    /**
     * Capture the resolved keys and the per-field failures one collection's references produced.
     *
     * @param  array<string, array<string, string>>  $keys      Storage key of every value that resolved,
     *         keyed by field handle and then by the submitted value that asked for it. A submitted value
     *         absent from a field's map did not resolve and is a violation for the line naming it.
     * @param  array<string, Throwable>              $failures  The exception a field's target resolution
     *         raised, keyed by field handle; replayed rather than thrown so ordering is preserved.
     *
     * @since  2.0.0
     */
    public function __construct(
        private array $keys,
        private array $failures,
    ) {
    }

    /**
     * An index over a collection that names no entity reference at all.
     *
     * @return  self  The empty index.
     *
     * @since   2.0.0
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * The exception this field's target resolution raised, if it raised one.
     *
     * @param   string  $field  Field handle the line is about to resolve.
     *
     * @return  Throwable|null  The failure to replay, or null when the field resolved normally.
     *
     * @since   2.0.0
     */
    public function failure(string $field): ?Throwable
    {
        return $this->failures[$field] ?? null;
    }

    /**
     * The storage key one submitted reference value resolved to.
     *
     * @param   string  $field  Field handle naming the reference.
     * @param   string  $value  Submitted value, exactly as the line carries it.
     *
     * @return  string|null  The stored row's key, or null when no visible row answered to that value.
     *
     * @since   2.0.0
     */
    public function key(string $field, string $value): ?string
    {
        return $this->keys[$field][$value] ?? null;
    }
}
