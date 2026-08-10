<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use JsonException;

/**
 * Strict browser mapping for native graphical filters, search, sorting, lifecycle scope, and cursors.
 *
 * A browser may supply either the stable shared JSON query document or the small native-control shape,
 * never both. Graphical fields compile into that same closed document before the application facade sees
 * them. Successful pages can then embed a freshly encoded document carrying the next opaque cursor, so
 * pagination preserves every validated predicate without trusting Twig or JavaScript to rebuild it.
 *
 * @since  2.0.0
 */
final readonly class BusinessBrowserQuery
{
    /**
     * Capture one already-mapped query document.
     *
     * @param  array<string, mixed>  $document   Shared query grammar document.
     * @param  array<string, mixed>  $formState  Safe values used to retain graphical controls.
     *
     * @since  2.0.0
     */
    private function __construct(
        private array $document,
        private array $formState,
    ) {
    }

    /**
     * Decode an opaque document or map native GET controls into the shared query grammar.
     *
     * @param   array<string, mixed>  $query  Decoded browser query string.
     *
     * @return  self  Bounded document and retainable graphical state.
     *
     * @throws  InvalidArgumentException  When opaque and graphical shapes are mixed or any control is
     *          malformed, unknown, duplicated, or outside its bound.
     *
     * @since   2.0.0
     */
    public static function fromQuery(array $query): self
    {
        $graphicalKeys = [
            'filters', 'integer_filters', 'boolean_filters',
            'sort_field', 'sort_direction', 'search_term', 'search_fields',
            'page_size', 'include_archived', 'include_deleted', 'after',
        ];
        $opaque = $query['query'] ?? null;
        if ($opaque !== null && $opaque !== '') {
            foreach ($graphicalKeys as $key) {
                if (array_key_exists($key, $query)) {
                    throw new InvalidArgumentException(
                        'An opaque business query cannot be mixed with graphical controls.',
                    );
                }
            }
            if (!is_string($opaque) || strlen($opaque) > 65_536) {
                throw new InvalidArgumentException('A generated business query is invalid or unbounded.');
            }
            try {
                $document = json_decode($opaque, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('A generated business query must be valid JSON.', 0, $exception);
            }
            if (!is_array($document) || array_is_list($document)) {
                throw new InvalidArgumentException('A generated business query must be a JSON object.');
            }

            return new self($document, self::state($document));
        }

        $document = [];
        $filterMaps = [];
        $rawFilterCount = 0;
        foreach (['filters', 'integer_filters', 'boolean_filters'] as $key) {
            $filterMap = $query[$key] ?? [];
            if (!is_array($filterMap) || ($filterMap !== [] && array_is_list($filterMap))) {
                throw new InvalidArgumentException('Graphical business filters must be bounded field maps.');
            }
            $rawFilterCount += count($filterMap);
            $filterMaps[$key] = $filterMap;
        }
        if ($rawFilterCount > 128) {
            throw new InvalidArgumentException('Graphical business filters must be bounded field maps.');
        }
        $nodes = [];
        $retainedFilters = [];
        $seenFields = [];
        foreach ($filterMaps as $kind => $filterMap) {
            foreach ($filterMap as $field => $value) {
                self::field($field);
                if (isset($seenFields[$field])) {
                    throw new InvalidArgumentException('A graphical business filter field is duplicated.');
                }
                $seenFields[$field] = true;
                if ($value === '') {
                    continue;
                }
                $typedValue = match ($kind) {
                    'integer_filters' => self::integer($value),
                    'boolean_filters' => self::boolean($value),
                    default => self::filterString($value),
                };
                $nodes[] = [
                    'type' => 'comparison',
                    'field' => $field,
                    'operator' => 'eq',
                    'value' => $typedValue,
                ];
                $retainedFilters[$field] = $typedValue;
            }
        }
        if (count($nodes) > 16) {
            throw new InvalidArgumentException('Graphical business filters cannot select more than 16 fields.');
        }
        if (count($nodes) === 1) {
            $document['filter'] = $nodes[0];
        } elseif ($nodes !== []) {
            $document['filter'] = ['type' => 'boolean', 'operator' => 'all', 'children' => $nodes];
        }

        $sortField = self::optionalString($query, 'sort_field', 63);
        $sortDirection = self::optionalString($query, 'sort_direction', 4);
        if (($sortField === null) !== ($sortDirection === null)) {
            throw new InvalidArgumentException('A graphical business sort requires both field and direction.');
        }
        if ($sortField !== null && $sortDirection !== null) {
            self::field($sortField);
            if (!in_array($sortDirection, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException('A graphical business sort direction is unsupported.');
            }
            $document['sorts'] = [['field' => $sortField, 'direction' => $sortDirection]];
        }

        $searchTerm = self::optionalString($query, 'search_term', 500);
        $searchFields = $query['search_fields'] ?? [];
        if (!is_array($searchFields) || !array_is_list($searchFields) || count($searchFields) > 16) {
            throw new InvalidArgumentException('A graphical business search field list is invalid or unbounded.');
        }
        foreach ($searchFields as $field) {
            self::field($field);
        }
        if (count($searchFields) !== count(array_unique($searchFields))) {
            throw new InvalidArgumentException('A graphical business search field list contains duplicates.');
        }
        if ($searchTerm !== null) {
            if ($searchFields === []) {
                throw new InvalidArgumentException('A graphical business search requires at least one field.');
            }
            $document['search'] = ['term' => $searchTerm, 'fields' => $searchFields];
        }

        foreach (['include_archived', 'include_deleted'] as $key) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            if ($query[$key] !== '1' && $query[$key] !== 1 && $query[$key] !== true) {
                throw new InvalidArgumentException('A graphical business lifecycle control is invalid.');
            }
            $document[$key] = true;
        }
        if (array_key_exists('page_size', $query) && $query['page_size'] !== '') {
            $document['page_size'] = self::positive($query['page_size'], 200);
        }
        if (array_key_exists('after', $query) && $query['after'] !== '') {
            $after = $query['after'];
            if (!is_string($after) || strlen($after) > 4096) {
                throw new InvalidArgumentException('A graphical business cursor is invalid or unbounded.');
            }
            $document['after'] = $after;
        }

        return new self($document, [
            'filters' => $retainedFilters,
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
            'search_term' => $searchTerm,
            'search_fields' => $searchTerm === null ? [] : $searchFields,
            'page_size' => $document['page_size'] ?? 50,
            'include_archived' => $document['include_archived'] ?? false,
            'include_deleted' => $document['include_deleted'] ?? false,
        ]);
    }

    /**
     * Return the shared validated query document.
     *
     * @return  array<string, mixed>  Document handed to `BusinessRecordQueryFactory` by the facade.
     *
     * @since   2.0.0
     */
    public function document(): array
    {
        return $this->document;
    }

    /**
     * Return safe values for retaining native graphical controls.
     *
     * @return  array<string, mixed>  Filter, sort, search, page-size, and lifecycle values.
     *
     * @since   2.0.0
     */
    public function formState(): array
    {
        return $this->formState;
    }

    /**
     * Encode this validated query with the next opaque cursor replacing the current cursor.
     *
     * @param   string  $cursor  Opaque cursor returned by the canonical record query.
     *
     * @return  string  JSON query document safe to place in the next-page link.
     *
     * @throws  InvalidArgumentException  When the cursor is empty or outside its transport bound.
     *
     * @since   2.0.0
     */
    public function next(string $cursor): string
    {
        if ($cursor === '' || strlen($cursor) > 4096) {
            throw new InvalidArgumentException('A generated business next cursor is invalid or unbounded.');
        }

        return self::encode([...$this->document, 'after' => $cursor]);
    }

    /**
     * Recover the subset of an opaque document that native controls can safely retain.
     *
     * Complex filters or multiple sorts continue to paginate through `next()` but are intentionally not
     * simplified into different graphical semantics.
     *
     * @param   array<string, mixed>  $document  Decoded shared query document.
     *
     * @return  array<string, mixed>  Safe native-control state.
     *
     * @since   2.0.0
     */
    private static function state(array $document): array
    {
        $filters = [];
        $filter = $document['filter'] ?? null;
        $nodes = is_array($filter) && ($filter['type'] ?? null) === 'boolean'
            && ($filter['operator'] ?? null) === 'all' && is_array($filter['children'] ?? null)
            ? $filter['children']
            : ($filter === null ? [] : [$filter]);
        foreach ($nodes as $node) {
            if (
                !is_array($node)
                || ($node['type'] ?? null) !== 'comparison'
                || ($node['operator'] ?? null) !== 'eq'
                || !is_string($node['field'] ?? null)
                || (
                    !is_string($node['value'] ?? null)
                    && !is_int($node['value'] ?? null)
                    && !is_bool($node['value'] ?? null)
                )
            ) {
                $filters = [];
                break;
            }
            $filters[$node['field']] = $node['value'];
        }
        $sort = is_array($document['sorts'] ?? null) && count($document['sorts']) === 1
            && is_array($document['sorts'][0] ?? null) ? $document['sorts'][0] : [];
        $search = is_array($document['search'] ?? null) ? $document['search'] : [];

        return [
            'filters' => $filters,
            'sort_field' => is_string($sort['field'] ?? null) ? $sort['field'] : null,
            'sort_direction' => is_string($sort['direction'] ?? null) ? $sort['direction'] : null,
            'search_term' => is_string($search['term'] ?? null) ? $search['term'] : null,
            'search_fields' => is_array($search['fields'] ?? null) ? $search['fields'] : [],
            'page_size' => is_int($document['page_size'] ?? null) ? $document['page_size'] : 50,
            'include_archived' => ($document['include_archived'] ?? false) === true,
            'include_deleted' => ($document['include_deleted'] ?? false) === true,
        ];
    }

    /**
     * Read one optional trimmed bounded string control.
     *
     * @param   array<string, mixed>  $query    Decoded query parameters.
     * @param   string                $key      Control name.
     * @param   int                   $maximum  Maximum byte length.
     *
     * @return  string|null  Trimmed value, or null when empty.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $query, string $key, int $maximum): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maximum) {
            throw new InvalidArgumentException('A graphical business control is invalid or unbounded.');
        }

        return trim($value);
    }

    /**
     * Validate one field handle used by a native query control.
     *
     * @param   mixed  $field  Candidate handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function field(mixed $field): void
    {
        if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A graphical business query field is invalid.');
        }
    }

    /**
     * Validate one exact string value from a native scalar filter.
     *
     * @param   mixed  $value  Candidate field value.
     *
     * @return  string  Original bounded string.
     *
     * @since   2.0.0
     */
    private static function filterString(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 1024) {
            throw new InvalidArgumentException('A graphical business filter value is invalid or unbounded.');
        }

        return $value;
    }

    /**
     * Decode one canonical signed integer from a native typed filter.
     *
     * @param   mixed  $value  Candidate integer text.
     *
     * @return  int  Platform-safe exact integer.
     *
     * @since   2.0.0
     */
    private static function integer(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            throw new InvalidArgumentException('A graphical business integer filter is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer) || (string) $integer !== $value) {
            throw new InvalidArgumentException('A graphical business integer filter is outside its bound.');
        }

        return $integer;
    }

    /**
     * Decode one canonical boolean from a native typed filter.
     *
     * @param   mixed  $value  Candidate `true` or `false` text.
     *
     * @return  bool  Exact boolean value.
     *
     * @since   2.0.0
     */
    private static function boolean(mixed $value): bool
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException('A graphical business boolean filter is invalid.'),
        };
    }

    /**
     * Parse one positive integer within the native page-size ceiling.
     *
     * @param   mixed  $value    Candidate integer.
     * @param   int    $maximum  Inclusive ceiling.
     *
     * @return  int  Validated integer.
     *
     * @since   2.0.0
     */
    private static function positive(mixed $value, int $maximum): int
    {
        $integer = is_int($value)
            ? $value
            : (is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/D', $value) === 1 ? (int) $value : 0);
        if ($integer < 1 || $integer > $maximum) {
            throw new InvalidArgumentException('A graphical business page size is outside its bound.');
        }

        return $integer;
    }

    /**
     * Encode a document for a URL without losing exact string values.
     *
     * @param   array<string, mixed>  $document  Validated shared query document.
     *
     * @return  string  Compact JSON.
     *
     * @since   2.0.0
     */
    private static function encode(array $document): string
    {
        try {
            return json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A generated business query cannot be encoded.', 0, $exception);
        }
    }
}
