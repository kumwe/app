<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Outcome of one business-record write, and the exact payload the idempotency ledger stores for it.
 *
 * A mutation has to be describable twice: once to the caller that performed it, and again to a caller
 * that repeats the same command under the same idempotency key and must be told what already happened
 * rather than having it happen again. This value object is both. `toArray()` is what the ledger persists
 * and checksums and `fromArray()` is how a replay rebuilds it, with `replayed` telling the two apart on
 * the way out. Every field is re-validated in the constructor, so a ledger row that was truncated or
 * tampered with fails to rebuild instead of being handed on as a plausible result.
 *
 * @since  2.0.0
 */
final readonly class RecordMutationResult
{
    /**
     * Capture the identity a completed mutation is reported and replayed under.
     *
     * @param   string   $definitionId       UUID of the business definition the record belongs to.
     * @param   int      $definitionVersion  Definition version the write was pinned to; at least 1.
     * @param   string   $recordKey          Internal UUID the row is stored under, which its revisions and
     *          relationships are keyed by.
     * @param   string   $recordId           Caller-facing identity of the record; at most 191 bytes and free
     *          of control characters.
     * @param   int      $version            Record version after this mutation, for the caller to send back
     *          as its expected version next time; at least 1.
     * @param   ?string  $workflowState      Workflow state the record now sits in, or null when its
     *          definition binds no workflow.
     * @param   string   $operation          What was performed, as the service names it — `create`,
     *          `update`, `archive`, `reorder` and the like.
     * @param   bool     $deleted            Whether the record no longer exists as a live row afterwards.
     * @param   bool     $replayed           Whether this describes a mutation replayed from the ledger
     *          rather than one applied by the call that returned it.
     *
     * @throws  InvalidArgumentException  When the definition id or record key is not a UUID, the record id
     *          is empty, over 191 bytes or carries a control character, either version is below 1, or the
     *          operation is not a lowercase name of at most 63 characters built from letters, digits, dots,
     *          dashes and underscores.
     *
     * @since   2.0.0
     */
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

    /**
     * Copy this result with the replay flag raised, for a caller being told about an earlier mutation.
     *
     * @return  self  The same mutation metadata with `replayed` true; no other field changes.
     *
     * @since   2.0.0
     */
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

    /**
     * Export the mutation in the shape the idempotency ledger stores and checksums.
     *
     * @return  array<string, int|string|bool|null>  The identity and outcome fields under their snake_case
     *          column names. `replayed` is left out deliberately: it describes how a result reached a
     *          caller, not what the mutation did, so omitting it keeps the checksum equal for the original
     *          write and every replay of it.
     *
     * @since   2.0.0
     */
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

    /**
     * Rebuild a stored result from a ledger row, already marked as a replay.
     *
     * Only the idempotency path reaches this, and it only reaches it about a mutation that has already
     * happened, so the rebuilt result always carries `replayed` true. Every entry is type checked before
     * the constructor sees it, which is what turns a row that lost a field or had one rewritten into a
     * refusal rather than a result the caller would act on.
     *
     * @param   array<string, mixed>  $data  Decoded ledger payload, in the shape `toArray()` writes.
     *
     * @return  self  The stored mutation, flagged as replayed.
     *
     * @throws  InvalidArgumentException  When an entry is absent or of the wrong type, or when the rebuilt
     *          values fail the constructor's identity and operation checks.
     *
     * @since   2.0.0
     */
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
