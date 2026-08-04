<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class ContentRevision
{
    /**
     * @param array<string, mixed> $snapshot
     */
    private function __construct(
        private string $id,
        private string $contentEntryId,
        private int $revisionNumber,
        private array $snapshot,
        private string $checksum,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @throws JsonException
     */
    public static function capture(
        string $id,
        ContentEntry $entry,
        int $revisionNumber,
        DateTimeImmutable $createdAt,
    ): self {
        self::assertUuid($id);

        if ($revisionNumber < 1) {
            throw new InvalidArgumentException('A revision number must be at least one.');
        }

        $snapshot = $entry->snapshot();

        return new self(
            strtolower($id),
            $entry->id(),
            $revisionNumber,
            $snapshot,
            self::checksumFor($snapshot),
            $createdAt,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function contentEntryId(): string
    {
        return $this->contentEntryId;
    }

    public function revisionNumber(): int
    {
        return $this->revisionNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @throws JsonException
     */
    public function hasValidChecksum(): bool
    {
        return hash_equals($this->checksum, self::checksumFor($this->snapshot));
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws JsonException
     */
    private static function checksumFor(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode(
                self::canonicalize($snapshot),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A content revision ID must be a canonical UUID.');
        }
    }
}
