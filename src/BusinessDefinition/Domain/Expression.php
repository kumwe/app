<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Validated expression tree behind every business-definition condition and computed-field formula.
 *
 * A definition document carries its conditions and formulas as nested JSON objects, and `fromArray()` is
 * the only way to turn one into this type: it checks the operator vocabulary, the arity, the declared
 * result type, and the type agreement between an operator and its arguments, while bounding nesting
 * depth, node count, and canonical byte size. Holding an instance therefore means the tree has already
 * been proven well formed and bounded, which is what lets `RecordInvariantDefinition`, `FieldDefinition`,
 * `ActionDefinition`, and the persisted schema backfill and transform states use it without re-checking
 * anything. `ExpressionEvaluator` computes the value, `dependencies()` names the field handles a caller
 * must supply first, and `toArray()` returns the same document shape so a tree survives a definition
 * checksum or a stored migration state and comes back identical.
 *
 * One leaf reaches past the record's own fields. `line_aggregate` reduces an owned-line collection to a
 * single value — the count of its lines, or the sum of one line field — so a record invariant can state
 * the most fundamental document rule there is, that a header total agrees with its lines. It is
 * deliberately the narrowest thing that expresses that: one declared collection, one closed reduction,
 * one line field, inside the same byte, node and depth budget every other tree lives under.
 * `lineDependencies()` names what a caller must gather, and it is the caller's job to hand over the whole
 * prepared collection so the rule is judged once for the document rather than once per line.
 *
 * @since  2.0.0
 */
