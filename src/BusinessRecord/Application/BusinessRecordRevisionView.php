<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;

/** A disclosure-safe view over an integrity-verified immutable revision. */
final readonly class BusinessRecordRevisionView
{
    /** @var array<string, mixed> */
    public array $snapshot;

    /** @var list<string> */
    public array $changedFields;

    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $revisionId,
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public int $recordVersion,
        public int $revisionNumber,
        public string $operation,
        array $snapshot,
        array $changedFields,
        public string $actorId,
        public DateTimeImmutable $occurredAt,
        public string $integrityChecksum,
    ) {
        $this->snapshot = $snapshot;
        $this->changedFields = array_values($changedFields);
    }

    public static function fromRevision(
        BusinessRecordRevision $revision,
        EntityTypeDefinition $definition,
    ): self {
        $sensitive = [];
        foreach ($definition->fields() as $field) {
            if (
                $field->type === 'core.entity_reference'
                || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                $sensitive[$field->handle] = true;
            }
        }
        $snapshot = $revision->snapshot();
        foreach ($snapshot as $handle => $_value) {
            if (isset($sensitive[$handle])) {
                $snapshot[$handle] = ['redacted' => true];
            }
        }

        return new self(
            $revision->revisionId,
            $revision->definitionId,
            $revision->definitionVersion,
            $revision->recordKey,
            $revision->recordVersion,
            $revision->revisionNumber,
            $revision->operation,
            $snapshot,
            $revision->changedFields(),
            $revision->actorId,
            $revision->occurredAt,
            $revision->checksum(),
        );
    }
}
