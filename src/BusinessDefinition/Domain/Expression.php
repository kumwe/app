<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class Expression
{
    private const MAX_DEPTH = 12;

    private const MAX_OPERATIONS = 128;

    private const MAX_BYTES = 32_768;

    private const OPERATORS = [
        'literal' => [0, 0],
        'field' => [0, 0],
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

    /** @var list<Expression> */
    private array $arguments;

    private function __construct(
        public string $operator,
        public string $type,
        array $arguments,
        public mixed $literal = null,
        public ?string $field = null,
        public ?int $scale = null,
    ) {
        $this->arguments = $arguments;
    }

    /** @param array<string, mixed> $document */
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

    /** @return list<Expression> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        $dependencies = [];
        $this->collectDependencies($dependencies);
        $dependencies = array_values(array_unique($dependencies));
        sort($dependencies, SORT_STRING);

        return $dependencies;
    }

    /** @param array<string, scalar|null> $fields */
    public function evaluate(array $fields): mixed
    {
        return ExpressionEvaluator::evaluate($this, $fields);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $document = ['op' => $this->operator, 'type' => $this->type];
        if ($this->operator === 'literal') {
            $document['value'] = $this->literal;
        } elseif ($this->operator === 'field') {
            $document['field'] = $this->field;
        } else {
            $document['args'] = array_map(static fn (self $item): array => $item->toArray(), $this->arguments);
        }
        if ($this->scale !== null) {
            $document['scale'] = $this->scale;
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function parse(array $document, int $depth, int &$operations): self
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 12 expression levels.');
        }
        $unknown = array_diff(array_keys($document), ['op', 'type', 'args', 'value', 'field', 'scale']);
        if ($unknown !== []) {
            throw new InvalidBusinessDefinition('A condition or formula contains an unknown property.');
        }
        $operator = $document['op'] ?? null;
        $type = $document['type'] ?? null;
        if (!is_string($operator) || !isset(self::OPERATORS[$operator])) {
            throw new InvalidBusinessDefinition('A condition or formula operator is unsupported.');
        }
        if (!is_string($type) || !in_array(
            $type,
            ['any', 'null', 'boolean', 'integer', 'decimal', 'string', 'date', 'time', 'datetime'],
            true,
        )) {
            throw new InvalidBusinessDefinition('A condition or formula type is unsupported.');
        }
        ++$operations;
        if ($operations > self::MAX_OPERATIONS) {
            throw new InvalidBusinessDefinition('A condition or formula exceeds 128 operations.');
        }
        if ($operator === 'literal') {
            if (array_diff(array_keys($document), ['op', 'type', 'value']) !== []
                || !array_key_exists('value', $document)) {
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
        if ($scale !== null && (!is_int($scale) || $scale < 0 || $scale > 30
            || $operator !== 'divide' || $type !== 'decimal')) {
            throw new InvalidBusinessDefinition('Expression scale is supported only for decimal division.');
        }
        if ($operator === 'divide' && $type === 'decimal' && !is_int($scale)) {
            throw new InvalidBusinessDefinition('Decimal division requires an explicit output scale.');
        }
        self::assertOperatorType($operator, $type, $parsed);

        return new self($operator, $type, $parsed, null, null, $scale);
    }

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

    /** @param list<Expression> $arguments */
    private static function assertOperatorType(string $operator, string $type, array $arguments): void
    {
        if (in_array(
            $operator,
            ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'and', 'or', 'not', 'is_null', 'in', 'contains'],
            true,
        )
            && $type !== 'boolean') {
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
            if ($arguments[0]->type !== 'boolean'
                || !self::resultCompatible($type, $arguments[1]->type)
                || !self::resultCompatible($type, $arguments[2]->type)) {
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

    /** @param list<Expression> $arguments */
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

    /** @param list<Expression> $arguments @param list<string> $argumentTypes */
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

    private static function resultCompatible(string $resultType, string $argumentType): bool
    {
        return $resultType === 'any'
            || $argumentType === $resultType
            || $argumentType === 'null';
    }

    /** @param list<string> $dependencies */
    private function collectDependencies(array &$dependencies): void
    {
        if ($this->field !== null) {
            $dependencies[] = $this->field;
        }
        foreach ($this->arguments as $argument) {
            $argument->collectDependencies($dependencies);
        }
    }
}
