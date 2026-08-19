<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * A bounded, disclosure-safe related record or owned-line projection.
 *
 * This is what a `BusinessRecordView` carries for each relationship a caller asked to include: enough
 * of the related record, or of the owned line, to render it without a second round trip. It is
 * deliberately flatter than the view it hangs from — an included record carries no includes of its
 * own, which is the bound that stops one query from walking the relationship graph — and its values
 * have already been filtered to the fields the definition exposes to readers, with restricted, secret
 * and withheld restricted, secret and entity-reference fields omitted.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordRelationView
{
    /**
     * Reader-visible field values of the related record or line, keyed by field handle.
     *
     * Fields the definition hides from readers and restricted, secret or unresolved entity-reference
     * fields are absent, so an include never has to be filtered again downstream.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Capture one included record or owned line as the reader is allowed to see it.
     *
     * @param  string                $definitionId       UUID of the included record's definition, or of
     *         the owned line's definition.
     * @param  int                   $definitionVersion  Pinned version the row was decoded with.
     * @param  string                $recordKey          Internal storage key: the record key, or the
     *         line key for an owned line.
     * @param  string                $recordId           Caller-facing identity of the row.
     * @param  int                   $version            Optimistic-lock version the row is at.
     * @param  int|null              $position           Ordinal within an ordered relationship, or null
     *         when the relationship carries no order.
     * @param  array<string, mixed>  $values             Reader-visible values, already redacted.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public int $version,
        public ?int $position,
        array $values,
    ) {
        $this->values = $values;
    }
}
