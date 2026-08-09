<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;

/**
 * Reports which rows one relationship write actually re-versioned.
 *
 * `BusinessRecordWriteRepository::relate()` and `unrelate()` normally touch the source row alone, but a
 * relationship whose canonical storage belongs to the inverse side is written on the target's row or
 * junction instead, which re-versions the target too. Handing both records back is what lets
 * `BusinessRecordService` record a mutation entry against each side under the handle that side really
 * stores, rather than inferring from the request which rows moved. This is an internal repository result
 * that the service consumes and never returns to its own callers.
 *
 * @since  2.0.0
 */
final readonly class RelationshipWriteResult
{
    /**
     * Capture the records a relationship write left behind.
     *
     * @param  BusinessRecord   $source              Source record at the version the write gave it; always
     *         present, because every relationship write re-versions the source.
     * @param  ?BusinessRecord  $target              Target record at its own new version, or null when the
     *         write left the target row untouched.
     * @param  ?string          $targetRelationship  Handle of the inverse relationship whose storage the
     *         target row carries, or null when no target row moved.
     *
     * @since  2.0.0
     */
    public function __construct(
        public BusinessRecord $source,
        public ?BusinessRecord $target = null,
        public ?string $targetRelationship = null,
    ) {
    }
}
