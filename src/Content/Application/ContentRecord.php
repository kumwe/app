<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Content\Domain\ContentEntry;

final readonly class ContentRecord
{
    public function __construct(
        public ContentEntry $entry,
        public string $contentTypeId,
        public string $workflowId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
    }

    public function withEntry(ContentEntry $entry, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $entry,
            $this->contentTypeId,
            $this->workflowId,
            $this->createdAt,
            $updatedAt,
            $this->deletedAt,
        );
    }

    public function withDeletedAt(?DateTimeImmutable $deletedAt, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->entry,
            $this->contentTypeId,
            $this->workflowId,
            $this->createdAt,
            $updatedAt,
            $deletedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->entry->snapshot(),
            'content_type_id' => $this->contentTypeId,
            'workflow_id' => $this->workflowId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'deleted_at' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
