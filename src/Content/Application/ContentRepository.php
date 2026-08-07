<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Content\Domain\ContentRevision;

interface ContentRepository
{
    /** @return list<ContentRecord> */
    public function all(int $limit = 100, bool $includeDeleted = false, int $offset = 0): array;

    public function find(string $id, bool $includeDeleted = false): ?ContentRecord;

    public function findPublishedById(string $id, DateTimeImmutable $time): ?ContentRecord;

    public function findPublishedBySlug(string $slug, DateTimeImmutable $time): ?ContentRecord;

    public function insert(ContentRecord $record): void;

    public function update(ContentRecord $record, int $expectedVersion): void;

    public function setDeletedAt(
        string $id,
        int $expectedVersion,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $updatedAt,
    ): void;

    public function appendRevision(ContentRevision $revision): void;

    public function nextRevisionNumber(string $contentEntryId): int;
}
