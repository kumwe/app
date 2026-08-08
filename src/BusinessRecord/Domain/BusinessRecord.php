<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class BusinessRecord
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public RecordScope $scope,
        public int $version,
        public ?string $workflowState,
        array $values,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
        public ?string $archivedBy = null,
        public ?DateTimeImmutable $archivedAt = null,
        public ?string $deletedBy = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        if (!Uuid::isValid($definitionId) || !Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('Business-record definition and internal record keys must be UUIDs.');
        }
        if ($recordId === '' || strlen($recordId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $recordId) === 1) {
            throw new InvalidArgumentException('A business-record identity is invalid.');
        }
        if ($definitionVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Business record definition and optimistic versions must be positive.');
        }
        if ($workflowState !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $workflowState) !== 1) {
            throw new InvalidArgumentException('A business-record workflow state is invalid.');
        }
        if (($archivedBy === null) !== ($archivedAt === null) || ($deletedBy === null) !== ($deletedAt === null)) {
            throw new InvalidArgumentException('Business-record lifecycle actor and timestamp pairs are inconsistent.');
        }
        self::assertActor($createdBy);
        self::assertActor($updatedBy);
        if ($archivedBy !== null) {
            self::assertActor($archivedBy);
        }
        if ($deletedBy !== null) {
            self::assertActor($deletedBy);
        }
        if (count($values) > 256) {
            throw new InvalidArgumentException('A business record contains too many fields.');
        }
        foreach ($values as $handle => $value) {
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business record contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
        ksort($values, SORT_STRING);
        $this->values = $values;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function value(string $handle): mixed
    {
        if (!array_key_exists($handle, $this->values)) {
            throw new InvalidArgumentException('The business record field is unavailable.');
        }

        return $this->values[$handle];
    }

    /** @param array<string, mixed> $values */
    public function updated(array $values, string $actor, DateTimeImmutable $now): self
    {
        return $this->copy($values, $actor, $now, $this->workflowState, $this->archivedBy, $this->archivedAt);
    }

    public function transitioned(string $state, string $actor, DateTimeImmutable $now): self
    {
        return $this->copy($this->values, $actor, $now, $state, $this->archivedBy, $this->archivedAt);
    }

    public function archived(string $actor, DateTimeImmutable $now): self
    {
        if ($this->archivedAt !== null) {
            throw new InvalidArgumentException('The business record is already archived.');
        }

        return $this->copy($this->values, $actor, $now, $this->workflowState, $actor, $now);
    }

    public function restored(string $actor, DateTimeImmutable $now): self
    {
        if ($this->archivedAt === null && $this->deletedAt === null) {
            throw new InvalidArgumentException('The business record is not archived or deleted.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
        );
    }

    public function softDeleted(string $actor, DateTimeImmutable $now): self
    {
        if ($this->deletedAt !== null) {
            throw new InvalidArgumentException('The business record is already deleted.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
            $this->archivedBy,
            $this->archivedAt,
            $actor,
            $now,
        );
    }

    /** @param array<string, mixed> $values */
    private function copy(
        array $values,
        string $actor,
        DateTimeImmutable $now,
        ?string $workflowState,
        ?string $archivedBy,
        ?DateTimeImmutable $archivedAt,
    ): self {
        if ($this->deletedAt !== null) {
            throw new InvalidArgumentException('A deleted business record must be restored before mutation.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $workflowState,
            $values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
            $archivedBy,
            $archivedAt,
            $this->deletedBy,
            $this->deletedAt,
        );
    }

    private static function assertActor(string $actor): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $actor) !== 1) {
            throw new InvalidArgumentException('A business-record actor identifier is invalid.');
        }
    }
}
