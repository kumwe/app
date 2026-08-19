<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Immutable, checksummed snapshot of a content entry as it stood at one point in its history.
 *
 * `ContentService` captures one of these on every accepted change, which is what gives the editorial
 * history something to show, diff and restore from without keeping a mutable copy of the entry. The
 * checksum is taken over a canonical encoding of the snapshot — map keys sorted, slashes and unicode
 * left unescaped — so the same entry state always hashes identically no matter what key order the
 * store hands back, and `hasValidChecksum()` can therefore detect a revision row altered outside the
 * domain rather than merely a re-serialised one.
 *
 * @since  2.0.0
 */
final readonly class ContentRevision
{
    /**
     * Bind an already-validated snapshot to its revision number and checksum.
     *
     * @param  string                $id              UUID this revision is stored under, already lowercased.
     * @param  string                $contentEntryId  UUID of the entry the snapshot was taken from.
     * @param  int                   $revisionNumber  Position in the entry's revision sequence.
     * @param  array<string, mixed>  $snapshot        Entry state as captured, in `ContentEntry::snapshot()` shape.
     * @param  string                $checksum        Digest over the canonical encoding of that snapshot.
     * @param  DateTimeImmutable     $createdAt       Instant the snapshot was taken.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $id,
        private string $contentEntryId,
        private int $revisionNumber,
        private array $snapshot,
        private string $checksum,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Snapshot an entry as it stands and compute the checksum that pins that state.
     *
     * This is the only way a revision comes into being, so no revision can exist without a checksum
     * that matches its snapshot. The caller supplies the sequence number because only the store knows
     * which numbers are already taken for that entry.
     *
     * @param   string             $id              UUID for the new revision row; lowercased on the way in.
     * @param   ContentEntry       $entry           Entry whose current state is captured.
     * @param   int                $revisionNumber  Next free number in the entry's sequence, at least one.
     * @param   DateTimeImmutable  $createdAt       Instant to stamp on the revision.
     *
     * @return  self  A revision carrying the snapshot and its freshly computed checksum.
     *
     * @throws  InvalidArgumentException  When the ID is not a canonical UUID or the number is below one.
     * @throws  JsonException  When the snapshot holds values that cannot be encoded for checksumming.
     *
     * @since   2.0.0
     */
    public static function capture(
        string $id,
        ContentEntry $entry,
        int $revisionNumber,
        DateTimeImmutable $createdAt,
    ): self {
        self::assertUuid($id);

        if ($revisionNumber < 1) {
            throw new InvalidArgumentException('A revision number must be at least one.');
        }

        $snapshot = $entry->snapshot();

        return new self(
            strtolower($id),
            $entry->id(),
            $revisionNumber,
            $snapshot,
            self::checksumFor($snapshot),
            $createdAt,
        );
    }

    /**
     * Return the identifier this revision is stored under.
     *
     * @return  string  Canonical UUID in lowercase.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Identify the entry whose history this revision belongs to.
     *
     * @return  string  UUID of the snapshotted content entry.
     *
     * @since   2.0.0
     */
    public function contentEntryId(): string
    {
        return $this->contentEntryId;
    }

    /**
     * Return where this revision sits in the entry's history.
     *
     * @return  int  One-based sequence number, unique within the entry and ascending with time.
     *
     * @since   2.0.0
     */
    public function revisionNumber(): int
    {
        return $this->revisionNumber;
    }

    /**
     * Return the captured entry state, for rendering history or rebuilding an earlier entry.
     *
     * @return  array<string, mixed>  The `ContentEntry::snapshot()` payload as it stood when captured,
     *          in the key order it was supplied in rather than canonical order.
     *
     * @since   2.0.0
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * Return the digest that pins the snapshot's content.
     *
     * @return  string  SHA-256 hex digest over the canonical encoding of the snapshot.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return $this->checksum;
    }

    /**
     * Return when the snapshot was taken.
     *
     * @return  DateTimeImmutable  Capture instant, supplied by the clock the writing service holds.
     *
     * @since   2.0.0
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Recompute the digest and report whether the snapshot still matches the checksum stored beside it.
     *
     * Worth asking after loading a revision from the store: a false answer means the row was changed
     * outside the domain, not that the entry itself moved on. The comparison is constant time.
     *
     * @return  bool  True when the snapshot and its stored checksum still agree.
     *
     * @throws  JsonException  When the snapshot holds values that cannot be encoded for checksumming.
     *
     * @since   2.0.0
     */
    public function hasValidChecksum(): bool
    {
        return hash_equals($this->checksum, self::checksumFor($this->snapshot));
    }

    /**
     * Digest a snapshot through the canonical encoding both capture and verification agree on.
     *
     * @param   array<string, mixed>  $snapshot  Snapshot to reduce to a digest.
     *
     * @return  string  SHA-256 hex digest, stable across key order and re-serialisation.
     *
     * @throws  JsonException  When the snapshot holds values JSON cannot represent.
     *
     * @since   2.0.0
     */
    private static function checksumFor(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode(
                self::canonicalize($snapshot),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * Normalise a snapshot fragment so that two equal states encode to identical JSON.
     *
     * Maps are sorted by key as strings and lists are left in place, because list order is part of the
     * content while map order is an artefact of however the value was decoded.
     *
     * @param   mixed  $value  Snapshot fragment: a map, a list, or a scalar to pass through untouched.
     *
     * @return  mixed  The fragment with every nested map's keys in sorted order.
     *
     * @since   2.0.0
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /**
     * Refuse a revision identifier that is not a canonical UUID.
     *
     * @param   string  $id  Candidate identifier, in either case.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A content revision ID must be a canonical UUID.');
        }
    }
}
