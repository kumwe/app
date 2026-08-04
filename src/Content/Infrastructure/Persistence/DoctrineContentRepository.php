<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentRevision;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Content\Domain\VersionConflict;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineContentRepository implements ContentRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function all(int $limit = 100, bool $includeDeleted = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The content result limit must be between 1 and 500.');
        }

        $query = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->orderBy('e.updated_at', 'DESC')
            ->setMaxResults($limit);

        if (!$includeDeleted) {
            $query->where('e.deleted_at IS NULL');
        }

        return array_map($this->map(...), $query->executeQuery()->fetchAllAssociative());
    }

    public function find(string $id, bool $includeDeleted = false): ?ContentRecord
    {
        $query = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if (!$includeDeleted) {
            $query->andWhere('e.deleted_at IS NULL');
        }

        $row = $query->executeQuery()->fetchAssociative();

        return $row === false ? null : $this->map($row);
    }

    public function findPublishedBySlug(string $slug, DateTimeImmutable $time): ?ContentRecord
    {
        $query = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->where('e.slug = :slug')
            ->andWhere("e.workflow_state_key = 'published'")
            ->andWhere('e.deleted_at IS NULL')
            ->andWhere('(e.publish_at IS NULL OR e.publish_at <= :visible_at)')
            ->andWhere('(e.unpublish_at IS NULL OR e.unpublish_at > :visible_at)')
            ->setParameter('slug', $slug)
            ->setParameter('visible_at', $time, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1);
        $row = $query->executeQuery()->fetchAssociative();

        return $row === false ? null : $this->map($row);
    }

    public function insert(ContentRecord $record): void
    {
        $entry = $record->entry;
        $this->database->insert($this->tables->raw('content_entries'), [
            'id' => $entry->id(),
            'content_type_id' => $record->contentTypeId,
            'workflow_id' => $record->workflowId,
            'workflow_state_key' => $entry->status()->value,
            'title' => $entry->title(),
            'slug' => $entry->slug(),
            'data' => $entry->data(),
            'publish_at' => $entry->publicationWindow()->startsAt(),
            'unpublish_at' => $entry->publicationWindow()->endsAt(),
            'version' => $entry->version(),
            'created_at' => $record->createdAt,
            'updated_at' => $record->updatedAt,
            'deleted_at' => $record->deletedAt,
        ], $this->writeTypes());
    }

    public function update(ContentRecord $record, int $expectedVersion): void
    {
        $entry = $record->entry;
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET workflow_state_key = ?, title = ?, slug = ?, data = ?, publish_at = ?, '
            . 'unpublish_at = ?, version = ?, updated_at = ? WHERE id = ? AND version = ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [
            $entry->status()->value,
            $entry->title(),
            $entry->slug(),
            $entry->data(),
            $entry->publicationWindow()->startsAt(),
            $entry->publicationWindow()->endsAt(),
            $entry->version(),
            $record->updatedAt,
            $entry->id(),
            $expectedVersion,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::JSON,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertUpdated($affected, $expectedVersion, $entry->id());
    }

    public function setDeletedAt(
        string $id,
        int $expectedVersion,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $updatedAt,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET deleted_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?',
            $this->tables->quoted('content_entries'),
        ), [$deletedAt, $updatedAt, $id, $expectedVersion], [
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertUpdated($affected, $expectedVersion, $id);
    }

    public function appendRevision(ContentRevision $revision): void
    {
        $this->database->insert($this->tables->raw('content_revisions'), [
            'id' => $revision->id(),
            'content_entry_id' => $revision->contentEntryId(),
            'revision_number' => $revision->revisionNumber(),
            'snapshot' => $revision->snapshot(),
            'checksum' => $revision->checksum(),
            'created_at' => $revision->createdAt(),
        ], [
            'snapshot' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function nextRevisionNumber(string $contentEntryId): int
    {
        $result = $this->database->fetchOne(sprintf(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM %s WHERE content_entry_id = ?',
            $this->tables->quoted('content_revisions'),
        ), [$contentEntryId]);

        if (!is_int($result) && (!is_string($result) || preg_match('/^[0-9]+$/D', $result) !== 1)) {
            throw new RuntimeException('The next content revision number is invalid.');
        }

        return (int) $result;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'e.id', 'e.content_type_id', 'e.workflow_id', 'e.workflow_state_key', 'e.title', 'e.slug',
            'e.data', 'e.publish_at', 'e.unpublish_at', 'e.version', 'e.created_at', 'e.updated_at', 'e.deleted_at',
        ];
    }

    /** @return array<string, string> */
    private function writeTypes(): array
    {
        return [
            'data' => Types::JSON,
            'publish_at' => Types::DATETIME_IMMUTABLE,
            'unpublish_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ContentRecord
    {
        try {
            $data = is_string($row['data'] ?? null)
                ? json_decode($row['data'], true, 64, JSON_THROW_ON_ERROR)
                : $row['data'];
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored content JSON is invalid.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Stored content data must be a JSON object.');
        }

        $window = new PublicationWindow(
            $this->nullableDateTime($row['publish_at'] ?? null),
            $this->nullableDateTime($row['unpublish_at'] ?? null),
        );
        $entry = ContentEntry::reconstitute(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'title'),
            $this->requiredString($row, 'slug'),
            $data,
            ContentStatus::from($this->requiredString($row, 'workflow_state_key')),
            $window,
            $this->integer($row, 'version'),
        );

        return new ContentRecord(
            $entry,
            $this->requiredString($row, 'content_type_id'),
            $this->requiredString($row, 'workflow_id'),
            $this->dateTime($row['created_at'] ?? null),
            $this->dateTime($row['updated_at'] ?? null),
            $this->nullableDateTime($row['deleted_at'] ?? null),
        );
    }

    private function assertUpdated(int $affected, int $expectedVersion, string $id): void
    {
        if ($affected !== 1) {
            throw new VersionConflict($expectedVersion, $this->find($id, true)?->entry->version() ?? 0);
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored content field %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Stored content field %s is not an integer.', $key));
        }

        return (int) $value;
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored content timestamp is invalid.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : $this->dateTime($value);
    }
}
