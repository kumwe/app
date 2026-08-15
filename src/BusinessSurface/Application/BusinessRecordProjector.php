<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Application\RecordHistoryResult;
use Kumwe\CMS\BusinessRecord\Application\RecordMutationResult;
use Kumwe\CMS\BusinessRecord\Domain\ConvertedMoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;

/**
 * Projects business-record results into the one safe document shape shared by every adapter.
 *
 * Internal storage keys and scope plumbing never leave this projector. Application read views have already
 * omitted withheld handles, so every remaining array is ordinary exact business data and is preserved
 * recursively. Exact decimal strings and typed composite arrays pass through unchanged.
 *
 * REST, the model-context tools and the console all serialize through here and own no second policy, so
 * a converted amount is exported once, in the declared shape that carries its rate, as-at instant,
 * provider and rounding. That export is structurally unlike a stored money value — the figure sits under
 * `value` and the `converted` marker is unconditional — so no consumer of this projection can mistake
 * one for the other, and none of them has to know it was converted to render it correctly.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordProjector
{
    /**
     * Project one disclosed record.
     *
     * @param   BusinessRecordView  $record  Application read result.
     *
     * @return  array<string, mixed>  Public identity, version, state, safe values and includes.
     *
     * @since   2.0.0
     */
    public function record(BusinessRecordView $record): array
    {
        $includes = [];
        foreach ($record->includes as $handle => $rows) {
            $includes[$handle] = array_map($this->relation(...), $rows);
        }

        return [
            'definition_version' => $record->definitionVersion,
            'record_id' => $record->recordId,
            'version' => $record->version,
            'workflow_state' => $record->workflowState,
            'values' => $this->values($record->values),
            'created_at' => $record->createdAt->format(DATE_ATOM),
            'updated_at' => $record->updatedAt->format(DATE_ATOM),
            'archived_at' => $record->archivedAt?->format(DATE_ATOM),
            'deleted_at' => $record->deletedAt?->format(DATE_ATOM),
            'includes' => $includes,
        ];
    }

    /**
     * Project one bounded browse page.
     *
     * @param   RecordBrowseResult  $result  Application browse result.
     *
     * @return  array<string, mixed>  Records, opaque next cursor and exact aggregates.
     *
     * @since   2.0.0
     */
    public function browse(RecordBrowseResult $result): array
    {
        return [
            'items' => array_map($this->record(...), $result->records),
            'next_cursor' => $result->nextCursor?->value(),
            'aggregates' => $this->values($result->aggregates),
        ];
    }

    /**
     * Project one mutation outcome without its internal record key.
     *
     * @param   RecordMutationResult  $result  Application mutation outcome.
     *
     * @return  array<string, mixed>  Public identity, version, state and replay metadata.
     *
     * @since   2.0.0
     */
    public function mutation(RecordMutationResult $result): array
    {
        return [
            'definition_version' => $result->definitionVersion,
            'record_id' => $result->recordId,
            'version' => $result->version,
            'workflow_state' => $result->workflowState,
            'operation' => $result->operation,
            'deleted' => $result->deleted,
            'replayed' => $result->replayed,
        ];
    }

    /**
     * Project a bounded revision page with caller-visible attribution.
     *
     * @param   RecordHistoryResult  $result  Disclosure-filtered application history result.
     *
     * @return  array<string, mixed>  Revisions and continuation metadata.
     *
     * @since   2.0.0
     */
    public function history(RecordHistoryResult $result): array
    {
        $last = $result->revisions === [] ? null : $result->revisions[array_key_last($result->revisions)];

        return [
            'items' => array_map($this->revision(...), $result->revisions),
            'has_more' => $result->hasMore,
            'next_before_version' => $result->hasMore ? $last?->recordVersion : null,
        ];
    }

    /**
     * Project one related row without its internal storage key.
     *
     * @param   BusinessRecordRelationView  $relation  Disclosure-filtered related record.
     *
     * @return  array<string, mixed>  Public identity, version, position and safe values.
     *
     * @since   2.0.0
     */
    private function relation(BusinessRecordRelationView $relation): array
    {
        return [
            'definition_version' => $relation->definitionVersion,
            'record_id' => $relation->recordId,
            'version' => $relation->version,
            'position' => $relation->position,
            'values' => $this->values($relation->values),
        ];
    }

    /**
     * Project one revision without storage, actor or integrity identifiers.
     *
     * @param   BusinessRecordRevisionView  $revision  Disclosure-filtered revision.
     *
     * @return  array<string, mixed>  Safe snapshot and revision metadata.
     *
     * @since   2.0.0
     */
    private function revision(BusinessRecordRevisionView $revision): array
    {
        $snapshot = $this->values($revision->snapshot);

        return [
            'definition_version' => $revision->definitionVersion,
            'record_version' => $revision->recordVersion,
            'revision_number' => $revision->revisionNumber,
            'operation' => $revision->operation,
            'snapshot' => $snapshot,
            'changed_fields' => array_values(array_filter(
                $revision->changedFields,
                static fn (string $handle): bool => array_key_exists($handle, $snapshot),
            )),
            'occurred_at' => $revision->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * Convert every disclosed value in a field-value map.
     *
     * @param   array<array-key, mixed>  $values  Disclosure-filtered object or nested list values.
     *
     * @return  array<array-key, mixed>  Exact disclosed values in their original map or list shape.
     *
     * @since   2.0.0
     */
    private function values(array $values): array
    {
        $projected = [];
        foreach ($values as $handle => $value) {
            $projected[$handle] = $this->value($value);
        }

        return array_is_list($values) ? array_values($projected) : $projected;
    }

    /**
     * Convert one normalized value into its exact transport representation.
     *
     * @param   mixed  $value  Normalized scalar, composite, temporal or nested value.
     *
     * @return  mixed  JSON-safe value with exact decimal spellings preserved.
     *
     * @since   2.0.0
     */
    private function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof ConvertedMoneyValue => $value->toArray(),
            $value instanceof ExactDecimal => $value->value(),
            $value instanceof MoneyValue,
            $value instanceof QuantityValue,
            $value instanceof ZonedDateTimeValue => $value->toArray(),
            $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
            is_array($value) => $this->values($value),
            default => $value,
        };
    }
}
