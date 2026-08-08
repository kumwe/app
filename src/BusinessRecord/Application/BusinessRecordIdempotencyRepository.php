<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotency;

interface BusinessRecordIdempotencyRepository
{
    public function find(string $scopeDigest): ?BusinessRecordIdempotency;

    public function begin(BusinessRecordIdempotency $entry): void;

    public function complete(
        string $id,
        RecordMutationResult $result,
        string $resultChecksum,
        DateTimeImmutable $completedAt,
    ): void;

    /** Delete at most $limit completed-expired or abandoned in-progress entries. */
    public function purgeExpired(DateTimeImmutable $now, int $limit): int;
}
