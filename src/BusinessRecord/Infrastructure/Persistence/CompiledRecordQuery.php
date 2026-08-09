<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * One record browse query compiled to SQL, its bindings, and the metadata needed to read the result.
 *
 * `DoctrineBusinessRecordQueryCompiler` produces this and `DoctrineBusinessRecordReadRepository`
 * executes it, so it is the whole handover between compiling a `RecordQuerySpecification` and running
 * it. Every identifier inside the statements was checked against the installed physical blueprint
 * while compiling and every caller-supplied value is bound, which is why each statement travels beside
 * its own parameter and type lists and why the constructor refuses a pair that is not the same length.
 * The remaining members carry what the SQL text alone cannot say: which field handles the projection
 * resolved to, which columns the next keyset cursor is read from, the digest that ties a cursor to this
 * exact query, and the optional aggregate statement.
 *
 * @since  2.0.0
 */
final readonly class CompiledRecordQuery
{
    /**
     * Values bound to the placeholders in $sql, in placeholder order.
     *
     * @var    list<mixed>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Doctrine type name for each entry of $parameters, at the same offset.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $types;

    /**
     * Field handles the projection resolved to, and so the physical columns $sql was built to select.
     *
     * A specification that restricts nothing expands to every read-visible field, and a formula field
     * drags its dependencies in beside itself, so this is wider than what the caller asked for.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $projectedFields;

    /**
     * Sort columns the next cursor reads off the last row of the page, in `ORDER BY` order.
     *
     * `field` names the definition field the sort came from, or is null for the implicit `updated_at`
     * ordering used when the specification asked for no sort; `physical` is the column to read.
     *
     * @var    list<array{field: ?string, physical: string}>
     * @since  2.0.0
     */
    public array $cursorColumns;

    /**
     * Values bound to the placeholders in $aggregateSql; empty when no aggregate was requested.
     *
     * @var    list<mixed>
     * @since  2.0.0
     */
    public array $aggregateParameters;

    /**
     * Doctrine type name for each entry of $aggregateParameters, at the same offset.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $aggregateTypes;

    /**
     * Assemble a compiled query, re-indexing its lists and proving the bindings line up.
     *
     * @param   string                                         $sql                  Page statement for one result page.
     *          It already carries the scope and lifecycle predicates, the `ORDER BY`, and a `LIMIT` of
     *          one more row than the page size, which is how the reader tells that a further page
     *          follows.
     * @param   string                                         $cursorDigest         Digest a cursor must match to run.
     *          It covers the definition version, schema checksum, scope and specification, so a cursor
     *          minted for a different query is refused rather than resumed.
     * @param   list<mixed>                                    $parameters           Values for the placeholders in
     *          $sql, in placeholder order.
     * @param   list<string>                                   $types                Doctrine type name per entry of
     *          $parameters; must be the same length.
     * @param   list<string>                                   $projectedFields      Field handles the SELECT covers,
     *          formula dependencies included.
     * @param   list<array{field: ?string, physical: string}>  $cursorColumns        Sort columns the next cursor
     *          is built from, in `ORDER BY` order.
     * @param   ?string                                        $aggregateSql         Aggregate statement, or null.
     *          It applies the same predicates without paging or cursor bounds, and is null exactly when
     *          the specification requested no aggregates.
     * @param   list<mixed>                                    $aggregateParameters  Values for the
     *          placeholders in $aggregateSql, in placeholder order.
     * @param   list<string>                                   $aggregateTypes       Doctrine type name per
     *          entry of $aggregateParameters; must be the same length.
     *
     * @throws  InvalidArgumentException  When a parameter list and its type list differ in length, which
     *          would leave a statement bound with the wrong types.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $sql,
        public string $cursorDigest,
        array $parameters,
        array $types,
        array $projectedFields,
        array $cursorColumns,
        public ?string $aggregateSql = null,
        array $aggregateParameters = [],
        array $aggregateTypes = [],
    ) {
        if (count($parameters) !== count($types) || count($aggregateParameters) !== count($aggregateTypes)) {
            throw new InvalidArgumentException('A compiled business-record query has mismatched bound parameters.');
        }
        $this->parameters = array_values($parameters);
        $this->types = array_values($types);
        $this->projectedFields = array_values($projectedFields);
        $this->cursorColumns = array_values($cursorColumns);
        $this->aggregateParameters = array_values($aggregateParameters);
        $this->aggregateTypes = array_values($aggregateTypes);
    }
}
