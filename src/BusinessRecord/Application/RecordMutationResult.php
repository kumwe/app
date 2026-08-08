<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class RecordMutationResult
{
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public int $version,
        public ?string $workflowState,
        public string $operation,
        public bool $deleted = false,
        public bool $replayed = false,
    ) {
        if (
            !Uuid::isValid($definitionId)
            || !Uuid::isValid($recordKey)
            || $recordId === ''
            || strlen($recordId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $recordId) === 1
            || $definitionVersion < 1
            || $version < 1
        ) {
            throw new InvalidArgumentException('A business-record mutation result has invalid identity metadata.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $operation) !== 1) {
            throw new InvalidArgumentException('A business-record mutation result operation is invalid.');
        }
    }

    public function asReplay(): self
    {
        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->version,
            $this->workflowState,
            $this->operation,
            $this->deleted,
            true,
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'definition_version' => $this->definitionVersion,
            'record_key' => $this->recordKey,
            'record_id' => $this->recordId,
            'version' => $this->version,
            'workflow_state' => $this->workflowState,
            'operation' => $this->operation,
            'deleted' => $this->deleted,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $definitionId = $data['definition_id'] ?? null;
        $definitionVersion = $data['definition_version'] ?? null;
        $recordId = $data['record_id'] ?? null;
        $recordKey = $data['record_key'] ?? null;
        $version = $data['version'] ?? null;
        $workflowState = $data['workflow_state'] ?? null;
        $operation = $data['operation'] ?? null;
        $deleted = $data['deleted'] ?? null;
        if (
            !is_string($definitionId) || !is_int($definitionVersion) || !is_string($recordKey)
            || !is_string($recordId)
            || !is_int($version) || ($workflowState !== null && !is_string($workflowState))
            || !is_string($operation) || !is_bool($deleted)
        ) {
            throw new InvalidArgumentException('A stored business-record mutation result is malformed.');
        }

        return new self(
            $definitionId,
            $definitionVersion,
            $recordKey,
            $recordId,
            $version,
            $workflowState,
            $operation,
            $deleted,
            true,
        );
    }
}
