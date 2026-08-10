<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Validated bounded plan for one atomic generated-surface bulk mutation.
 *
 * The plan accepts only archive, restore, and explicitly bulk-enabled action operations. Every selected
 * record carries the version the operator reviewed, and a caller operation identity is deterministically
 * narrowed to one independent idempotency key per record. The derived keys reveal neither record identity
 * nor action input and remain stable across retries of the same ordered or re-ordered selection.
 *
 * @since  2.0.0
 */
final readonly class BusinessBulkMutation
{
    /**
     * Validated unique selected records.
     *
     * @var    list<array{record_id: string, expected_version: int}>
     * @since  2.0.0
     */
    private array $items;

    /**
     * Capture and validate one bounded bulk attempt.
     *
     * @param   BusinessSurfaceOperation    $operation    Archive, restore, or action operation.
     * @param   list<array<string, mixed>>  $items        At most 50 record identities and reviewed versions.
     * @param   string                      $operationId  Caller-owned idempotency identity for the whole attempt.
     * @param   ?string                     $action       Declared action handle for an action attempt.
     * @param   array<string, mixed>        $input        Shared bounded action input applied to every record.
     *
     * @throws  InvalidArgumentException  When the operation, selection, identity, version, action, or input
     *          is malformed, duplicated, or outside its bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public BusinessSurfaceOperation $operation,
        array $items,
        private string $operationId,
        public ?string $action = null,
        public array $input = [],
    ) {
        if (
            !in_array($operation, [
            BusinessSurfaceOperation::Archive,
            BusinessSurfaceOperation::Restore,
            BusinessSurfaceOperation::Action,
            ], true)
        ) {
            throw new InvalidArgumentException('A generated business bulk operation is unsupported.');
        }
        IdempotencyKey::fromString($operationId);
        if ($items === [] || count($items) > 50 || !array_is_list($items)) {
            throw new InvalidArgumentException('A generated business bulk selection must contain 1 to 50 records.');
        }
        $normalized = [];
        $identities = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || array_is_list($item)
                || array_diff(array_keys($item), ['record_id', 'expected_version']) !== []
                || !array_key_exists('record_id', $item)
                || !array_key_exists('expected_version', $item)
            ) {
                throw new InvalidArgumentException('A generated business bulk item is malformed.');
            }
            $recordId = $item['record_id'];
            $expectedVersion = $item['expected_version'];
            if (!is_string($recordId) || $recordId === '' || strlen($recordId) > 191) {
                throw new InvalidArgumentException('A generated business bulk record identity is invalid.');
            }
            if (!is_int($expectedVersion) || $expectedVersion < 1 || $expectedVersion > 2_147_483_647) {
                throw new InvalidArgumentException('A generated business bulk expected version is invalid.');
            }
            if (isset($identities[$recordId])) {
                throw new InvalidArgumentException('A generated business bulk selection contains a duplicate record.');
            }
            $identities[$recordId] = true;
            $normalized[] = ['record_id' => $recordId, 'expected_version' => $expectedVersion];
        }
        if ($operation === BusinessSurfaceOperation::Action) {
            if ($action === null || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $action) !== 1) {
                throw new InvalidArgumentException('A generated business bulk action handle is invalid.');
            }
        } elseif ($action !== null || $input !== []) {
            throw new InvalidArgumentException('Only a generated business bulk action accepts action input.');
        }
        if (count($input) > 128 || strlen(CanonicalDefinitionJson::encode($input)) > 1_048_576) {
            throw new InvalidArgumentException('A generated business bulk action input is unbounded.');
        }

        $this->items = $normalized;
    }

    /**
     * Return the validated selected records in caller order.
     *
     * @return  list<array{record_id: string, expected_version: int}>  Unique bounded selection.
     *
     * @since   2.0.0
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Derive the replay identity for one selected record without exposing its identity in the key.
     *
     * @param   string  $recordId  Record identity from `items()`.
     *
     * @return  string  Stable `bulk:` plus SHA-256 idempotency identity.
     *
     * @throws  InvalidArgumentException  When the record was not part of this plan.
     *
     * @since   2.0.0
     */
    public function operationIdFor(string $recordId): string
    {
        $selected = false;
        foreach ($this->items as $item) {
            if (hash_equals($item['record_id'], $recordId)) {
                $selected = true;
                break;
            }
        }
        if (!$selected) {
            throw new InvalidArgumentException(
                'A generated business bulk operation identity requires a selected record.',
            );
        }

        return 'bulk:' . hash('sha256', implode("\0", [
            $this->operationId,
            $this->operation->value,
            $this->action ?? '',
            $recordId,
        ]));
    }
}
