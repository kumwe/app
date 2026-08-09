<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * What a stored row can say about itself before any of its values are decoded.
 *
 * `BusinessRecordReadRepository::identity()` and `ownedLineIdentity()` return this from a narrow lookup
 * that reads the key, identity and version columns and nothing else. It exists because a caller cannot
 * safely load a row until it knows which definition version to read it under: that version is fed back to
 * the definition resolver, and only then is the record loaded and decoded with the shape it actually has.
 * The optimistic lock version travels alongside so a write can be attempted without a second round trip.
 * Construction validates all four values, so a malformed identity is caught at the storage boundary
 * rather than further in.
 *
 * @since  2.0.0
 */
final readonly class StoredRecordIdentity
{
    /**
     * Capture the identity of one stored row.
     *
     * @param   string  $recordKey          Internal storage key of the row, a canonical UUID.
     * @param   string  $recordId           Caller-facing identity the row was found by.
     * @param   int     $definitionVersion  Published definition version the row's shape is pinned to, which
     *          the caller re-resolves before decoding the row.
     * @param   int     $version            Optimistic lock version to present on the next write.
     *
     * @throws  InvalidArgumentException  When the record id is empty, over-long or contains control
     *          characters, the storage key is not a canonical UUID, or either version is below one.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $recordKey,
        public string $recordId,
        public int $definitionVersion,
        public int $version,
    ) {
        RecordRequestGuard::record($recordId);
        if (!Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('A stored business-record key must be a canonical UUID.');
        }
        if ($definitionVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Stored business-record versions must be positive.');
        }
    }
}
