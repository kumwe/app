<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessRecord\Query\AggregateFunction;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\NullFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordAggregate;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordSearch;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordSort;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationQuantifier;
use Kumwe\Extension\Spi\BusinessRecord\Query\SetFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\SortDirection;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextOperator;

/**
 * Compiles one transport-neutral record-query document into the bounded domain query tree.
 *
 * Administrator forms, REST, CLI and MCP all hand their decoded input to this factory. The domain
 * constructors remain the authority for depth, operation, projection, cursor and page limits; this
 * class adds a closed wire grammar so an adapter cannot silently ignore a misspelled or future key.
 * Values are never coerced, which in particular keeps floating-point input out of exact business
 * comparisons.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordQueryFactory
{
    /**
     * Build a query specification from a decoded JSON-like document.
     *
     * @param   array<string, mixed>  $document  Closed query document shared by every delivery adapter.
     *
     * @return  RecordQuerySpecification  Validated bounded query ready for the application service.
     *
     * @throws  InvalidArgumentException  When a member has the wrong shape or an unknown key is present.
     *
     * @since   2.0.0
     */
    public function create(array $document = []): RecordQuerySpecification
    {
        self::known($document, [
            'filter', 'search', 'sorts', 'after', 'page_size', 'projection',
            'include_archived', 'include_deleted',
        ], 'query');

        $filter = $document['filter'] ?? null;
        $search = $document['search'] ?? null;
        $sorts = $document['sorts'] ?? [];
        $projection = $document['projection'] ?? [];

        if (!is_array($sorts) || !array_is_list($sorts)) {
            throw new InvalidArgumentException('Business-record sorts must be a list.');
        }
        $filter = $filter === null ? null : self::decodedObject($filter, 'filter');
        $search = $search === null ? null : self::decodedObject($search, 'search');
        $projection = self::decodedObject($projection, 'projection');

        return new RecordQuerySpecification(
            $filter === null ? null : $this->filter($filter),
            $search === null ? null : $this->search($search),
            array_map($this->sort(...), $sorts),
            $this->cursor($document['after'] ?? null),
            self::integer($document, 'page_size', 50),
            $this->projection($projection),
            self::boolean($document, 'include_archived'),
            self::boolean($document, 'include_deleted'),
        );
    }

    /**
     * Compile one recursive filter node.
     *
     * @param   array<string, mixed>  $node  Filter object carrying an explicit `type` discriminator.
     *
     * @return  RecordFilter  Validated leaf, group, or bounded relation traversal.
     *
     * @throws  InvalidArgumentException  When the discriminator or node shape is invalid.
     *
     * @since   2.0.0
     */
    private function filter(array $node): RecordFilter
    {
        $type = self::string($node, 'type');

        return match ($type) {
            'comparison' => $this->comparison($node),
            'text' => $this->text($node),
            'set' => $this->set($node),
            'null' => $this->null($node),
            'boolean' => $this->booleanFilter($node),
            'relation' => $this->relation($node),
            default => throw new InvalidArgumentException('A business-record filter type is unsupported.'),
        };
    }

    /**
     * Compile an exact or ordered comparison.
     *
     * @param   array<string, mixed>  $node  Comparison node from the shared wire grammar.
     *
     * @return  ComparisonFilter  Validated comparison.
     *
     * @since   2.0.0
     */
    private function comparison(array $node): ComparisonFilter
    {
        self::known($node, ['type', 'field', 'operator', 'value'], 'comparison filter');
        if (!array_key_exists('value', $node)) {
            throw new InvalidArgumentException('A comparison filter requires a value.');
        }

        return new ComparisonFilter(
            self::string($node, 'field'),
            ComparisonOperator::tryFrom(self::string($node, 'operator'))
                ?? throw new InvalidArgumentException('A comparison operator is unsupported.'),
            $node['value'],
        );
    }

    /**
     * Compile a text match.
     *
     * @param   array<string, mixed>  $node  Text node from the shared wire grammar.
     *
     * @return  TextFilter  Validated text predicate.
     *
     * @since   2.0.0
     */
    private function text(array $node): TextFilter
    {
        self::known($node, ['type', 'field', 'operator', 'text'], 'text filter');

        return new TextFilter(
            self::string($node, 'field'),
            TextOperator::tryFrom(self::string($node, 'operator'))
                ?? throw new InvalidArgumentException('A text operator is unsupported.'),
            self::string($node, 'text'),
        );
    }

    /**
     * Compile a bounded membership predicate.
     *
     * @param   array<string, mixed>  $node  Set node from the shared wire grammar.
     *
     * @return  SetFilter  Validated set predicate.
     *
     * @since   2.0.0
     */
    private function set(array $node): SetFilter
    {
        self::known($node, ['type', 'field', 'values', 'negated'], 'set filter');
        $values = self::list($node, 'values');
        if ($values === []) {
            throw new InvalidArgumentException('A set filter requires at least one value.');
        }

        return new SetFilter(
            self::string($node, 'field'),
            $values,
            self::boolean($node, 'negated'),
        );
    }

    /**
     * Compile an explicit null test.
     *
     * @param   array<string, mixed>  $node  Null node from the shared wire grammar.
     *
     * @return  NullFilter  Validated null predicate.
     *
     * @since   2.0.0
     */
    private function null(array $node): NullFilter
    {
        self::known($node, ['type', 'field', 'is_null'], 'null filter');

        return new NullFilter(self::string($node, 'field'), self::boolean($node, 'is_null', true));
    }

    /**
     * Compile a bounded boolean group.
     *
     * @param   array<string, mixed>  $node  Group node from the shared wire grammar.
     *
     * @return  BooleanFilter  Validated recursive group.
     *
     * @since   2.0.0
     */
    private function booleanFilter(array $node): BooleanFilter
    {
        self::known($node, ['type', 'operator', 'children'], 'boolean filter');
        $children = self::objectList($node, 'children');
        if ($children === []) {
            throw new InvalidArgumentException('A boolean filter requires at least one child.');
        }

        return new BooleanFilter(
            BooleanOperator::tryFrom(self::string($node, 'operator'))
                ?? throw new InvalidArgumentException('A boolean operator is unsupported.'),
            array_map($this->filter(...), $children),
        );
    }

    /**
     * Compile a relation traversal with its nested target predicate.
     *
     * @param   array<string, mixed>  $node  Relation node from the shared wire grammar.
     *
     * @return  RelationFilter  Validated bounded traversal.
     *
     * @since   2.0.0
     */
    private function relation(array $node): RelationFilter
    {
        self::known($node, ['type', 'relationship', 'quantifier', 'target'], 'relation filter');
        $target = self::object($node, 'target');

        return new RelationFilter(
            self::string($node, 'relationship'),
            RelationQuantifier::tryFrom(self::string($node, 'quantifier'))
                ?? throw new InvalidArgumentException('A relation quantifier is unsupported.'),
            $this->filter($target),
        );
    }

    /**
     * Compile the optional full-text-like search declaration.
     *
     * @param   array<string, mixed>  $document  Search object with a term and explicit field allow-list.
     *
     * @return  RecordSearch  Validated search declaration.
     *
     * @since   2.0.0
     */
    private function search(array $document): RecordSearch
    {
        self::known($document, ['term', 'fields'], 'search');
        $fields = self::stringList($document, 'fields');
        if ($fields === []) {
            throw new InvalidArgumentException('A business-record search requires at least one field.');
        }

        return new RecordSearch(
            self::string($document, 'term'),
            $fields,
        );
    }

    /**
     * Compile one sort declaration.
     *
     * @param   mixed  $document  Decoded sort member.
     *
     * @return  RecordSort  Validated ordering choice.
     *
     * @throws  InvalidArgumentException  When the member is not an object.
     *
     * @since   2.0.0
     */
    private function sort(mixed $document): RecordSort
    {
        $document = self::decodedObject($document, 'sort');
        self::known($document, ['field', 'direction', 'nulls_last'], 'sort');

        return new RecordSort(
            self::string($document, 'field'),
            SortDirection::tryFrom(self::optionalString($document, 'direction', 'asc'))
                ?? throw new InvalidArgumentException('A sort direction is unsupported.'),
            self::boolean($document, 'nulls_last', true),
        );
    }

    /**
     * Compile the projection, relation includes and optional aggregate declarations.
     *
     * @param   array<string, mixed>  $document  Projection object, or the empty default.
     *
     * @return  RecordProjection  Validated projection.
     *
     * @since   2.0.0
     */
    private function projection(array $document): RecordProjection
    {
        self::known($document, ['fields', 'includes', 'aggregates'], 'projection');
        $aggregates = $document['aggregates'] ?? [];
        if (!is_array($aggregates) || !array_is_list($aggregates)) {
            throw new InvalidArgumentException('Business-record aggregates must be a list.');
        }

        return new RecordProjection(
            self::stringList($document, 'fields', []),
            self::stringList($document, 'includes', []),
            array_map($this->aggregate(...), $aggregates),
        );
    }

    /**
     * Compile one aggregate declaration.
     *
     * @param   mixed  $document  Decoded aggregate member.
     *
     * @return  RecordAggregate  Validated aggregate.
     *
     * @throws  InvalidArgumentException  When the member is not an object.
     *
     * @since   2.0.0
     */
    private function aggregate(mixed $document): RecordAggregate
    {
        $document = self::decodedObject($document, 'aggregate');
        self::known($document, ['alias', 'function', 'field'], 'aggregate');
        $field = $document['field'] ?? null;
        if ($field !== null && !is_string($field)) {
            throw new InvalidArgumentException('An aggregate field must be a string or null.');
        }

        return new RecordAggregate(
            self::string($document, 'alias'),
            AggregateFunction::tryFrom(self::string($document, 'function'))
                ?? throw new InvalidArgumentException('An aggregate function is unsupported.'),
            $field,
        );
    }

    /**
     * Parse an optional opaque continuation cursor.
     *
     * @param   mixed  $value  Cursor token or null.
     *
     * @return  RecordCursor|null  Validated cursor when supplied.
     *
     * @throws  InvalidArgumentException  When the cursor is not a string.
     *
     * @since   2.0.0
     */
    private function cursor(mixed $value): ?RecordCursor
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A business-record cursor must be a string.');
        }

        return RecordCursor::fromString($value);
    }

    /**
     * Read a required non-empty string.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Required member name.
     *
     * @return  string  Original string value, never empty.
     *
     * @throws  InvalidArgumentException  When absent, non-string, or empty.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Business-record query property ' . $key . ' is required.');
        }

        return $value;
    }

    /**
     * Read an optional string with a default.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Optional member name.
     * @param   string                $default   Value used when absent.
     *
     * @return  string  Declared or default value.
     *
     * @throws  InvalidArgumentException  When a present value is not a string.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $document, string $key, string $default): string
    {
        if (!array_key_exists($key, $document)) {
            return $default;
        }
        if (!is_string($document[$key])) {
            throw new InvalidArgumentException('Business-record query property ' . $key . ' must be a string.');
        }

        return $document[$key];
    }

    /**
     * Read an integer without coercion.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Optional member name.
     * @param   int                   $default   Value used when absent.
     *
     * @return  int  Declared or default value.
     *
     * @throws  InvalidArgumentException  When a present value is not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key, int $default): int
    {
        if (!array_key_exists($key, $document)) {
            return $default;
        }
        if (!is_int($document[$key])) {
            throw new InvalidArgumentException('Business-record query property ' . $key . ' must be an integer.');
        }

        return $document[$key];
    }

    /**
     * Read a boolean without truthy coercion.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Optional member name.
     * @param   bool                  $default   Value used when absent.
     *
     * @return  bool  Declared or default value.
     *
     * @throws  InvalidArgumentException  When a present value is not boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $document)) {
            return $default;
        }
        if (!is_bool($document[$key])) {
            throw new InvalidArgumentException('Business-record query property ' . $key . ' must be boolean.');
        }

        return $document[$key];
    }

    /**
     * Read a list without changing its values.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Required member name.
     *
     * @return  list<mixed>  Re-indexed list.
     *
     * @throws  InvalidArgumentException  When absent or not a list.
     *
     * @since   2.0.0
     */
    private static function list(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Business-record query property ' . $key . ' must be a list.');
        }

        return $value;
    }

    /**
     * Read a list containing strings only.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Member name.
     * @param   list<string>|null     $default   Default list, or null to require the member.
     *
     * @return  list<string>  Validated string list.
     *
     * @throws  InvalidArgumentException  When the member or one of its values has the wrong type.
     *
     * @since   2.0.0
     */
    private static function stringList(array $document, string $key, ?array $default = null): array
    {
        if (!array_key_exists($key, $document) && $default !== null) {
            return $default;
        }
        $values = self::list($document, $key);
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    'Business-record query property ' . $key . ' must contain only strings.',
                );
            }
        }

        return $values;
    }

    /**
     * Read a required object member.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Required member name.
     *
     * @return  array<string, mixed>  Validated object.
     *
     * @throws  InvalidArgumentException  When absent, not an array, or a list.
     *
     * @since   2.0.0
     */
    private static function object(array $document, string $key): array
    {
        return self::decodedObject($document[$key] ?? null, $key);
    }

    /**
     * Read a list containing objects only.
     *
     * @param   array<string, mixed>  $document  Object being decoded.
     * @param   string                $key       Required member name.
     *
     * @return  list<array<string, mixed>>  Validated object list.
     *
     * @throws  InvalidArgumentException  When the list or a member has the wrong shape.
     *
     * @since   2.0.0
     */
    private static function objectList(array $document, string $key): array
    {
        $values = self::list($document, $key);
        $objects = [];
        foreach ($values as $value) {
            $objects[] = self::decodedObject($value, $key . ' member');
        }

        return $objects;
    }

    /**
     * Normalize one decoded JSON object and prove every PHP array key is textual.
     *
     * Empty JSON objects decode to an empty PHP array and therefore remain valid even though PHP also
     * classifies that representation as a list. Non-empty lists and integer-keyed maps are rejected.
     *
     * @param   mixed   $value  Candidate decoded object.
     * @param   string  $kind   Stable grammar name used in the validation failure.
     *
     * @return  array<string, mixed>  Validated object map.
     *
     * @throws  InvalidArgumentException  When the value is not an object map.
     *
     * @since   2.0.0
     */
    private static function decodedObject(mixed $value, string $kind): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('A business-record ' . $kind . ' must be an object.');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('A business-record ' . $kind . ' must be an object.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Refuse keys outside one grammar production.
     *
     * @param   array<string, mixed>  $document  Object whose keys are inspected.
     * @param   list<string>          $allowed   Complete key allow-list.
     * @param   string                $kind      Human-readable production name for the failure.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an unknown key is present.
     *
     * @since   2.0.0
     */
    private static function known(array $document, array $allowed, string $kind): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidArgumentException('A business-record ' . $kind . ' contains an unknown property.');
        }
    }
}
