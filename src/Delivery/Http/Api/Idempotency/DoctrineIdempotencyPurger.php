<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;

final readonly class DoctrineIdempotencyPurger implements IdempotencyPurger
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    public function purgeExpired(int $batchSize = 1_000): int
    {
        $cutoff = $this->clock->now();
        $ids = $this->expiredCandidates($cutoff, $batchSize);
        if ($ids === []) {
            return 0;
        }

        return $this->deleteExpiredCandidates($ids, $cutoff);
    }

    /** @return list<string> */
    public function expiredCandidates(DateTimeImmutable $cutoff, int $batchSize): array
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('Idempotency purge batch size must be between 1 and 10000.');
        }
        $ids = array_values($this->database->fetchFirstColumn(sprintf(
            "SELECT id FROM %s WHERE expires_at <= ? AND ((state IN ('completed', 'failed') "
            . "AND owner_token IS NULL) OR (state = 'in_progress' "
            . 'AND (locked_until IS NULL OR locked_until <= ?))) ORDER BY expires_at, id LIMIT %d',
            $this->tables->quoted('idempotency'),
            $batchSize,
        ), [$cutoff, $cutoff], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]));

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException('An idempotency purge candidate has an invalid identifier.');
            }
        }
        /** @var list<non-empty-string> $ids */
        return $ids;
    }

    /** @param list<string> $ids */
    public function deleteExpiredCandidates(array $ids, DateTimeImmutable $cutoff): int
    {
        if ($ids === []) {
            return 0;
        }
        return (int) $this->database->executeStatement(sprintf(
            "DELETE FROM %s WHERE id IN (?) AND expires_at <= ? AND ((state IN ('completed', 'failed') "
            . "AND owner_token IS NULL) OR (state = 'in_progress' "
            . 'AND (locked_until IS NULL OR locked_until <= ?)))',
            $this->tables->quoted('idempotency'),
        ), [$ids, $cutoff, $cutoff], [
            ArrayParameterType::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ]);
    }
}
