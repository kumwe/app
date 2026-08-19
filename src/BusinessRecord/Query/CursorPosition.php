<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Verified page position that a signed browse cursor carries from one request to the next.
 *
 * Both directions of a cursor build this value: `DoctrineBusinessRecordReadRepository` when it mints a
 * token from the last row of the page it is returning, and `RecordCursorCodec` when it decodes a token
 * a client sent back. Construction is therefore where an untrusted payload is checked, which is why
 * nothing but a hex digest, a UUID record key, and at most the five sort values a query may sort by
 * survives it. The digest names the exact query the position belongs to;
 * `DoctrineBusinessRecordQueryCompiler` compares it against the query actually being run, so a
 * genuine cursor replayed against a different filter or sort is refused rather than quietly paging
 * over rows the original query never matched.
 *
 * @since  2.0.0
 */
final readonly class CursorPosition
{
    /**
     * Values of the last returned row's sort columns, in the order the query's sorts declare them.
     *
     * Reindexed from zero on construction so the list round-trips through JSON as an array rather than
     * an object. The compiler requires one entry per declared sort, or a single default-ordering value
     * when the query declares none.
     *
     * @var    list<mixed>
     * @since  2.0.0
     */
    public array $sortValues;

    /**
     * Build a page position, rejecting every part of it a client could have tampered with.
     *
     * @param   string       $specificationDigest  Lowercase 64-character hex checksum of the query this
     *          position belongs to, as the query compiler computes it.
     * @param   list<mixed>  $sortValues           Sort-column values of the last row on the page just
     *          returned, in sort order; at most five, each limited to the types a query may bind.
     * @param   string       $recordKey            UUID of that last row, which breaks ties between rows
     *          whose sort values are equal.
     *
     * @throws  InvalidArgumentException  When the digest is not 64 hex characters, the record key is not a
     *          UUID, more than five sort values are supplied, or a sort value is of a type or size a query
     *          may not bind.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $specificationDigest,
        array $sortValues,
        public string $recordKey,
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $specificationDigest) !== 1
            || !\Ramsey\Uuid\Uuid::isValid($recordKey)
        ) {
            throw new InvalidArgumentException('A business-record cursor position identity is invalid.');
        }
        if (count($sortValues) > 5) {
            throw new InvalidArgumentException('A business-record cursor contains too many sort values.');
        }
        foreach ($sortValues as $value) {
            QueryValue::assert($value);
        }
        $this->sortValues = array_values($sortValues);
    }

    /**
     * Flatten the position into the payload `RecordCursorCodec` signs and encodes into a token.
     *
     * Sort values are canonicalised on the way out, so a value object and the scalar it flattens to
     * produce the same payload and the position survives the JSON round trip a decode performs.
     *
     * @return  array{specification: string, values: list<mixed>, record_key: string}  The query digest,
     *          the canonicalised sort values in sort order, and the tie-breaking record key.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'specification' => $this->specificationDigest,
            'values' => array_map(QueryCanonicalizer::value(...), $this->sortValues),
            'record_key' => $this->recordKey,
        ];
    }
}