final readonly class Expression
{
    /**
     * Deepest nesting `parse()` descends into before it refuses the tree.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DEPTH = 12;

    /**
     * Largest number of nodes one condition or formula may be built from.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_OPERATIONS = 128;

    /**
     * Largest canonical encoding, in bytes, one condition or formula may occupy.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_BYTES = 32_768;

    /**
     * Supported operators, each mapped to the inclusive minimum and maximum argument count it accepts.
     *
     * Membership of this map is the operator vocabulary: an operator absent from it is rejected at parse
     * time. The `literal`, `field` and `line_aggregate` leaves are listed at zero arity because they carry
     * a value, a field handle, or a collection reduction instead of arguments.
     *
     * @var    array<string, array{int, int}>
     * @since  2.0.0
     */
    private const OPERATORS = [
        'literal' => [0, 0],
        'field' => [0, 0],
        'line_aggregate' => [0, 0],
        'eq' => [2, 2],
        'ne' => [2, 2],
        'lt' => [2, 2],
        'lte' => [2, 2],
        'gt' => [2, 2],
        'gte' => [2, 2],
        'and' => [2, 16],
        'or' => [2, 16],
        'not' => [1, 1],
        'add' => [2, 16],
        'subtract' => [2, 2],
        'multiply' => [2, 16],
        'divide' => [2, 2],
        'concat' => [2, 16],
        'coalesce' => [2, 16],
        'if' => [3, 3],
        'is_null' => [1, 1],
        'in' => [2, 32],
        'contains' => [2, 2],
    ];

    /**
     * The closed set of reductions a `line_aggregate` leaf may apply to an owned-line collection.
     *
     * Each entry maps the reduction to the result types it may declare. `count` measures the collection
     * itself and therefore names no line field; `sum` folds one declared line field and therefore
     * requires one. The set is deliberately this small: an invariant states a rule about a document, and
     * a general query language inside a published definition is not what a rule needs.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const LINE_AGGREGATES = [
        'count' => ['integer'],
        'sum' => ['integer', 'decimal'],
    ];

    /**
     * Operand nodes this expression applies its operator to, empty for a `literal` or `field` leaf.
     *
     * Assigned in the constructor rather than promoted like the other values, and read through
     * `arguments()`.
     *
     * @var    list<Expression>
     * @since  2.0.0
     */
    private array $arguments;

    /**
     * Assemble a node whose shape and types `parse()` has already accepted.
     *
     * Private on purpose: `fromArray()` is the only producer of a tree, and nothing here re-validates
     * what it is handed.
     *
     * @param  string            $operator   Operator name from the supported vocabulary, such as `and`.
     * @param  string            $type       Result type this node declares, such as `boolean`.
     * @param  list<Expression>  $arguments  Operand nodes in evaluation order; empty for a leaf.
     * @param  mixed             $literal    Constant a `literal` node carries; null for other operators.
     * @param  ?string           $field      Field handle a `field` node reads, or the line field a
     *         `line_aggregate` node folds; null for other operators.
     * @param  ?int              $scale      Decimal places a decimal `divide` rounds to; null otherwise.
     * @param  ?string           $lines      Owned-line relationship handle a `line_aggregate` node reduces
     *         over; null for other operators.
     * @param  ?string           $aggregate  Reduction a `line_aggregate` node applies, `count` or `sum`;
     *         null for other operators.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $operator,
        public string $type,
        array $arguments,
        public mixed $literal = null,
        public ?string $field = null,
        public ?int $scale = null,
        public ?string $lines = null,
        public ?string $aggregate = null,
    ) {
        $this->arguments = $arguments;
    }

    /**
     * Parse a canonical condition or formula document into a validated expression tree.
     *
     * The document is measured against the byte budget before it is walked, and the node budget is
     * re-checked once the walk finishes, so an oversized definition is refused rather than parsed.
     *
     * @param   array<string, mixed>  $document  Root expression object as it appears in the definition.
     *
     * @return  self  Root node of the parsed tree.
     *
     * @throws  InvalidBusinessDefinition  When the document holds a value the canonical encoder refuses,
     *          such as a float, when its encoding passes 32768 bytes, when the tree passes 128 nodes or
     *          12 levels, or when any node has an unsupported operator, type, shape, or arity.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (strlen(CanonicalDefinitionJson::encode($document)) > self::MAX_BYTES) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 32768 canonical bytes.');
        }
        $operations = 0;
        $expression = self::parse($document, 1, $operations);
        if ($operations > self::MAX_OPERATIONS) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 128 operations.');
        }

        return $expression;
    }

    /**
     * Return the operand nodes this expression applies its operator to.
     *
     * @return  list<Expression>  Child nodes in evaluation order; empty for a `literal` or `field` leaf.
     *
     * @since   2.0.0
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * Name the field handles the whole tree reads, so a caller can gather the values before evaluating.
     *
     * Callers depend on the deduplicated, sorted form: the schema gateway compares this list key for key
     * against the dependency column map it assembled for a backfill, and the record validator uses it to
     * decide when a computed field has everything it needs to be evaluated.
     *
     * @return  list<string>  Field handles in ascending string order, each appearing once; empty when
     *          the tree reads no field at all.
     *
     * @since   2.0.0
     */
    public function dependencies(): array
    {
        $dependencies = [];
        $this->collectDependencies($dependencies);
        $dependencies = array_values(array_unique($dependencies));
        sort($dependencies, SORT_STRING);

        return $dependencies;
    }

    /**
     * Name the owned-line collections this tree reduces over, and which line field each reduction reads.
     *
     * This is the counterpart of `dependencies()` for the collection half of a document rule. A caller
     * gathers the header's own values from `dependencies()` and the lines from here, so the whole rule is
     * evaluated once over a prepared line set rather than once per line. A `count` reduction contributes
     * its relationship handle with no field, because it measures the collection rather than a value in it.
     *
     * @return  array<string, list<string>>  Line field handles keyed by owned-line relationship handle,
     *          each list deduplicated and in ascending string order and possibly empty; the map itself is
     *          empty when the tree reduces over nothing, which is true of every tree written before this
     *          leaf existed.
     *
     * @since   2.0.0
     */
    public function lineDependencies(): array
    {
        /** @var array<string, list<string>> $collections */
        $collections = [];
        $this->collectLineDependencies($collections);
        foreach ($collections as $relationship => $fields) {
            $fields = array_values(array_unique($fields));
            sort($fields, SORT_STRING);
            $collections[$relationship] = $fields;
        }
        ksort($collections, SORT_STRING);

        return $collections;
    }

    /**
     * Compute this expression against one record's field values, and over its lines where it reduces them.
     *
     * @param   array<string, scalar|null>                       $fields  Values for the handles
     *          `dependencies()` names, keyed by field handle.
     * @param   array<string, list<array<string, scalar|null>>>  $lines   Prepared line values keyed by the
     *          owned-line relationship handles `lineDependencies()` names; each entry is the whole
     *          collection as the command is about to store it, in position order.
     *
     * @return  mixed  Result of the root operator in this node's declared type; a decimal result is a
     *          canonical base-10 string, never a float.
     *
     * @throws  InvalidBusinessDefinition  When a dependency is absent, a supplied value contradicts its
     *          declared type, or the arithmetic overflows or divides by zero.
     *
     * @since   2.0.0
     */
    public function evaluate(array $fields, array $lines = []): mixed
    {
        return ExpressionEvaluator::evaluate($this, $fields, $lines);
    }

    /**
     * Render the tree back into the canonical document a definition stores and checksums.
     *
     * The output is accepted by `fromArray()` unchanged, which is what lets a schema plan carry an
     * expression inside its persisted state and rebuild the same tree in a later request.
     *
     * @return  array<string, mixed>  Node keyed by `op` and `type`, plus `value` for a literal, `field`
     *          for a field reference, `lines` and `aggregate` — and `field` where the reduction folds
     *          one — for a line aggregation, or nested `args` for an operator, and `scale` where one is set.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $document = ['op' => $this->operator, 'type' => $this->type];
        if ($this->operator === 'literal') {
            $document['value'] = $this->literal;
        } elseif ($this->operator === 'field') {
            $document['field'] = $this->field;
        } elseif ($this->operator === 'line_aggregate') {
            $document['lines'] = $this->lines;
            $document['aggregate'] = $this->aggregate;
            if ($this->field !== null) {
                $document['field'] = $this->field;
            }
        } else {
            $document['args'] = array_map(static fn (self $item): array => $item->toArray(), $this->arguments);
        }
        if ($this->scale !== null) {
            $document['scale'] = $this->scale;
        }

        return $document;
    }

    /**
     * Validate one node and everything beneath it, then build the immutable expression for it.
     *
     * Depth is counted down the descent and the node total is carried by reference across it, so a tree
     * is refused the moment it crosses a limit rather than after the whole document has been walked.
     * Each operator family is then held to its own shape: a literal carries exactly `value`, a field
     * reference exactly `field`, a line aggregation exactly `lines`, `aggregate` and — for a reduction
     * that folds a value — `field`, and everything else exactly `args` with an optional `scale`.
     *
     * @param   array<string, mixed>  $document    Node object to validate at this level of the descent.
     * @param   int                   $depth       Nesting level of this node; the root is passed as 1.
     * @param   int                   $operations  Running node count, incremented here for this node.
     *
     * @return  self  This node, with its arguments already parsed and type-checked.
     *
     * @throws  InvalidBusinessDefinition  When the node nests past 12 levels, pushes the tree past 128
     *          nodes, carries an unknown property, or breaks an operator, type, shape, arity, or scale
     *          rule — including a decimal division that omits its output scale.
     *
     * @since   2.0.0
     */
    private static function parse(array $document, int $depth, int &$operations): self
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 12 expression levels.');
        }
        $unknown = array_diff(
            array_keys($document),
            ['op', 'type', 'args', 'value', 'field', 'scale', 'lines', 'aggregate'],
        );
        if ($unknown !== []) {
            throw new InvalidBusinessDefinition('A condition or formula contains an unknown property.');
        }
        $operator = $document['op'] ?? null;
        $type = $document['type'] ?? null;
        if (!is_string($operator) || !isset(self::OPERATORS[$operator])) {
            throw new InvalidBusinessDefinition('A condition or formula operator is unsupported.');
        }
        if (
            !is_string($type) || !in_array(
                $type,
                ['any', 'null', 'boolean', 'integer', 'decimal', 'string', 'date', 'time', 'datetime'],
                true,
            )
        ) {
            throw new InvalidBusinessDefinition('A condition or formula type is unsupported.');
        }
        ++$operations;
        if ($operations > self::MAX_OPERATIONS) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 128 operations.');
        }
        if ($operator === 'literal') {
            if (
                array_diff(array_keys($document), ['op', 'type', 'value']) !== []
                || !array_key_exists('value', $document)
            ) {
                throw new InvalidBusinessDefinition('A literal expression has an invalid shape.');
            }
            $value = $document['value'];
            self::assertLiteral($type, $value);

            return new self($operator, $type, [], $value);
        }
        if ($operator === 'field') {
            if (array_diff(array_keys($document), ['op', 'type', 'field']) !== []) {
                throw new InvalidBusinessDefinition('A field expression has an invalid shape.');
            }
            $field = $document['field'] ?? null;
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
                throw new InvalidBusinessDefinition('A field expression references an invalid field.');
            }

            return new self($operator, $type, [], null, $field);
        }
        if ($operator === 'line_aggregate') {
            return self::parseLineAggregate($document, $type);
        }
        if (array_diff(array_keys($document), ['op', 'type', 'args', 'scale']) !== []) {
            throw new InvalidBusinessDefinition('An operator expression has an invalid shape.');
        }
        $arguments = $document['args'] ?? null;
        if (!is_array($arguments) || !array_is_list($arguments)) {
            throw new InvalidBusinessDefinition('An operator expression requires an argument list.');
        }
        [$minimum, $maximum] = self::OPERATORS[$operator];
        if (count($arguments) < $minimum || count($arguments) > $maximum) {
            throw new InvalidBusinessDefinition(sprintf('Expression operator %s has an invalid arity.', $operator));
        }
        $parsed = [];
        foreach ($arguments as $argument) {
            if (!is_array($argument) || array_is_list($argument)) {
                throw new InvalidBusinessDefinition('Every expression argument must be an object.');
            }
            /** @var array<string, mixed> $argument */
            $parsed[] = self::parse($argument, $depth + 1, $operations);
        }
        $scale = $document['scale'] ?? null;
        if (
            $scale !== null && (!is_int($scale) || $scale < 0 || $scale > 30
            || $operator !== 'divide' || $type !== 'decimal')
        ) {
            throw new InvalidBusinessDefinition('Expression scale is supported only for decimal division.');
        }
        if ($operator === 'divide' && $type === 'decimal' && !is_int($scale)) {
            throw new InvalidBusinessDefinition('Decimal division requires an explicit output scale.');
        }
        self::assertOperatorType($operator, $type, $parsed);

        return new self($operator, $type, $parsed, null, null, $scale);
    }

    /**
     * Validate a `line_aggregate` leaf and build the immutable node for it.
     *
     * The leaf is deliberately narrow, because a rule about a document is not a query over it: it names
     * exactly one owned-line relationship, applies exactly one reduction from a closed set, and folds at
     * most one line field. `count` measures the collection and so must carry no field; `sum` folds a value
     * and so must carry one. The result type is held to what the reduction can honestly produce, which is
     * what keeps the surrounding comparison exact — a summed decimal stays a canonical decimal string and
     * never becomes a float on the way into a `gte`.
     *
     * @param   array<string, mixed>  $document  Node object as it appears in the definition.
     * @param   string                $type      Result type the node declares, already in the supported set.
     *
     * @return  self  The aggregation leaf.
     *
     * @throws  InvalidBusinessDefinition  When the node carries a property this leaf does not take, names
     *          an unsupported reduction, spells the relationship or field handle wrongly, declares a result
     *          type the reduction cannot produce, or supplies a field where the reduction takes none or
     *          omits one where it requires it.
     *
     * @since   2.0.0
     */
    private static function parseLineAggregate(array $document, string $type): self
    {
        if (array_diff(array_keys($document), ['op', 'type', 'lines', 'aggregate', 'field']) !== []) {
            throw new InvalidBusinessDefinition('A line aggregation has an invalid shape.');
        }
        $aggregate = $document['aggregate'] ?? null;
        if (!is_string($aggregate) || !isset(self::LINE_AGGREGATES[$aggregate])) {
            throw new InvalidBusinessDefinition('A line aggregation names an unsupported reduction.');
        }
        $lines = $document['lines'] ?? null;
        if (!is_string($lines) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $lines) !== 1) {
            throw new InvalidBusinessDefinition('A line aggregation references an invalid owned-line collection.');
        }
        if (!in_array($type, self::LINE_AGGREGATES[$aggregate], true)) {
            throw new InvalidBusinessDefinition(sprintf(
                'Line aggregation %s cannot produce %s.',
                $aggregate,
                $type,
            ));
        }
        $field = $document['field'] ?? null;
        if ($aggregate === 'count') {
            if ($field !== null) {
                throw new InvalidBusinessDefinition('A line count measures the collection and takes no field.');
            }

            return new self('line_aggregate', $type, [], null, null, null, $lines, $aggregate);
        }
        if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidBusinessDefinition('A line aggregation references an invalid line field.');
        }

        return new self('line_aggregate', $type, [], null, $field, null, $lines, $aggregate);
    }

    /**
     * Refuse a literal whose decoded value does not match the type its node declares.
     *
     * A `decimal` literal is held to the canonical base-10 spelling rather than to a PHP number, and the
     * textual types are capped at 4096 bytes so a definition cannot smuggle a large payload in as a
     * constant.
     *
     * @param   string  $type   Type the literal node declares, such as `integer` or `decimal`.
     * @param   mixed   $value  Decoded literal to hold against that type.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the value is of the wrong kind, is not a canonical decimal
     *          string, or exceeds 4096 bytes of text.
     *
     * @since   2.0.0
     */
    private static function assertLiteral(string $type, mixed $value): void
    {
        $valid = match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'decimal' => is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1,
            'string', 'date', 'time', 'datetime' => is_string($value) && strlen($value) <= 4096,
            'any' => is_null($value) || is_bool($value) || is_int($value) || is_string($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidBusinessDefinition('An expression literal does not match its declared type.');
        }
    }

    /**
     * Enforce the type algebra an operator imposes on its own result and on its arguments.
     *
     * Every rule lands here at parse time so evaluation can trust the tree: comparison and logic
     * operators must declare `boolean`, logic arguments must themselves be boolean, arithmetic must
     * declare `integer` or `decimal` and take arguments of that same type, ordered comparison is limited
     * to types that have a natural order, and `if` and `coalesce` branches must be assignable to the
     * declared result.
     *
     * @param   string            $operator   Operator whose rules are being applied.
     * @param   string            $type       Result type the node declares.
     * @param   list<Expression>  $arguments  Already parsed argument nodes, in declaration order.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the declared result type or any argument type is
     *          incompatible with the operator.
     *
     * @since   2.0.0
     */
    private static function assertOperatorType(string $operator, string $type, array $arguments): void
    {
        if (
            in_array(
                $operator,
                ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'and', 'or', 'not', 'is_null', 'in', 'contains'],
                true,
            )
            && $type !== 'boolean'
        ) {
            throw new InvalidBusinessDefinition(sprintf('Expression operator %s must produce boolean.', $operator));
        }
        if (in_array($operator, ['and', 'or', 'not'], true)) {
            foreach ($arguments as $argument) {
                if ($argument->type !== 'boolean') {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Expression operator %s requires boolean arguments.',
                        $operator,
                    ));
                }
            }
        }
        if (in_array($operator, ['add', 'subtract', 'multiply', 'divide'], true)) {
            if (!in_array($type, ['integer', 'decimal'], true)) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Expression operator %s requires a numeric result.',
                    $operator,
                ));
            }
            foreach ($arguments as $argument) {
                if ($argument->type !== $type) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Expression operator %s has incompatible types.',
                        $operator,
                    ));
                }
            }
        }
        if (in_array($operator, ['eq', 'ne'], true)) {
            self::assertSameArgumentTypes($operator, $arguments);
        }
        if (in_array($operator, ['lt', 'lte', 'gt', 'gte'], true)) {
            self::assertSameArgumentTypes($operator, $arguments);
            if (!in_array($arguments[0]->type, ['integer', 'decimal', 'string', 'date', 'time', 'datetime'], true)) {
                throw new InvalidBusinessDefinition('Ordered comparison arguments have an unsupported type.');
            }
        }
        if ($operator === 'contains') {
            self::assertResultAndArgumentTypes($operator, $type, 'boolean', $arguments, ['string']);
        }
        if ($operator === 'concat') {
            self::assertResultAndArgumentTypes($operator, $type, 'string', $arguments, ['string']);
        }
        if ($operator === 'in') {
            self::assertSameArgumentTypes($operator, $arguments);
        }
        if ($operator === 'if') {
            if (
                $arguments[0]->type !== 'boolean'
                || !self::resultCompatible($type, $arguments[1]->type)
                || !self::resultCompatible($type, $arguments[2]->type)
            ) {
                throw new InvalidBusinessDefinition('Expression operator if has incompatible argument types.');
            }
        }
        if ($operator === 'coalesce') {
            foreach ($arguments as $argument) {
                if (!self::resultCompatible($type, $argument->type)) {
                    throw new InvalidBusinessDefinition('Expression operator coalesce has incompatible types.');
                }
            }
        }
    }

    /**
     * Require every argument of an operator to declare the same type as the first one.
     *
     * This is what makes the identity comparisons in the evaluator safe: `eq`, `ne`, and `in` compare
     * with `===`, which would otherwise silently report a mismatched pair as unequal instead of refusing
     * the expression.
     *
     * @param   string            $operator   Operator named in the failure message.
     * @param   list<Expression>  $arguments  Argument nodes; the first one sets the expected type.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When any argument declares a different type.
     *
     * @since   2.0.0
     */
    private static function assertSameArgumentTypes(string $operator, array $arguments): void
    {
        $expected = $arguments[0]->type;
        foreach ($arguments as $argument) {
            if ($argument->type !== $expected) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Expression operator %s has incompatible argument types.',
                    $operator,
                ));
            }
        }
    }

    /**
     * Pin an operator to one result type and restrict its arguments to a fixed set of types.
     *
     * Used for the operators whose signature is fixed rather than inferred from their operands, namely
     * `contains` and `concat`.
     *
     * @param   string            $operator       Operator named in the failure message.
     * @param   string            $type           Result type the node declares.
     * @param   string            $resultType     The only result type this operator is allowed to declare.
     * @param   list<Expression>  $arguments      Argument nodes to hold against the allowed types.
     * @param   list<string>      $argumentTypes  Types an argument of this operator may declare.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the declared result type differs from the required one, or
     *          an argument declares a type outside the allowed set.
     *
     * @since   2.0.0
     */
    private static function assertResultAndArgumentTypes(
        string $operator,
        string $type,
        string $resultType,
        array $arguments,
        array $argumentTypes,
    ): void {
        if ($type !== $resultType) {
            throw new InvalidBusinessDefinition(sprintf(
                'Expression operator %s has an incompatible result type.',
                $operator,
            ));
        }
        foreach ($arguments as $argument) {
            if (!in_array($argument->type, $argumentTypes, true)) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Expression operator %s has incompatible argument types.',
                    $operator,
                ));
            }
        }
    }

    /**
     * Decide whether a branch may stand in for the result type of `if` or `coalesce`.
     *
     * A `null` branch is always accepted, which is what gives `coalesce` something to fall through, and
     * an `any` result accepts every branch.
     *
     * @param   string  $resultType    Result type the node declares.
     * @param   string  $argumentType  Type of the branch being offered for that result.
     *
     * @return  bool  True when the branch may produce the node's result.
     *
     * @since   2.0.0
     */
    private static function resultCompatible(string $resultType, string $argumentType): bool
    {
        return $resultType === 'any'
            || $argumentType === $resultType
            || $argumentType === 'null';
    }

    /**
     * Append this node's field handle, then those of its subtree, to the accumulator.
     *
     * Walks in argument order and repeats a handle each time it is read; `dependencies()` owns the
     * deduplication and the ordering.
     *
     * @param   list<string>  $dependencies  Accumulator appended to in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function collectDependencies(array &$dependencies): void
    {
        if ($this->operator === 'field' && $this->field !== null) {
            $dependencies[] = $this->field;
        }
        foreach ($this->arguments as $argument) {
            $argument->collectDependencies($dependencies);
        }
    }

    /**
     * Append this node's owned-line reduction, then those of its subtree, to the accumulator.
     *
     * A relationship handle is recorded even when the reduction folds no field, so a `count` still tells a
     * caller which collection it has to gather. `lineDependencies()` owns the deduplication and ordering.
     *
     * @param   array<string, list<string>>  $collections  Accumulator appended to in place, keyed by
     *          owned-line relationship handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function collectLineDependencies(array &$collections): void
    {
        if ($this->operator === 'line_aggregate' && $this->lines !== null) {
            $collections[$this->lines] ??= [];
            if ($this->field !== null) {
                $collections[$this->lines][] = $this->field;
            }
        }
        foreach ($this->arguments as $argument) {
            $argument->collectLineDependencies($collections);
        }
    }
}
