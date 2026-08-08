<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use LogicException;

final readonly class DoctrineBusinessRecordRevisionRepository implements BusinessRecordRevisionRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function append(BusinessRecordRevision $revision): void
    {
        $this->assertTransaction();
        $this->database->insert($this->tables->raw('business_record_revisions'), [
            'id' => $revision->revisionId,
            'definition_id' => $revision->definitionId,
            'definition_version' => $revision->definitionVersion,
            'site_identifier' => $revision->siteIdentifier,
            'organization_identifier' => $revision->organizationIdentifier,
            'record_id' => $revision->recordKey,
            'record_identity_digest' => $revision->recordIdentityDigest,
            'record_version' => $revision->recordVersion,
            'revision_number' => $revision->revisionNumber,
            'action' => $revision->operation,
            'actor_id' => $revision->actorId,
            'snapshot' => RecordValueGuard::canonical($revision->snapshot()),
            'checksum' => $revision->checksum(),
            'changed_fields' => $revision->changedFields(),
            'created_at' => $revision->occurredAt,
        ], [
            'id' => Types::GUID,
            'definition_id' => Types::GUID,
            'definition_version' => Types::INTEGER,
            'record_version' => Types::INTEGER,
            'revision_number' => Types::INTEGER,
            'snapshot' => Types::JSON,
            'changed_fields' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function history(
        string $definitionId,
        string $recordKey,
        int $limit,
        ?int $beforeVersion = null,
    ): array {
        if ($limit < 1 || $limit > 201) {
            throw new InvalidArgumentException('A revision repository window must contain 1 to 201 rows.');
        }
        $parameters = [$definitionId, $recordKey];
        $types = [Types::GUID, Types::GUID];
        $version = '';
        if ($beforeVersion !== null) {
            $version = ' AND record_version < ?';
            $parameters[] = $beforeVersion;
            $types[] = Types::INTEGER;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE definition_id = ? AND record_id = ?%s '
            . 'ORDER BY record_version DESC, revision_number DESC LIMIT %d',
            $this->tables->quoted('business_record_revisions'),
            $version,
            $limit,
        ), $parameters, $types);

        return array_map($this->map(...), $rows);
    }

    public function historyByIdentityDigest(
        string $definitionId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        string $recordIdentityDigest,
        int $limit,
        ?int $beforeVersion = null,
    ): array {
        if ($limit < 1 || $limit > 201 || preg_match('/^[a-f0-9]{64}$/D', $recordIdentityDigest) !== 1) {
            throw new InvalidArgumentException('A revision identity window is invalid.');
        }
        $where = ['definition_id = ?', 'site_identifier = ?', 'record_identity_digest = ?'];
        $parameters = [$definitionId, $siteIdentifier, $recordIdentityDigest];
        $types = [Types::GUID, Types::STRING, Types::STRING];
        if ($organizationIdentifier === null) {
            $where[] = 'organization_identifier IS NULL';
        } else {
            $where[] = 'organization_identifier = ?';
            $parameters[] = $organizationIdentifier;
            $types[] = Types::STRING;
        }
        if ($beforeVersion !== null) {
            $where[] = 'record_version < ?';
            $parameters[] = $beforeVersion;
            $types[] = Types::INTEGER;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY record_version DESC, revision_number DESC LIMIT %d',
            $this->tables->quoted('business_record_revisions'),
            implode(' AND ', $where),
            $limit,
        ), $parameters, $types);

        return array_map($this->map(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): BusinessRecordRevision
    {
        $snapshot = $this->jsonObject($row['snapshot'] ?? null);
        $changed = $this->jsonList($row['changed_fields'] ?? null);
        $revision = new BusinessRecordRevision(
            $this->string($row, 'id'),
            $this->string($row, 'definition_id'),
            $this->integer($row, 'definition_version'),
            $this->string($row, 'site_identifier'),
            $this->nullableString($row, 'organization_identifier'),
            $this->string($row, 'record_id'),
            $this->string($row, 'record_identity_digest'),
            $this->integer($row, 'record_version'),
            $this->integer($row, 'revision_number'),
            $this->string($row, 'action'),
            $snapshot,
            $changed,
            $this->string($row, 'actor_id'),
            $this->date($row['created_at'] ?? null),
        );
        if (!hash_equals($this->string($row, 'checksum'), $revision->checksum())) {
            throw new BusinessRecordSchemaUnavailable('A business-record revision failed integrity verification.');
        }

        return $revision;
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        $decoded = $this->json($value);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision snapshot is malformed.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = $this->json($value);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new BusinessRecordSchemaUnavailable('Stored revision changed fields are malformed.');
        }
        $result = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new BusinessRecordSchemaUnavailable('Stored revision changed fields contain a non-string.');
            }
            $result[] = $item;
        }

        return $result;
    }

    private function json(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        try {
            return json_decode($value, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BusinessRecordSchemaUnavailable('Stored revision JSON is invalid.');
        }
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision string is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision value is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision integer is invalid.');
        }

        return (int) $value;
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
        throw new BusinessRecordSchemaUnavailable('A stored business-record revision timestamp is invalid.');
    }

    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Business-record revisions require an active application transaction.');
        }
    }
}
