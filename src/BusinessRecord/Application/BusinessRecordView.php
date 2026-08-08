<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;

final readonly class BusinessRecordView
{
    /** @var array<string, mixed> */
    public array $values;

    /** @var array<string, list<BusinessRecordRelationView>> */
    public array $includes;

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<BusinessRecordRelationView>> $includes
     */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public int $version,
        public ?string $siteIdentifier,
        public ?string $organizationIdentifier,
        public ?string $workflowState,
        array $values,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
        public ?string $archivedBy,
        public ?DateTimeImmutable $archivedAt,
        public ?string $deletedBy,
        public ?DateTimeImmutable $deletedAt,
        array $includes = [],
    ) {
        $this->values = $values;
        $this->includes = $includes;
    }

    /** @param list<string> $projection @param array<string, mixed>|null $resolvedValues */
    public static function fromRecord(
        BusinessRecord $record,
        array $projection = [],
        ?EntityTypeDefinition $definition = null,
        ?array $resolvedValues = null,
    ): self {
        $values = $resolvedValues ?? $record->values();
        if ($definition !== null) {
            $visible = [];
            foreach ($definition->fields() as $field) {
                if ($field->readVisible) {
                    $visible[$field->handle] = true;
                }
            }
            $values = array_intersect_key($values, $visible);
        }
        if ($projection !== []) {
            $values = array_intersect_key($values, array_fill_keys($projection, true));
        }
        foreach ($definition?->fields() ?? [] as $field) {
            if (
                $resolvedValues === null && $field->type === 'core.entity_reference'
                && array_key_exists($field->handle, $values)
            ) {
                $values[$field->handle] = ['redacted' => true];
            }
            if (
                array_key_exists($field->handle, $values)
                && in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                $values[$field->handle] = ['redacted' => true];
            }
        }

        return new self(
            $record->definitionId,
            $record->definitionVersion,
            $record->recordKey,
            $record->recordId,
            $record->version,
            $record->scope->siteIdentifier,
            $record->scope->organizationIdentifier,
            $record->workflowState,
            $values,
            $record->createdBy,
            $record->createdAt,
            $record->updatedBy,
            $record->updatedAt,
            $record->archivedBy,
            $record->archivedAt,
            $record->deletedBy,
            $record->deletedAt,
        );
    }

    /** @param array<string, list<BusinessRecordRelationView>> $includes */
    public function withIncludes(array $includes): self
    {
        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->version,
            $this->siteIdentifier,
            $this->organizationIdentifier,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $this->updatedBy,
            $this->updatedAt,
            $this->archivedBy,
            $this->archivedAt,
            $this->deletedBy,
            $this->deletedAt,
            $includes,
        );
    }
}
