<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;

/**
 * Arranges one safe document read model into the template-ready shape of a printed business document.
 *
 * The presenter works entirely on the policy-filtered model `BusinessSurfaceService::document()` already
 * produced: it resolves the document title from the declared identity field's presented display — falling
 * back to the definition label and the record's creation date, never a machine key — and maps the declared
 * group, party, line and totals roles onto the presented values the model carries. It reads nothing and
 * widens nothing; a role whose field or relationship was withheld simply renders as absent.
 *
 * A presented value's conversion evidence travels with it into every block the document arranges, so a
 * converted total, a converted meta field and a converted line cell each reach the printed page carrying
 * the rate, the as-at instant and the provider behind them. A printed document is the artifact most
 * likely to be filed, posted or produced in a dispute, which is why it is the last place a figure may
 * appear without saying where it came from.
 *
 * @since  2.0.0
 */
final readonly class BusinessDocumentPresenter
{
    /**
     * Arrange one document read model for the generated document templates.
     *
     * @param   array<string, mixed>  $model  Safe detail model carrying a `document_view` item.
     *
     * @return  array<string, mixed>  Title, identity, groups, parties, lines table and totals block.
     *
     * @throws  InvalidArgumentException  When the trusted model is missing a document view or malformed.
     *
     * @since   2.0.0
     */
    public function present(array $model): array
    {
        $definition = $this->map($model['definition'] ?? null, 'A document model definition is invalid.');
        $view = $this->map($model['document_view'] ?? null, 'A document model view is invalid.');
        $record = $this->map($model['record'] ?? null, 'A document model record is invalid.');
        $fields = $this->presentedFields($model['fields'] ?? null);
        $rolesValue = $view['document'] ?? null;
        $roles = $rolesValue === null ? [] : $this->map($rolesValue, 'A document model role block is invalid.');
        $includes = $this->map($record['includes'] ?? [], 'A document model include set is invalid.');

        $identity = null;
        $identityLabel = null;
        $identityHandle = $roles['identity'] ?? null;
        if (is_string($identityHandle) && isset($fields[$identityHandle])) {
            $display = $fields[$identityHandle]['display'];
            $identity = $display === '' ? null : $display;
            $identityLabel = $fields[$identityHandle]['label'];
        }
        $singular = $definition['singular_label'] ?? null;
        $singular = is_string($singular) ? $singular : 'Record';
        $date = $this->shortDate($record);

        return [
            'title' => $identity !== null
                ? $singular . ' ' . $identity
                : ($date === '' ? $singular : $singular . ' · ' . $date),
            'identity' => $identity,
            'identity_label' => $identityLabel,
            'groups' => $this->groups($roles, $fields),
            'parties' => $this->parties($roles, $includes),
            'lines' => $this->lines($roles, $view, $definition, $includes),
            'totals' => $this->fieldItems($roles['totals'] ?? [], $fields),
        ];
    }

    /**
     * Resolve the declared meta groups against the presented field displays.
     *
     * @param   array<string, mixed>  $roles  Policy-filtered
     *          document roles.
     * @param   array<string, array{handle: string, label: string, display: string,
     *          provenance: ?array<string, mixed>}>  $fields  Presented fields keyed by handle.
     *
     * @return  list<array{label: string, fields: list<array{handle: string, label: string, display: string,
     *          provenance: ?array<string, mixed>}>}>  Groups that still project at least one presented field.
     *
     * @since   2.0.0
     */
    private function groups(array $roles, array $fields): array
    {
        $groups = [];
        $declared = $roles['groups'] ?? [];
        foreach (is_array($declared) ? $declared : [] as $group) {
            if (!is_array($group) || !is_string($group['label'] ?? null)) {
                continue;
            }
            $items = $this->fieldItems($group['fields'] ?? [], $fields);
            if ($items !== []) {
                $groups[] = ['label' => $group['label'], 'fields' => $items];
            }
        }

        return $groups;
    }

    /**
     * Resolve the declared parties against the hydrated relationship includes.
     *
     * @param   array<string, mixed>  $roles     Policy-filtered document roles.
     * @param   array<string, mixed>  $includes  Hydrated includes keyed by relationship handle.
     *
     * @return  list<array{label: string, records: list<array<string, mixed>>}>  One card per declared
     *          party, carrying the presented related records; empty parties are kept so a document shows
     *          the party label with no counterparty rather than silently dropping the block.
     *
     * @since   2.0.0
     */
    private function parties(array $roles, array $includes): array
    {
        $parties = [];
        $declared = $roles['parties'] ?? [];
        foreach (is_array($declared) ? $declared : [] as $party) {
            if (
                !is_array($party)
                || !is_string($party['label'] ?? null)
                || !is_string($party['relationship'] ?? null)
            ) {
                continue;
            }
            $parties[] = [
                'label' => $party['label'],
                'records' => $this->relatedRows($includes[$party['relationship']] ?? []),
            ];
        }

        return $parties;
    }

    /**
     * Resolve the declared line collection into a stable-column body table.
     *
     * @param   array<string, mixed>  $roles       Policy-filtered document roles.
     * @param   array<string, mixed>  $view        Document view item carrying the projected line columns.
     * @param   array<string, mixed>  $definition  Safe definition metadata naming the relationship label.
     * @param   array<string, mixed>  $includes    Hydrated includes keyed by relationship handle.
     *
     * @return  ?array{label: string, columns: list<array{handle: string, label: string}>,
     *          rows: list<array<string, mixed>>}  The table model, or null when no line role survives.
     *
     * @since   2.0.0
     */
    private function lines(array $roles, array $view, array $definition, array $includes): ?array
    {
        $handle = $roles['lines'] ?? null;
        if (!is_string($handle)) {
            return null;
        }
        $label = $handle;
        $relationships = $definition['relationships'] ?? [];
        foreach (is_array($relationships) ? $relationships : [] as $relationship) {
            if (
                is_array($relationship)
                && ($relationship['handle'] ?? null) === $handle
                && is_string($relationship['label'] ?? null)
            ) {
                $label = $relationship['label'];
                break;
            }
        }
        $columns = [];
        foreach (is_array($view['line_columns'] ?? null) ? $view['line_columns'] : [] as $column) {
            if (
                is_array($column)
                && is_string($column['handle'] ?? null)
                && is_string($column['label'] ?? null)
            ) {
                $columns[] = ['handle' => $column['handle'], 'label' => $column['label']];
            }
        }
        return [
            'label' => $label,
            'columns' => $columns,
            'rows' => $this->relatedRows($includes[$handle] ?? []),
        ];
    }

    /**
     * Narrow one hydrated include to its object rows, dropping machine identifiers on the way.
     *
     * Record and version keys are removed so no template iterating a document row can render a UUID or
     * an optimistic version into the document body — the printed page carries human values only.
     *
     * @param   mixed  $related  Candidate include collection from the safe record projection.
     *
     * @return  list<array<string, mixed>>  Object rows in include order; anything else yields no rows.
     *
     * @since   2.0.0
     */
    private function relatedRows(mixed $related): array
    {
        if (!is_array($related) || !array_is_list($related)) {
            return [];
        }
        $rows = [];
        foreach ($related as $row) {
            if (is_array($row)) {
                $row = $this->map($row, 'A document related row is invalid.');
                unset($row['record_id'], $row['version']);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Map one list of role handles onto the presented fields that survived policy.
     *
     * @param   mixed  $handles  Declared handles.
     * @param   array<string, array{handle: string, label: string, display: string,
     *          provenance: ?array<string, mixed>}>  $fields   Presented fields keyed by handle.
     *
     * @return  list<array{handle: string, label: string, display: string,
     *          provenance: ?array<string, mixed>}>  Presented items in role order.
     *
     * @since   2.0.0
     */
    private function fieldItems(mixed $handles, array $fields): array
    {
        $items = [];
        foreach (is_array($handles) ? $handles : [] as $handle) {
            if (is_string($handle) && isset($fields[$handle])) {
                $items[] = $fields[$handle];
            }
        }

        return $items;
    }

    /**
     * Index the presented detail fields by handle, keeping only what the templates need.
     *
     * @param   mixed  $fields  Presented field list from the safe detail model.
     *
     * @return  array<string, array{handle: string, label: string, display: string,
     *          provenance: ?array<string, mixed>}>  Label, display text and any conversion evidence,
     *          keyed by field handle.
     *
     * @since   2.0.0
     */
    private function presentedFields(mixed $fields): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new InvalidArgumentException('A document model field list is invalid.');
        }
        $indexed = [];
        foreach ($fields as $field) {
            if (
                !is_array($field)
                || !is_string($field['handle'] ?? null)
                || !is_string($field['label'] ?? null)
                || !is_string($field['display'] ?? null)
            ) {
                continue;
            }
            $provenance = $field['provenance'] ?? null;
            $indexed[$field['handle']] = [
                'handle' => $field['handle'],
                'label' => $field['label'],
                'display' => $field['display'],
                'provenance' => is_array($provenance)
                    ? $this->map($provenance, 'A document model conversion provenance is invalid.')
                    : null,
            ];
        }

        return $indexed;
    }

    /**
     * Read the record's creation date as the fallback document date.
     *
     * @param   array<string, mixed>  $record  Safe record projection.
     *
     * @return  string  The `Y-m-d` prefix of the creation instant, or an empty string when unavailable.
     *
     * @since   2.0.0
     */
    private function shortDate(array $record): string
    {
        $created = $record['created_at'] ?? null;

        return is_string($created) ? substr($created, 0, 10) : '';
    }

    /**
     * Prove one trusted model member is a string-keyed object.
     *
     * @param   mixed   $value    Candidate object value.
     * @param   string  $message  Stable exception message for a malformed trusted document.
     *
     * @return  array<string, mixed>  Validated object document.
     *
     * @throws  InvalidArgumentException  When the value is not an object map.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($message);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
