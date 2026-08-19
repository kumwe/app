<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Ramsey\Uuid\Uuid;

/**
 * Tagged, bounded custom-action result stored in the canonical business idempotency ledger.
 *
 * Private definition and contract references let replay and operation-status reads prove the exact active
 * tuple again. `publicResult()` omits every one of those references and returns the same mutation envelope
 * delivered synchronously, including only contract-validated custom data.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessActionLedgerResult
{
    /**
     * Stable discriminator separating custom results from canonical record and approval outcomes.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string KIND = 'custom_business_action_v1';

    /**
     * Contract-shaped result data retained for exact replay.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $data;

    /**
     * Validate one complete stored custom-action outcome.
     *
     * @param   string                $definitionId        Internal immutable definition UUID.
     * @param   int                   $definitionVersion   Published definition version executed.
     * @param   string                $definitionChecksum  Exact published definition checksum.
     * @param   int                   $runtimeGeneration   Trusted extension publication generation.
     * @param   string                $runtimeChecksum     Trusted runtime publication checksum.
     * @param   string                $handler             Owner-scoped handler reference executed.
     * @param   string                $schema              Owner-scoped schema reference validated.
     * @param   string                $recordId            Caller-facing record identity.
     * @param   int                   $recordVersion       Resulting positive optimistic version.
     * @param   ?string               $workflowState       Resulting workflow state, when applicable.
     * @param   string                $action              Definition-local custom action handle.
     * @param   bool                  $deleted             Whether the result marks the record deleted.
     * @param   bool                  $originallyReplayed  Whether the nested handler itself replayed work.
     * @param   array<string, mixed>  $data                Contract-validated caller-visible result.
     *
     * @throws  InvalidArgumentException  When identity, version, references, checksum, state, or data is unsafe.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $definitionChecksum,
        public int $runtimeGeneration,
        public string $runtimeChecksum,
        public string $handler,
        public string $schema,
        public string $recordId,
        public int $recordVersion,
        public ?string $workflowState,
        public string $action,
        public bool $deleted,
        public bool $originallyReplayed,
        array $data,
    ) {
        if (!Uuid::isValid($definitionId) || $definitionVersion < 1 || $recordVersion < 1) {
            throw new InvalidArgumentException('A custom business action ledger identity is invalid.');
        }
        if (
            $runtimeGeneration < 0
            || preg_match('/^[a-f0-9]{64}$/D', $definitionChecksum) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $runtimeChecksum) !== 1
        ) {
            throw new InvalidArgumentException('A custom business action generation binding is invalid.');
        }
        CustomBusinessReference::assert($handler, 'action handler');
        CustomBusinessReference::assert($schema, 'action schema');
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::handle($action, 'action');
        if ($workflowState !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $workflowState) !== 1) {
            throw new InvalidArgumentException('A custom business action workflow state is invalid.');
        }
        CustomBusinessPayload::assertObject($data, 'action ledger result');
        $this->data = $data;
    }

    /**
     * Capture a freshly validated handler result under its immutable definition and action references.
     *
     * @param   EntityTypeDefinition        $definition         Installed definition executed under the fence.
     * @param   ActionDefinition            $action             Exact custom action declaration.
     * @param   string                      $recordId           Caller-facing target identity.
     * @param   int                         $runtimeGeneration  Trusted extension runtime generation.
     * @param   string                      $runtimeChecksum    Trusted runtime publication checksum.
     * @param   CustomBusinessActionResult  $result             Registry-validated handler outcome.
     *
     * @return  self  Complete replayable ledger result.
     *
     * @throws  InvalidArgumentException  When the action is not a complete custom declaration.
     *
     * @since   2.0.0
     */
    public static function capture(
        EntityTypeDefinition $definition,
        ActionDefinition $action,
        string $recordId,
        int $runtimeGeneration,
        string $runtimeChecksum,
        CustomBusinessActionResult $result,
    ): self {
        if ($action->handler === null || $action->schema === null) {
            throw new InvalidArgumentException('A custom business action ledger requires active references.');
        }

        return new self(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            $runtimeGeneration,
            $runtimeChecksum,
            $action->handler,
            $action->schema,
            $recordId,
            $result->recordVersion,
            $result->workflowState,
            $action->handle,
            $result->deleted,
            $result->replayed,
            $result->data,
        );
    }

    /**
     * Rebuild and strictly validate one checksum-verified ledger payload.
     *
     * @param   array<string, mixed>  $document  Stored tagged outcome.
     *
     * @return  self  Validated custom action ledger result.
     *
     * @throws  InvalidArgumentException  When keys, discriminator, or any value is malformed.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        $expectedKeys = [
            'kind',
            'definition_id',
            'definition_version',
            'definition_checksum',
            'runtime_generation',
            'runtime_checksum',
            'handler',
            'schema',
            'record_id',
            'record_version',
            'workflow_state',
            'action',
            'deleted',
            'originally_replayed',
            'data',
        ];
        if (
            count($document) !== count($expectedKeys)
            || array_diff($expectedKeys, array_keys($document)) !== []
            || ($document['kind'] ?? null) !== self::KIND
        ) {
            throw new InvalidArgumentException('A custom business action ledger result is malformed.');
        }
        foreach (
            [
                'definition_id',
                'definition_checksum',
                'runtime_checksum',
                'handler',
                'schema',
                'record_id',
                'action',
            ] as $string
        ) {
            if (!is_string($document[$string])) {
                throw new InvalidArgumentException('A custom business action ledger result is malformed.');
            }
        }
        if (
            !is_int($document['definition_version'])
            || !is_int($document['runtime_generation'])
            || !is_int($document['record_version'])
            || ($document['workflow_state'] !== null && !is_string($document['workflow_state']))
            || !is_bool($document['deleted'])
            || !is_bool($document['originally_replayed'])
            || !is_array($document['data'])
            || array_is_list($document['data'])
        ) {
            throw new InvalidArgumentException('A custom business action ledger result is malformed.');
        }

        /** @var array<string, mixed> $data */
        $data = $document['data'];
        return new self(
            $document['definition_id'],
            $document['definition_version'],
            $document['definition_checksum'],
            $document['runtime_generation'],
            $document['runtime_checksum'],
            $document['handler'],
            $document['schema'],
            $document['record_id'],
            $document['record_version'],
            $document['workflow_state'],
            $document['action'],
            $document['deleted'],
            $document['originally_replayed'],
            $data,
        );
    }

    /**
     * Recognize the tagged result family without interpreting malformed payload members.
     *
     * @param   array<string, mixed>  $document  Checksum-verified ledger payload.
     *
     * @return  bool  True only for the custom-action discriminator.
     *
     * @since   2.0.0
     */
    public static function recognizes(array $document): bool
    {
        return ($document['kind'] ?? null) === self::KIND;
    }

    /**
     * Export the exact internal ledger envelope used for checksumming and replay.
     *
     * @return  array<string, mixed>  Canonically ordered tagged result.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'definition_id' => $this->definitionId,
            'definition_version' => $this->definitionVersion,
            'definition_checksum' => $this->definitionChecksum,
            'runtime_generation' => $this->runtimeGeneration,
            'runtime_checksum' => $this->runtimeChecksum,
            'handler' => $this->handler,
            'schema' => $this->schema,
            'record_id' => $this->recordId,
            'record_version' => $this->recordVersion,
            'workflow_state' => $this->workflowState,
            'action' => $this->action,
            'deleted' => $this->deleted,
            'originally_replayed' => $this->originallyReplayed,
            'data' => $this->data,
        ];
    }

    /**
     * Reconstitute the typed handler result under the current operation identity.
     *
     * @param   IdempotencyKey  $operationId  Caller operation key resolving this ledger entry.
     * @param   bool            $replayed     Whether the outer shared ledger served a previous result.
     *
     * @return  CustomBusinessActionResult  Typed result ready for the common surface facade.
     *
     * @since   2.0.0
     */
    public function result(IdempotencyKey $operationId, bool $replayed): CustomBusinessActionResult
    {
        return new CustomBusinessActionResult(
            $this->data,
            $this->recordVersion,
            $operationId,
            $replayed || $this->originallyReplayed,
            $this->workflowState,
            $this->deleted,
        );
    }

    /**
     * Project the public mutation plus custom result without internal activation references.
     *
     * Reconstructing a ledger result is itself a replay, matching canonical record-operation status.
     *
     * @return  array<string, mixed>  Exact operation-status result document.
     *
     * @since   2.0.0
     */
    public function publicResult(): array
    {
        return [
            'definition_version' => $this->definitionVersion,
            'record_id' => $this->recordId,
            'version' => $this->recordVersion,
            'workflow_state' => $this->workflowState,
            'operation' => 'action',
            'deleted' => $this->deleted,
            'replayed' => true,
            'result' => $this->data,
        ];
    }
}
