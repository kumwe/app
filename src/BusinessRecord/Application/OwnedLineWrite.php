<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

/**
 * One prepared owned line, decided by the document command and ready for the write side to store.
 *
 * The application layer settles what a document is going to look like — which line keeps which identity,
 * what each line holds, and where each sits — and hands the whole collection over at once so the write
 * side can store it in bounded statements instead of one call per line. Three states are expressed here
 * and nothing else needs to be: a line with no stored version is new, a line with one that is marked
 * modified is rewritten, and a line with one that is not is left exactly as it is, so resubmitting a
 * document unchanged costs no statement at all.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineWrite
{
    /**
     * Capture one line of a prepared document collection.
     *
     * @param  string                $recordKey      Internal storage key the line row is written under;
     *         minted for a new line and carried over for one that already exists.
     * @param  string                $recordId       Caller-facing identity of the line, recorded so the
     *         command can report and audit the document without reading the rows back.
     * @param  int                   $position       Slot in the owner's collection, counted from zero and
     *         taken from the submitted order rather than from anything the caller asked for directly.
     * @param  array<string, mixed>  $values         Validated, normalized line values keyed by field
     *         handle, ready for the codec to spread across the line table's columns.
     * @param  ?int                  $storedVersion  Version the line row currently carries, or null when
     *         this line does not exist yet and is to be inserted at version one.
     * @param  bool                  $modified       Whether the row has to be written at all; false only
     *         for an existing line whose values and position are both unchanged.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $recordKey,
        public string $recordId,
        public int $position,
        public array $values,
        public ?int $storedVersion = null,
        public bool $modified = true,
    ) {
    }
}
