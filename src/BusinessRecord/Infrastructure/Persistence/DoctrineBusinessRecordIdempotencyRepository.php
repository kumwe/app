<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\CMS\BusinessRecord\Application\RecordMutationResult;
use Kumwe\CMS\BusinessRecord\Application\RecordFingerprint;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use LogicException;
use Throwable;

final readonly class DoctrineBusinessRecordIdempotencyRepository implements BusinessRecordIdempotencyRepository
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private RecordFingerprint $fingerprints,
    ) {
    }

    public function find(string $scopeDigest): ?BusinessRecordIdempotency
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE scope_digest = ?',
            $this->tables->quoted('business_command_idempotency'),
        ), [$scopeDigest]);

        return $row === false ? null : $this->map($row);
    }

    public function begin(BusinessRecordIdempotency $entry): void
    {
        $this->assertTransaction();
        try {
            $this->database->insert($this->tables->raw('business_command_idempotency'), [
                'id' => $entry->id,
                'scope_digest' => $entry->scopeDigest,
                'site_identifier' => $entry->siteIdentifier,
                'organization_identifier' => $entry->organizationIdentifier,
                'actor_id' => $entry->actorId,
                'operation' => $entry->operation,
                'operation_id' => $entry->operationId,
                'request_fingerprint' => $entry->requestFingerprint,
                'authorization_fingerprint' => $entry->authorizationFingerprint,
                'state' => $entry->state->value,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'result' => null,
                'result_checksum' => null,
                'created_at' => $entry->createdAt,
                'completed_at' => null,
                'expires_at' => $entry->expiresAt,
            ], [
                'id' => Types::GUID,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'result' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRecordIdempotencyRace();
        }
    }

    public function complete(
        string $id,
        RecordMutationResult $result,
        string $resultChecksum,
        DateTimeImmutable $completedAt,
    ): void {
        $this->assertTransaction();
        if (!hash_equals($resultChecksum, $this->fingerprints->digest($result->toArray()))) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        $affected = $this->database->update($this->tables->raw('business_command_idempotency'), [
            'state' => BusinessRecordIdempotencyState::Completed->value,
            'result' => $result->toArray(),
            'result_checksum' => $resultChecksum,
            'completed_at' => $completedAt,
        ], [
            'id' => $id,
            'state' => BusinessRecordIdempotencyState::InProgress->value,
        ], [
            'result' => Types::JSON,
            'completed_at' => Types::DATETIME_IMMUTABLE,
        ]);
        if ($affected !== 1) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    public function purgeExpired(DateTimeImmutable $now, int $limit): int
    {
        $this->assertTransaction();
        if ($limit < 1 || $limit > 1000) {
            throw new LogicException('The idempotency purge batch is outside its bounded range.');
        }
        $ids = $this->database->createQueryBuilder()
            ->select('id')
            ->from($this->tables->raw('business_command_idempotency'))
            ->where('expires_at <= :now')
            ->andWhere(
                '(state = :completed '
                . 'OR (state = :progress AND (lease_expires_at IS NULL OR lease_expires_at <= :now)))',
            )
            ->orderBy('expires_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('completed', BusinessRecordIdempotencyState::Completed->value)
            ->setParameter('progress', BusinessRecordIdempotencyState::InProgress->value)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($ids === []) {
            return 0;
        }

        return (int) $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id IN (?)',
            $this->tables->quoted('business_command_idempotency'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): BusinessRecordIdempotency
    {
        $state = BusinessRecordIdempotencyState::tryFrom($this->string($row, 'state'))
            ?? throw new BusinessRecordIdempotencyConflict('corrupt');
        $result = $row['result'] ?? null;
        if (is_string($result)) {
            try {
                $result = json_decode($result, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new BusinessRecordIdempotencyConflict('corrupt');
            }
        }
        if ($result !== null && (!is_array($result) || array_is_list($result))) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        /** @var array<string, mixed>|null $result */

        $resultChecksum = $this->nullableString($row, 'result_checksum');
        if (
            $state === BusinessRecordIdempotencyState::Completed
            && ($result === null || $resultChecksum === null
                || !hash_equals($resultChecksum, $this->fingerprints->digest($result)))
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        try {
            return new BusinessRecordIdempotency(
                $this->string($row, 'id'),
                $this->string($row, 'scope_digest'),
                $this->string($row, 'site_identifier'),
                $this->nullableString($row, 'organization_identifier'),
                $this->string($row, 'actor_id'),
                $this->string($row, 'operation'),
                $this->string($row, 'operation_id'),
                $this->string($row, 'request_fingerprint'),
                $this->string($row, 'authorization_fingerprint'),
                $state,
                $result,
                $resultChecksum,
                $this->date($row['created_at'] ?? null),
                $this->nullableDate($row['completed_at'] ?? null),
                $this->date($row['expires_at'] ?? null),
            );
        } catch (BusinessRecordIdempotencyConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        return $value;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return new DateTimeImmutable(
                $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value,
                new DateTimeZone('UTC'),
            );
        }
        throw new BusinessRecordIdempotencyConflict('corrupt');
    }

    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Business-record idempotency writes require an active application transaction.');
        }
    }
}
