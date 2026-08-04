<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use JsonException;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentRevision;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Content\Domain\VersionConflict;
use RuntimeException;

final readonly class PostgreSqlContentRepository implements ContentRepository
{
    public function __construct(private DatabaseInterface $database, private string $schema)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function all(int $limit = 100, bool $includeDeleted = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The content result limit must be between 1 and 500.');
        }

        $query = $this->baseSelect()->order($this->quoteName('e.updated_at') . ' DESC');

        if (!$includeDeleted) {
            $query->where($this->quoteName('e.deleted_at') . ' IS NULL');
        }

        $rows = $this->database->setQuery($query, 0, $limit)->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The content query returned an invalid result set.');
        }

        return array_map(fn (array $row): ContentRecord => $this->map($row), $rows);
    }

    public function find(string $id, bool $includeDeleted = false): ?ContentRecord
    {
        $query = $this->baseSelect()
            ->where($this->quoteName('e.id') . ' = :id')
            ->bind(':id', $id, ParameterType::STRING);

        if (!$includeDeleted) {
            $query->where($this->quoteName('e.deleted_at') . ' IS NULL');
        }

        $row = $this->database->setQuery($query)->loadAssoc();

        return is_array($row) ? $this->map($row) : null;
    }

    public function findPublishedBySlug(string $slug, DateTimeImmutable $time): ?ContentRecord
    {
        $timestamp = $this->timestamp($time);
        $query = $this->baseSelect()
            ->where($this->quoteName('e.slug') . ' = :slug')
            ->where($this->quoteName('e.workflow_state_key') . " = 'published'")
            ->where($this->quoteName('e.deleted_at') . ' IS NULL')
            ->where('(' . $this->quoteName('e.publish_at') . ' IS NULL OR '
                . $this->quoteName('e.publish_at') . ' <= :visible_at)')
            ->where('(' . $this->quoteName('e.unpublish_at') . ' IS NULL OR '
                . $this->quoteName('e.unpublish_at') . ' > :visible_until)')
            ->bind(':slug', $slug, ParameterType::STRING)
            ->bind(':visible_at', $timestamp, ParameterType::STRING)
            ->bind(':visible_until', $timestamp, ParameterType::STRING);
        $row = $this->database->setQuery($query)->loadAssoc();

        return is_array($row) ? $this->map($row) : null;
    }

    public function insert(ContentRecord $record): void
    {
        $entry = $record->entry;
        $data = json_encode($entry->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $publishAt = $entry->publicationWindow()->startsAt();
        $unpublishAt = $entry->publicationWindow()->endsAt();
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.content_entries'))
            ->columns($this->quoteNames([
                'id',
                'content_type_id',
                'workflow_id',
                'workflow_state_key',
                'title',
                'slug',
                'data',
                'publish_at',
                'unpublish_at',
                'version',
                'created_at',
                'updated_at',
                'deleted_at',
            ]))
            ->values(implode(', ', [
                ':id',
                ':content_type_id',
                ':workflow_id',
                ':workflow_state_key',
                ':title',
                ':slug',
                'CAST(:data AS jsonb)',
                ':publish_at',
                ':unpublish_at',
                ':version',
                ':created_at',
                ':updated_at',
                ':deleted_at',
            ]))
            ->bind(':id', $entry->id(), ParameterType::STRING)
            ->bind(':content_type_id', $record->contentTypeId, ParameterType::STRING)
            ->bind(':workflow_id', $record->workflowId, ParameterType::STRING)
            ->bind(':workflow_state_key', $entry->status()->value, ParameterType::STRING)
            ->bind(':title', $entry->title(), ParameterType::STRING)
            ->bind(':slug', $entry->slug(), ParameterType::STRING)
            ->bind(':data', $data, ParameterType::STRING)
            ->bind(':publish_at', $this->nullableTimestamp($publishAt), $this->nullableType($publishAt))
            ->bind(':unpublish_at', $this->nullableTimestamp($unpublishAt), $this->nullableType($unpublishAt))
            ->bind(':version', $entry->version(), ParameterType::INTEGER)
            ->bind(':created_at', $this->timestamp($record->createdAt), ParameterType::STRING)
            ->bind(':updated_at', $this->timestamp($record->updatedAt), ParameterType::STRING)
            ->bind(
                ':deleted_at',
                $this->nullableTimestamp($record->deletedAt),
                $this->nullableType($record->deletedAt),
            );

        $this->database->setQuery($query)->execute();
    }

    public function update(ContentRecord $record, int $expectedVersion): void
    {
        $entry = $record->entry;
        $data = json_encode($entry->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $publishAt = $entry->publicationWindow()->startsAt();
        $unpublishAt = $entry->publicationWindow()->endsAt();
        $query = $this->database->getQuery(true)
            ->update($this->quoteName($this->schema . '.content_entries'))
            ->set($this->quoteName('workflow_state_key') . ' = :workflow_state_key')
            ->set($this->quoteName('title') . ' = :title')
            ->set($this->quoteName('slug') . ' = :slug')
            ->set($this->quoteName('data') . ' = CAST(:data AS jsonb)')
            ->set($this->quoteName('publish_at') . ' = :publish_at')
            ->set($this->quoteName('unpublish_at') . ' = :unpublish_at')
            ->set($this->quoteName('version') . ' = :new_version')
            ->set($this->quoteName('updated_at') . ' = :updated_at')
            ->where($this->quoteName('id') . ' = :id')
            ->where($this->quoteName('version') . ' = :expected_version')
            ->where($this->quoteName('deleted_at') . ' IS NULL')
            ->bind(':workflow_state_key', $entry->status()->value, ParameterType::STRING)
            ->bind(':title', $entry->title(), ParameterType::STRING)
            ->bind(':slug', $entry->slug(), ParameterType::STRING)
            ->bind(':data', $data, ParameterType::STRING)
            ->bind(':publish_at', $this->nullableTimestamp($publishAt), $this->nullableType($publishAt))
            ->bind(':unpublish_at', $this->nullableTimestamp($unpublishAt), $this->nullableType($unpublishAt))
            ->bind(':new_version', $entry->version(), ParameterType::INTEGER)
            ->bind(':updated_at', $this->timestamp($record->updatedAt), ParameterType::STRING)
            ->bind(':id', $entry->id(), ParameterType::STRING)
            ->bind(':expected_version', $expectedVersion, ParameterType::INTEGER);

        $this->database->setQuery($query)->execute();
        $this->assertUpdated($expectedVersion, $entry->id());
    }

    public function setDeletedAt(
        string $id,
        int $expectedVersion,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $updatedAt,
    ): void {
        $query = $this->database->getQuery(true)
            ->update($this->quoteName($this->schema . '.content_entries'))
            ->set($this->quoteName('deleted_at') . ' = :deleted_at')
            ->set($this->quoteName('updated_at') . ' = :updated_at')
            ->set($this->quoteName('version') . ' = ' . $this->quoteName('version') . ' + 1')
            ->where($this->quoteName('id') . ' = :id')
            ->where($this->quoteName('version') . ' = :expected_version')
            ->bind(':deleted_at', $this->nullableTimestamp($deletedAt), $this->nullableType($deletedAt))
            ->bind(':updated_at', $this->timestamp($updatedAt), ParameterType::STRING)
            ->bind(':id', $id, ParameterType::STRING)
            ->bind(':expected_version', $expectedVersion, ParameterType::INTEGER);

        $this->database->setQuery($query)->execute();
        $this->assertUpdated($expectedVersion, $id);
    }

    public function appendRevision(ContentRevision $revision): void
    {
        $snapshot = json_encode(
            $revision->snapshot(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.content_revisions'))
            ->columns($this->quoteNames([
                'id',
                'content_entry_id',
                'revision_number',
                'snapshot',
                'checksum',
                'created_at',
            ]))
            ->values(':id, :content_entry_id, :revision_number, CAST(:snapshot AS jsonb), :checksum, :created_at')
            ->bind(':id', $revision->id(), ParameterType::STRING)
            ->bind(':content_entry_id', $revision->contentEntryId(), ParameterType::STRING)
            ->bind(':revision_number', $revision->revisionNumber(), ParameterType::INTEGER)
            ->bind(':snapshot', $snapshot, ParameterType::STRING)
            ->bind(':checksum', $revision->checksum(), ParameterType::STRING)
            ->bind(':created_at', $this->timestamp($revision->createdAt()), ParameterType::STRING);

        $this->database->setQuery($query)->execute();
    }

    public function nextRevisionNumber(string $contentEntryId): int
    {
        $query = $this->database->getQuery(true)
            ->select('COALESCE(MAX(' . $this->quoteName('revision_number') . '), 0) + 1')
            ->from($this->quoteName($this->schema . '.content_revisions'))
            ->where($this->quoteName('content_entry_id') . ' = :content_entry_id')
            ->bind(':content_entry_id', $contentEntryId, ParameterType::STRING);

        return (int) $this->database->setQuery($query)->loadResult();
    }

    private function baseSelect(): \Joomla\Database\QueryInterface
    {
        return $this->database->getQuery(true)
            ->select($this->quoteNames([
                'e.id',
                'e.content_type_id',
                'e.workflow_id',
                'e.workflow_state_key',
                'e.title',
                'e.slug',
                'e.data',
                'e.publish_at',
                'e.unpublish_at',
                'e.version',
                'e.created_at',
                'e.updated_at',
                'e.deleted_at',
            ]))
            ->from($this->quoteName($this->schema . '.content_entries', 'e'));
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

        if (!is_array($data)) {
            throw new RuntimeException('Stored content data must be a JSON object.');
        }

        $id = $this->requiredString($row, 'id');
        $status = ContentStatus::from($this->requiredString($row, 'workflow_state_key'));
        $window = new PublicationWindow(
            $this->nullableDateTime($row['publish_at'] ?? null),
            $this->nullableDateTime($row['unpublish_at'] ?? null),
        );
        $entry = ContentEntry::reconstitute(
            $id,
            $this->requiredString($row, 'title'),
            $this->requiredString($row, 'slug'),
            $data,
            $status,
            $window,
            (int) ($row['version'] ?? 0),
        );

        return new ContentRecord(
            $entry,
            $this->requiredString($row, 'content_type_id'),
            $this->requiredString($row, 'workflow_id'),
            $this->dateTime($this->requiredString($row, 'created_at')),
            $this->dateTime($this->requiredString($row, 'updated_at')),
            $this->nullableDateTime($row['deleted_at'] ?? null),
        );
    }

    private function assertUpdated(int $expectedVersion, string $id): void
    {
        if ($this->database->getAffectedRows() !== 1) {
            $current = $this->find($id, true);
            throw new VersionConflict($expectedVersion, $current?->entry->version() ?? 0);
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

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? $this->dateTime($value) : null;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function nullableTimestamp(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->timestamp($value);
    }

    private function nullableType(?DateTimeImmutable $value): string
    {
        return $value === null ? ParameterType::NULL : ParameterType::STRING;
    }

    /** @param list<string> $names @return list<string> */
    private function quoteNames(array $names): array
    {
        return array_map(fn (string $name): string => $this->quoteName($name), $names);
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
