<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;

/**
 * Bounded server-side presentation controls for one policy-filtered business collection.
 *
 * The class can hide or reorder only metadata the application catalog already disclosed. It never
 * changes the record query, reveals a denied field, or treats a hidden column as authorization. The
 * same state drives the table and responsive summary-card representations without requiring JavaScript.
 *
 * @since  2.0.0
 */
final readonly class BusinessCollectionPresentation
{
    /**
     * Hold normalized controls after comparing every column with the disclosed metadata.
     *
     * @param  list<string>  $columns         Ordered field handles rendered by this response.
     * @param  string        $density         KIS density selected for this collection.
     * @param  string        $representation  Automatic, table, or summary-card preference.
     *
     * @since  2.0.0
     */
    private function __construct(
        public array $columns,
        public string $density,
        public string $representation,
    ) {
    }

    /**
     * Parse native controls against the exact policy-visible field list.
     *
     * @param   array<string, mixed>       $query    Decoded browser query string.
     * @param   list<array<string, mixed>>  $fields   Field metadata already filtered by application policy.
     * @param   list<string>               $defaults  Declared default-view columns, or an empty list for all.
     *
     * @return  self  Safe presentation state for both administrator and portal Twig templates.
     *
     * @throws  InvalidArgumentException  When a control is malformed, duplicated, unbounded, or withheld.
     *
     * @since   2.0.0
     */
    public static function fromQuery(array $query, array $fields, array $defaults = []): self
    {
        $available = [];
        foreach ($fields as $field) {
            $handle = $field['handle'] ?? null;
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('Generated collection field metadata is invalid.');
            }
            $available[$handle] = true;
        }

        $requested = $query['columns'] ?? null;
        if ($requested === null) {
            $requested = self::defaultColumns($defaults, $available);
        }
        if (!is_array($requested) || !array_is_list($requested) || count($requested) > 64) {
            throw new InvalidArgumentException('Generated collection columns must be a bounded list.');
        }
        $columns = [];
        foreach ($requested as $column) {
            if (!is_string($column) || !isset($available[$column]) || in_array($column, $columns, true)) {
                throw new InvalidArgumentException(
                    'A generated collection column is unavailable, invalid, or duplicated.',
                );
            }
            $columns[] = $column;
        }

        $density = self::choice($query, 'density', ['comfortable', 'compact', 'touch'], 'comfortable');
        $representation = self::choice(
            $query,
            'representation',
            ['auto', 'table', 'cards'],
            'auto',
        );

        return new self($columns, $density, $representation);
    }

    /**
     * Intersect a declared default view with the actor's current field projection.
     *
     * Default views describe presentation, not authorization. A field can therefore disappear from a
     * previously valid view when policy or membership changes. Such fields are omitted without disclosing
     * their names; if none remain, the collection falls back to all currently visible fields. Explicit
     * browser requests stay strict in {@see fromQuery()} and still fail for withheld fields.
     *
     * @param   list<string>         $defaults   Declared list-view field handles.
     * @param   array<string, true>  $available  Exact policy-visible field lookup.
     *
     * @return  list<string>  At most 64 visible, unique fields in declared order.
     *
     * @throws  InvalidArgumentException  When trusted default-view metadata is malformed or duplicated.
     *
     * @since   2.0.0
     */
    private static function defaultColumns(array $defaults, array $available): array
    {
        if ($defaults === []) {
            return array_slice(array_keys($available), 0, 64);
        }

        $columns = [];
        $seen = [];
        foreach ($defaults as $column) {
            if (!is_string($column) || isset($seen[$column])) {
                throw new InvalidArgumentException('Generated collection default-view metadata is invalid.');
            }
            $seen[$column] = true;
            if (isset($available[$column]) && count($columns) < 64) {
                $columns[] = $column;
            }
        }

        return $columns === []
            ? array_slice(array_keys($available), 0, 64)
            : $columns;
    }

    /**
     * Return a query fragment that preserves presentation controls across cursor navigation.
     *
     * @return  array{columns: list<string>, density: string, representation: string}
     *          Canonical native controls safe for `http_build_query()`.
     *
     * @since   2.0.0
     */
    public function query(): array
    {
        return [
            'columns' => $this->columns,
            'density' => $this->density,
            'representation' => $this->representation,
        ];
    }

    /**
     * Select one bounded literal without coercing scalar or collection input.
     *
     * @param   array<string, mixed>  $query     Decoded browser query.
     * @param   string                $key       Native control name.
     * @param   list<string>          $allowed   Closed literal vocabulary.
     * @param   string                $default   Value used when the control is absent.
     *
     * @return  string  Admitted literal.
     *
     * @throws  InvalidArgumentException  When a supplied value is not one allowed literal.
     *
     * @since   2.0.0
     */
    private static function choice(array $query, string $key, array $allowed, string $default): string
    {
        $value = $query[$key] ?? $default;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'The generated collection %s control is unsupported.',
                $key,
            ));
        }

        return $value;
    }
}
