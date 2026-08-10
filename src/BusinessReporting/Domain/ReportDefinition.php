<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\Extension\Contribution\ContributionDefinition;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Immutable, bounded and manifest-comparable business report definition.
 *
 * It contains logical handles and validated expression trees only. Physical tables, SQL fragments,
 * callbacks and delivery URLs have no representation in this model.
 *
 * @since  2.0.0
 */
final readonly class ReportDefinition implements ContributionDefinition
{
    /**
     * Parameters validated in declaration order.
     *
     * @var    list<ReportParameterDefinition>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Filters validated in declaration order.
     *
     * @var    list<ReportFilterDefinition>
     * @since  2.0.0
     */
    public array $filters;

    /**
     * Columns validated in declaration order.
     *
     * @var    non-empty-list<ReportColumnDefinition>
     * @since  2.0.0
     */
    public array $columns;

    /**
     * Groups validated in declaration order.
     *
     * @var    list<ReportGroupDefinition>
     * @since  2.0.0
     */
    public array $groups;

    /**
     * Aggregates validated in declaration order.
     *
     * @var    list<ReportAggregateDefinition>
     * @since  2.0.0
     */
    public array $aggregates;

    /**
     * Formulas validated in declaration order.
     *
     * @var    list<ReportFormulaDefinition>
     * @since  2.0.0
     */
    public array $formulas;

    /**
     * Sorts validated in declaration order.
     *
     * @var    list<ReportSortDefinition>
     * @since  2.0.0
     */
    public array $sorts;

    /**
     * Drill downs validated in declaration order.
     *
     * @var    list<ReportDrillDownDefinition>
     * @since  2.0.0
     */
    public array $drillDowns;

    /**
     * Assemble and cross-check one report contribution.
     *
     * @param   string                                  $id                    Namespaced contribution identifier.
     * @param   int                                     $version               Positive immutable definition version.
     * @param   string                                  $title                 Human report title.
     * @param   string                                  $sourceDefinition      Business entity definition handle.
     * @param   string                                  $requiredCapability    Capability required in addition to the
     *          business-record report or export capability.
     * @param   list<ReportParameterDefinition>         $parameters            Typed caller inputs, at most 32.
     * @param   list<ReportFilterDefinition>            $filters               Query predicates, at most 32.
     * @param   non-empty-list<ReportColumnDefinition>  $columns               Disclosed output columns, at most 64.
     * @param   list<ReportGroupDefinition>             $groups                Grouping keys, at most four.
     * @param   list<ReportAggregateDefinition>         $aggregates            Aggregate outputs, at most 16.
     * @param   list<ReportFormulaDefinition>           $formulas              Bounded formulas, at most 16.
     * @param   list<ReportSortDefinition>              $sorts                 Output sorts, at most five.
     * @param   list<ReportDrillDownDefinition>         $drillDowns            Declarative record links, at most eight.
     * @param   int                                     $synchronousRowCap     Interactive row limit, from 1 to 1000.
     * @param   bool                                    $administratorVisible  Whether generated administrator
     *          delivery may expose this report.
     * @param bool $portalVisible Explicit opt-in for generated portal delivery.
     *
     * @throws  InvalidArgumentException  When a bound, reference or identifier is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        public int $version,
        public string $title,
        public string $sourceDefinition,
        public string $requiredCapability,
        array $parameters,
        array $filters,
        array $columns,
        array $groups = [],
        array $aggregates = [],
        array $formulas = [],
        array $sorts = [],
        array $drillDowns = [],
        public int $synchronousRowCap = 1000,
        public bool $administratorVisible = true,
        public bool $portalVisible = false,
    ) {
        ReportDefinitionGuard::identifier($id, 'identifier');
        ReportDefinitionGuard::identifier($sourceDefinition, 'source definition');
        if ($requiredCapability !== Capability::fromString($requiredCapability)->value()) {
            throw new InvalidArgumentException('A report capability must already be canonical.');
        }
        if ($version < 1 || $title === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('A report version and title must be valid.');
        }
        if (
            count($parameters) > 32 || count($filters) > 32 || $columns === [] || count($columns) > 64
            || count($groups) > 4 || count($aggregates) > 16 || count($formulas) > 16
            || count($sorts) > 5 || count($drillDowns) > 8 || $synchronousRowCap < 1
            || $synchronousRowCap > 1000
        ) {
            throw new InvalidArgumentException('A report definition exceeds a declared collection or row bound.');
        }
        self::assertInstances($parameters, ReportParameterDefinition::class);
        self::assertInstances($filters, ReportFilterDefinition::class);
        self::assertInstances($columns, ReportColumnDefinition::class);
        self::assertInstances($groups, ReportGroupDefinition::class);
        self::assertInstances($aggregates, ReportAggregateDefinition::class);
        self::assertInstances($formulas, ReportFormulaDefinition::class);
        self::assertInstances($sorts, ReportSortDefinition::class);
        self::assertInstances($drillDowns, ReportDrillDownDefinition::class);
        $parameterNames = self::unique($parameters, static fn (ReportParameterDefinition $item): string => $item->name);
        foreach ($filters as $filter) {
            if ($filter->parameter !== null && !isset($parameterNames[$filter->parameter])) {
                throw new InvalidArgumentException('A report filter references an undeclared parameter.');
            }
        }
        $columnAliases = self::unique($columns, static fn (ReportColumnDefinition $item): string => $item->alias);
        $columnTypes = [];
        foreach ($columns as $column) {
            $columnTypes[$column->alias] = $column->type;
        }
        foreach ($groups as $group) {
            self::assertReference($columnAliases, $group->columnAlias, 'group');
        }
        foreach ($aggregates as $aggregate) {
            if ($aggregate->columnAlias !== null) {
                self::assertReference($columnAliases, $aggregate->columnAlias, 'aggregate');
                $type = $columnTypes[$aggregate->columnAlias];
                if (in_array($aggregate->function, [
                    ReportAggregateFunction::Sum,
                    ReportAggregateFunction::Average,
                ], true) && !in_array($type, [ReportValueType::Integer, ReportValueType::Decimal], true)) {
                    throw new InvalidArgumentException('A numeric report aggregate requires a numeric column.');
                }
                if (in_array($aggregate->function, [
                    ReportAggregateFunction::Minimum,
                    ReportAggregateFunction::Maximum,
                ], true) && $type === ReportValueType::Boolean) {
                    throw new InvalidArgumentException('A boolean report column cannot be ordered for an aggregate.');
                }
            }
        }
        $groupedOutputAliases = [];
        foreach ($groups as $group) {
            $groupedOutputAliases[$group->columnAlias] = true;
        }
        $outputAliases = ($groups !== [] || $aggregates !== []) ? $groupedOutputAliases : $columnAliases;
        $outputTypes = ($groups !== [] || $aggregates !== [])
            ? array_intersect_key($columnTypes, $groupedOutputAliases)
            : $columnTypes;
        foreach ($aggregates as $aggregate) {
            self::addUnique($outputAliases, $aggregate->alias, 'output');
            $outputTypes[$aggregate->alias] = match ($aggregate->function) {
                ReportAggregateFunction::Count => ReportValueType::Integer,
                ReportAggregateFunction::Sum, ReportAggregateFunction::Average => ReportValueType::Decimal,
                default => $columnTypes[$aggregate->columnAlias]
                    ?? throw new InvalidArgumentException('A report aggregate source type is unavailable.'),
            };
        }
        foreach ($formulas as $formula) {
            foreach ($formula->expression->dependencies() as $dependency) {
                self::assertReference($outputAliases, $dependency, 'formula dependency');
            }
            self::addUnique($outputAliases, $formula->alias, 'output');
            $outputTypes[$formula->alias] = $formula->type;
        }
        foreach ($sorts as $sort) {
            self::assertReference($outputAliases, $sort->outputAlias, 'sort');
        }
        foreach ($drillDowns as $drillDown) {
            self::assertReference($outputAliases, $drillDown->recordAlias, 'drill-down');
            if (($outputTypes[$drillDown->recordAlias] ?? null) !== ReportValueType::Identifier) {
                throw new InvalidArgumentException('A report drill-down requires an identifier output.');
            }
        }
        $relation = null;
        foreach (array_merge($columns, $filters) as $definition) {
            $path = $definition instanceof ReportColumnDefinition ? $definition->sourcePath : $definition->fieldPath;
            if (!str_contains($path, '.')) {
                continue;
            }
            $candidate = explode('.', $path, 2)[0];
            if ($relation !== null && $relation !== $candidate) {
                throw new InvalidArgumentException('A report may traverse only one declared relationship.');
            }
            $relation = $candidate;
        }
        $this->parameters = array_values($parameters);
        $this->filters = array_values($filters);
        $this->columns = array_values($columns);
        $this->groups = array_values($groups);
        $this->aggregates = array_values($aggregates);
        $this->formulas = array_values($formulas);
        $this->sorts = array_values($sorts);
        $this->drillDowns = array_values($drillDowns);
    }

    /**
     * Return the stable contribution identifier.
     *
     * @return  string  Namespaced report handle.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export every semantic choice in deterministic manifest shape.
     *
     * @return  array<string, mixed>  Canonically encodable report document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->id,
            'version' => $this->version,
            'title' => $this->title,
            'source_definition' => $this->sourceDefinition,
            'required_capability' => $this->requiredCapability,
            'administrator_visible' => $this->administratorVisible,
            'portal_visible' => $this->portalVisible,
            'parameters' => array_map(static fn (ReportParameterDefinition $item): array => [
                'name' => $item->name, 'type' => $item->type->value, 'required' => $item->required,
                'multiple' => $item->multiple, 'default' => $item->defaultValue,
            ], $this->parameters),
            'filters' => array_map(static fn (ReportFilterDefinition $item): array => [
                'field' => $item->fieldPath, 'operator' => $item->operator->value,
                'parameter' => $item->parameter, 'quantifier' => $item->quantifier->value,
            ], $this->filters),
            'columns' => array_map(static fn (ReportColumnDefinition $item): array => [
                'alias' => $item->alias, 'label' => $item->label, 'source' => $item->sourcePath,
                'type' => $item->type->value,
            ], $this->columns),
            'groups' => array_map(static fn (ReportGroupDefinition $item): array => [
                'column' => $item->columnAlias,
            ], $this->groups),
            'aggregates' => array_map(static fn (ReportAggregateDefinition $item): array => [
                'alias' => $item->alias, 'function' => $item->function->value, 'column' => $item->columnAlias,
            ], $this->aggregates),
            'formulas' => array_map(static fn (ReportFormulaDefinition $item): array => [
                'alias' => $item->alias, 'label' => $item->label, 'type' => $item->type->value,
                'expression' => $item->expression->toArray(),
            ], $this->formulas),
            'sorts' => array_map(static fn (ReportSortDefinition $item): array => [
                'output' => $item->outputAlias, 'direction' => $item->direction->value,
                'nulls_last' => $item->nullsLast,
            ], $this->sorts),
            'drill_downs' => array_map(static fn (ReportDrillDownDefinition $item): array => [
                'record' => $item->recordAlias, 'definition' => $item->definitionIdentifier,
                'view' => $item->viewIdentifier,
            ], $this->drillDowns),
            'synchronous_row_cap' => $this->synchronousRowCap,
        ];
    }

    /**
     * Rebuild a report from its deterministic manifest document.
     *
     * @param   array<string, mixed>  $document  Exact output of `toArray()` from a trusted manifest parser.
     *
     * @return  self  Validated immutable definition.
     *
     * @throws  InvalidArgumentException  When a member is missing or has an invalid scalar shape.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        try {
            self::keys($document, [
                'identifier', 'version', 'title', 'source_definition', 'required_capability',
                'administrator_visible', 'portal_visible', 'parameters', 'filters', 'columns', 'groups',
                'aggregates', 'formulas', 'sorts', 'drill_downs', 'synchronous_row_cap',
            ]);
            /** @var list<array<string, mixed>> $parameters */
            $parameters = self::list($document, 'parameters');
            /** @var list<array<string, mixed>> $filters */
            $filters = self::list($document, 'filters');
            /** @var list<array<string, mixed>> $columns */
            $columns = self::list($document, 'columns');
            /** @var list<array<string, mixed>> $groups */
            $groups = self::list($document, 'groups');
            /** @var list<array<string, mixed>> $aggregates */
            $aggregates = self::list($document, 'aggregates');
            /** @var list<array<string, mixed>> $formulas */
            $formulas = self::list($document, 'formulas');
            /** @var list<array<string, mixed>> $sorts */
            $sorts = self::list($document, 'sorts');
            /** @var list<array<string, mixed>> $drillDowns */
            $drillDowns = self::list($document, 'drill_downs');

            return new self(
                self::string($document, 'identifier'),
                self::integer($document, 'version'),
                self::string($document, 'title'),
                self::string($document, 'source_definition'),
                self::string($document, 'required_capability'),
                array_map(static function (array $item): ReportParameterDefinition {
                    self::keys($item, ['name', 'type', 'required', 'multiple', 'default']);
                    return new ReportParameterDefinition(
                        self::string($item, 'name'), ReportValueType::from(self::string($item, 'type')),
                        self::boolean($item, 'required'), self::boolean($item, 'multiple'), $item['default'],
                    );
                }, $parameters),
                array_map(static function (array $item): ReportFilterDefinition {
                    self::keys($item, ['field', 'operator', 'parameter', 'quantifier']);
                    return new ReportFilterDefinition(
                        self::string($item, 'field'), ReportFilterOperator::from(self::string($item, 'operator')),
                        self::nullableString($item, 'parameter'),
                        ReportRelationQuantifier::from(self::string($item, 'quantifier')),
                    );
                }, $filters),
                array_map(static function (array $item): ReportColumnDefinition {
                    self::keys($item, ['alias', 'label', 'source', 'type']);
                    return new ReportColumnDefinition(
                        self::string($item, 'alias'), self::string($item, 'label'), self::string($item, 'source'),
                        ReportValueType::from(self::string($item, 'type')),
                    );
                }, $columns),
                array_map(static function (array $item): ReportGroupDefinition {
                    self::keys($item, ['column']);
                    return new ReportGroupDefinition(self::string($item, 'column'));
                }, $groups),
                array_map(static function (array $item): ReportAggregateDefinition {
                    self::keys($item, ['alias', 'function', 'column']);
                    return new ReportAggregateDefinition(
                        self::string($item, 'alias'), ReportAggregateFunction::from(self::string($item, 'function')),
                        self::nullableString($item, 'column'),
                    );
                }, $aggregates),
                array_map(static function (array $item): ReportFormulaDefinition {
                    self::keys($item, ['alias', 'label', 'type', 'expression']);
                    return new ReportFormulaDefinition(
                        self::string($item, 'alias'), self::string($item, 'label'),
                        ReportValueType::from(self::string($item, 'type')),
                        Expression::fromArray(self::object($item, 'expression')),
                    );
                }, $formulas),
                array_map(static function (array $item): ReportSortDefinition {
                    self::keys($item, ['output', 'direction', 'nulls_last']);
                    return new ReportSortDefinition(
                        self::string($item, 'output'), ReportSortDirection::from(self::string($item, 'direction')),
                        self::boolean($item, 'nulls_last'),
                    );
                }, $sorts),
                array_map(static function (array $item): ReportDrillDownDefinition {
                    self::keys($item, ['record', 'definition', 'view']);
                    return new ReportDrillDownDefinition(
                        self::string($item, 'record'), self::string($item, 'definition'), self::string($item, 'view'),
                    );
                }, $drillDowns),
                self::integer($document, 'synchronous_row_cap'),
                self::boolean($document, 'administrator_visible'),
                self::boolean($document, 'portal_visible'),
            );
        } catch (\ValueError|\TypeError $exception) {
            throw new InvalidArgumentException('A report definition document has an invalid value.', 0, $exception);
        }
    }

    /**
     * Fingerprint the exact manifest shape.
     *
     * @return  string  Lowercase SHA-256 checksum.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /**
     * Validate instances before continuing.
     *
     * @param   list<object>  $items  Definitions whose runtime type and uniqueness are validated.
     * @param   class-string  $class  Expected definition class for every list member.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertInstances(array $items, string $class): void
    {
        foreach ($items as $item) {
            if (!$item instanceof $class) {
                throw new InvalidArgumentException('A report definition collection has an invalid member.');
            }
        }
    }

    /**
     * Return the unique references in their declared order.
     *
     * @param   list<object>              $items       Definitions whose runtime type and uniqueness are validated.
     * @param   callable(object): string  $identifier  Stable namespaced identifier to render or persist.
     *
     * @return  array<string, true>
     *
     * @since   2.0.0
     */
    private static function unique(array $items, callable $identifier): array
    {
        $seen = [];
        foreach ($items as $item) {
            self::addUnique($seen, $identifier($item), 'definition-local');
        }

        return $seen;
    }

    /**
     * Append a reference only when it has not already been declared.
     *
     * @param   array<string, true>  $seen   References already encountered while preserving declaration order.
     * @param   string               $value  Candidate value being validated or normalized.
     * @param   string               $label  Human-readable field name used in validation errors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function addUnique(array &$seen, string $value, string $label): void
    {
        if (isset($seen[$value])) {
            throw new InvalidArgumentException(sprintf('A report %s alias is duplicated.', $label));
        }
        $seen[$value] = true;
    }

    /**
     * Validate a stable report-definition reference.
     *
     * @param   array<string, true>  $available  Declared references that this definition may use.
     * @param   string               $value      Candidate value being validated or normalized.
     * @param   string               $label      Human-readable field name used in validation errors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertReference(array $available, string $value, string $label): void
    {
        if (!isset($available[$value])) {
            throw new InvalidArgumentException(sprintf('A report %s references an undeclared output.', $label));
        }
    }

    /** @param array<string, mixed> $document @return list<array<string, mixed>> @since 2.0.0 */
    private static function list(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('A report definition document collection is invalid.');
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('A report definition document member is invalid.');
            }
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * Read a required string from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  string  Required string stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A report definition string is invalid.');
        }

        return $value;
    }

    /**
     * Read an optional string from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  ?string  String stored under the key, or null when the member is absent.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('A report definition nullable string is invalid.');
        }

        return $value;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('A report definition integer is invalid.');
        }

        return $value;
    }

    /**
     * Read and validate a boolean value.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  bool  Boolean stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException('A report definition boolean is invalid.');
        }

        return $value;
    }

    /**
     * Read a structured object from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private static function object(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('A report definition object is invalid.');
        }

        return $value;
    }

    /**
     * Return the allowed keys for this report-definition object.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   list<string>          $expected  Exact keys allowed in the serialized definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function keys(array $document, array $expected): void
    {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('A report definition document has missing or unknown keys.');
        }
    }
}
