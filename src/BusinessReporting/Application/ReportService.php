<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use DateTimeInterface;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\DecimalValue;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Query\BooleanFilter;
use Kumwe\CMS\BusinessRecord\Query\BooleanOperator;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonOperator;
use Kumwe\CMS\BusinessRecord\Query\NullFilter;
use Kumwe\CMS\BusinessRecord\Query\RecordCursor;
use Kumwe\CMS\BusinessRecord\Query\RecordFilter;
use Kumwe\CMS\BusinessRecord\Query\RecordProjection;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessRecord\Query\RelationFilter;
use Kumwe\CMS\BusinessRecord\Query\RelationQuantifier;
use Kumwe\CMS\BusinessRecord\Query\SetFilter;
use Kumwe\CMS\BusinessRecord\Query\TextFilter;
use Kumwe\CMS\BusinessRecord\Query\TextOperator;
use Kumwe\CMS\BusinessReporting\Domain\ReportAggregateDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportAggregateFunction;
use Kumwe\CMS\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportFilterDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportFilterOperator;
use Kumwe\CMS\BusinessReporting\Domain\ReportRelationQuantifier;
use Kumwe\CMS\BusinessReporting\Domain\ReportSortDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportSortDirection;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Executes immutable reports exclusively over policy-filtered business-record browse pages.
 *
 * Grouping, formulas and ordering happen only after `BusinessRecordService` has applied row policy and
 * omitted disallowed fields. Missing fields remain missing through formulas and aggregates, preventing a
 * conditional field denial from being converted into a value or grouping key downstream.
 *
 * @since  2.0.0
 */
final readonly class ReportService
{
    /**
     * Wire reporting to definitions, the policy-aware record seam and capability enforcement.
     *
     * @param   ReportDefinitionRegistry  $reports            Active report contributions.
     * @param   BusinessRecordReportReader $records           Canonical record browse adapter.
     * @param   AuthorizationGateway      $authorization      Deny-by-default permission gateway.
     * @param   int                       $maximumExportRows  Absolute expanded-row bound for one export.
     *
     * @throws  InvalidArgumentException  When the export bound is outside 1 to 100000.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ReportDefinitionRegistry $reports,
        private BusinessRecordReportReader $records,
        private AuthorizationGateway $authorization,
        private int $maximumExportRows = 100_000,
    ) {
        if ($maximumExportRows < 1 || $maximumExportRows > 100_000) {
            throw new InvalidArgumentException('The report export row limit is invalid.');
        }
    }

    /**
     * Execute a report or export under the caller's current authority.
     *
     * @param   ReportExecutionRequest  $request  Authenticated report input.
     *
     * @return  ReportExecutionResult  Fully bounded disclosure-safe result.
     *
     * @throws  ReportUnavailable       When the report is absent or not exposed to the current surface.
     * @throws  ReportRowLimitExceeded  When the materialized row count passes its purpose-specific bound.
     *
     * @since   2.0.0
     */
    public function execute(ReportExecutionRequest $request): ReportExecutionResult
    {
        $report = $this->reports->get($request->reportIdentifier);
        $this->assertSurface($report, $request->context->surface());
        $this->authorization->assertAllowed(
            $request->context,
            Capability::fromString($report->requiredCapability),
            AuthorizationResource::collection('business_report'),
        );
        $this->authorization->assertAllowed(
            $request->context,
            Capability::fromString('business.record.' . $request->purpose->value),
            AuthorizationResource::collection('business_report'),
        );
        $parameters = $this->bindParameters($report, $request->parameters);
        $filter = $this->compileFilters($report, $parameters);
        [$fields, $includes] = $this->projection($report);
        $limit = $request->purpose === BusinessRecordQueryPurpose::Export
            ? $this->maximumExportRows
            : $report->synchronousRowCap;
        $rows = [];
        $after = null;
        do {
            $specification = new RecordQuerySpecification(
                filter: $filter,
                after: $after,
                pageSize: 200,
                projection: new RecordProjection($fields, $includes),
            );
            $page = $this->records->browse(
                $request->context,
                $report->sourceDefinition,
                $specification,
                $request->organizationIdentifier,
                $request->purpose,
            );
            foreach ($page->records as $record) {
                foreach ($this->projectRecord($report, $record) as $row) {
                    $rows[] = $row;
                    if (count($rows) > $limit) {
                        throw new ReportRowLimitExceeded('The report result exceeds its row limit.');
                    }
                }
            }
            $after = $page->nextCursor;
        } while ($after instanceof RecordCursor);

        $rows = $this->materialize($report, $rows);
        if (count($rows) > $limit) {
            throw new ReportRowLimitExceeded('The report result exceeds its row limit.');
        }
        $labels = $this->labels($report);
        $types = $this->types($report);
        $queryDigest = CanonicalDefinitionJson::checksum([
            'report_checksum' => $report->checksum(),
            'parameters' => $parameters,
            'organization' => $request->organizationIdentifier,
            'purpose' => $request->purpose->value,
        ]);

        return new ReportExecutionResult(
            $report->identifier(),
            $report->checksum(),
            $queryDigest,
            $labels,
            $types,
            $rows,
        );
    }

    /** @param array<string, mixed> $supplied @return array<string, mixed> @since 2.0.0 */
    private function bindParameters(ReportDefinition $report, array $supplied): array
    {
        $declared = [];
        $bound = [];
        foreach ($report->parameters as $parameter) {
            $declared[$parameter->name] = true;
            $value = array_key_exists($parameter->name, $supplied)
                ? $supplied[$parameter->name]
                : $parameter->defaultValue;
            if ($value === null && !$parameter->required) {
                continue;
            }
            $bound[$parameter->name] = $parameter->assertValue($value);
        }
        if (array_diff_key($supplied, $declared) !== []) {
            throw new InvalidArgumentException('A report execution contains an undeclared parameter.');
        }
        ksort($bound, SORT_STRING);

        return $bound;
    }

    /** @param array<string, mixed> $parameters @since 2.0.0 */
    private function compileFilters(ReportDefinition $report, array $parameters): ?RecordFilter
    {
        $filters = [];
        foreach ($report->filters as $definition) {
            if ($definition->parameter !== null && !array_key_exists($definition->parameter, $parameters)) {
                continue;
            }
            [$relationship, $field] = $this->splitPath($definition->fieldPath);
            $filter = $this->compileFilter($definition, $field, $parameters[$definition->parameter] ?? null);
            if ($relationship !== null) {
                $filter = new RelationFilter($relationship, match ($definition->quantifier) {
                    ReportRelationQuantifier::Any => RelationQuantifier::Any,
                    ReportRelationQuantifier::None => RelationQuantifier::None,
                    ReportRelationQuantifier::All => RelationQuantifier::All,
                }, $filter);
            }
            $filters[] = $filter;
        }
        if ($filters === []) {
            return null;
        }

        return count($filters) === 1 ? $filters[0] : new BooleanFilter(BooleanOperator::All, $filters);
    }

    /** @since 2.0.0 */
    private function compileFilter(ReportFilterDefinition $definition, string $field, mixed $value): RecordFilter
    {
        return match ($definition->operator) {
            ReportFilterOperator::Equal => new ComparisonFilter($field, ComparisonOperator::Equal, $value),
            ReportFilterOperator::NotEqual => new ComparisonFilter($field, ComparisonOperator::NotEqual, $value),
            ReportFilterOperator::LessThan => new ComparisonFilter($field, ComparisonOperator::LessThan, $value),
            ReportFilterOperator::LessThanOrEqual => new ComparisonFilter(
                $field,
                ComparisonOperator::LessThanOrEqual,
                $value,
            ),
            ReportFilterOperator::GreaterThan => new ComparisonFilter($field, ComparisonOperator::GreaterThan, $value),
            ReportFilterOperator::GreaterThanOrEqual => new ComparisonFilter(
                $field,
                ComparisonOperator::GreaterThanOrEqual,
                $value,
            ),
            ReportFilterOperator::Contains => new TextFilter($field, TextOperator::Contains, $this->text($value)),
            ReportFilterOperator::StartsWith => new TextFilter($field, TextOperator::StartsWith, $this->text($value)),
            ReportFilterOperator::EndsWith => new TextFilter($field, TextOperator::EndsWith, $this->text($value)),
            ReportFilterOperator::In => new SetFilter($field, $this->set($value)),
            ReportFilterOperator::NotIn => new SetFilter($field, $this->set($value), true),
            ReportFilterOperator::IsNull => new NullFilter($field),
            ReportFilterOperator::IsNotNull => new NullFilter($field, false),
        };
    }

    /** @return array{0: ?string, 1: string} @since 2.0.0 */
    private function splitPath(string $path): array
    {
        $parts = explode('.', $path, 2);

        return count($parts) === 1 ? [null, $parts[0]] : [$parts[0], $parts[1]];
    }

    /** @return array{0: list<string>, 1: list<string>} @since 2.0.0 */
    private function projection(ReportDefinition $report): array
    {
        $fields = [];
        $includes = [];
        foreach ($report->columns as $column) {
            [$relationship, $field] = $this->splitPath($column->sourcePath);
            if ($relationship === null) {
                $fields[] = $field;
            } else {
                $includes[] = $relationship;
            }
        }

        return [array_values(array_unique($fields)), array_values(array_unique($includes))];
    }

    /** @return list<array<string, bool|int|string|null>> @since 2.0.0 */
    private function projectRecord(ReportDefinition $report, BusinessRecordView $record): array
    {
        $root = [];
        $relationColumns = [];
        $relationship = null;
        foreach ($report->columns as $column) {
            [$candidate, $field] = $this->splitPath($column->sourcePath);
            if ($candidate === null) {
                if (array_key_exists($field, $record->values)) {
                    $root[$column->alias] = $this->cell($record->values[$field], $column);
                }
                continue;
            }
            $relationship = $candidate;
            $relationColumns[] = [$column, $field];
        }
        if ($relationship === null) {
            return [$root];
        }
        $relatedRows = $record->includes[$relationship] ?? [];
        if ($relatedRows === []) {
            return [$root];
        }
        $rows = [];
        foreach ($relatedRows as $related) {
            $row = $root;
            foreach ($relationColumns as [$column, $field]) {
                if (!$column instanceof ReportColumnDefinition || !$related instanceof BusinessRecordRelationView) {
                    throw new ReportUnavailable('The report relation projection is invalid.');
                }
                if (array_key_exists($field, $related->values)) {
                    $row[$column->alias] = $this->cell($related->values[$field], $column);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return bool|int|string|null @since 2.0.0 */
    private function cell(mixed $value, ReportColumnDefinition $column): bool|int|string|null
    {
        if ($value instanceof ExactDecimal) {
            $value = $value->value();
        } elseif ($value instanceof DateTimeInterface) {
            $value = $column->type === ReportValueType::Date
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value !== null && !$column->type->accepts($value)) {
            throw new ReportUnavailable('A report column value contradicts its declared type.');
        }
        if (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null) {
            throw new ReportUnavailable('A report column cannot disclose a structured value.');
        }

        return $value;
    }

    /**
     * @param  list<array<string, bool|int|string|null>> $rows
     * @return list<array<string, bool|int|string|null>>
     * @since  2.0.0
     */
    private function materialize(ReportDefinition $report, array $rows): array
    {
        if ($report->groups !== [] || $report->aggregates !== []) {
            $rows = $this->group($report, $rows);
        }
        foreach ($rows as &$row) {
            foreach ($report->formulas as $formula) {
                $dependencies = $formula->expression->dependencies();
                if (array_diff_key(array_fill_keys($dependencies, true), $row) !== []) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
                /** @var array<string, scalar|null> $values */
                $values = array_intersect_key($row, array_fill_keys($dependencies, true));
                $value = $formula->expression->evaluate($values);
                if ($value !== null && !$formula->type->accepts($value)) {
                    throw new ReportUnavailable('A report formula result contradicts its declared type.');
                }
                if (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null) {
                    throw new ReportUnavailable('A report formula produced a structured value.');
                }
                $row[$formula->alias] = $value;
            }
        }
        unset($row);
        $this->sort($report, $rows);

        return $rows;
    }

    /**
     * @param  list<array<string, bool|int|string|null>> $rows
     * @return list<array<string, bool|int|string|null>>
     * @since  2.0.0
     */
    private function group(ReportDefinition $report, array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = [];
            $groupRow = [];
            foreach ($report->groups as $group) {
                if (!array_key_exists($group->columnAlias, $row)) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
                $key[] = $row[$group->columnAlias];
                $groupRow[$group->columnAlias] = $row[$group->columnAlias];
            }
            $digest = CanonicalDefinitionJson::checksum($key);
            $buckets[$digest] ??= ['row' => $groupRow, 'members' => []];
            $buckets[$digest]['members'][] = $row;
        }
        if ($report->groups === [] && $buckets === []) {
            $buckets['all'] = ['row' => [], 'members' => $rows];
        }
        $result = [];
        foreach ($buckets as $bucket) {
            $output = $bucket['row'];
            foreach ($report->aggregates as $aggregate) {
                $value = $this->aggregate($aggregate, $bucket['members']);
                $output[$aggregate->alias] = $value;
            }
            $result[] = $output;
        }

        return $result;
    }

    /** @param list<array<string, bool|int|string|null>> $rows @return int|string|null @since 2.0.0 */
    private function aggregate(ReportAggregateDefinition $aggregate, array $rows): int|string|null
    {
        if ($aggregate->function === ReportAggregateFunction::Count) {
            return count($rows);
        }
        $values = [];
        foreach ($rows as $row) {
            if ($aggregate->columnAlias !== null && !array_key_exists($aggregate->columnAlias, $row)) {
                throw new ReportUnavailable('The report is unavailable.');
            }
            if ($aggregate->columnAlias !== null && $row[$aggregate->columnAlias] !== null) {
                $values[] = $row[$aggregate->columnAlias];
            }
        }
        if ($values === []) {
            return null;
        }
        if ($aggregate->function === ReportAggregateFunction::Minimum
            || $aggregate->function === ReportAggregateFunction::Maximum
        ) {
            $selected = array_shift($values);
            foreach ($values as $value) {
                if ($selected === null || $this->compare($value, $selected) * (
                    $aggregate->function === ReportAggregateFunction::Minimum ? 1 : -1
                ) < 0) {
                    $selected = $value;
                }
            }

            return is_bool($selected) ? (int) $selected : $selected;
        }
        $sum = DecimalValue::fromString('0');
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new ReportUnavailable('A numeric report aggregate received a non-numeric value.');
            }
            $sum = $sum->add(DecimalValue::fromString((string) $value));
        }
        if ($aggregate->function === ReportAggregateFunction::Average) {
            return $sum->divide(DecimalValue::fromString((string) count($values)), 6)->value();
        }

        return $sum->value();
    }

    /**
     * @param  list<array<string, bool|int|string|null>> $rows
     * @return void
     * @since  2.0.0
     */
    private function sort(ReportDefinition $report, array &$rows): void
    {
        if ($report->sorts === []) {
            return;
        }
        $decorated = [];
        foreach ($rows as $index => $row) {
            $decorated[] = ['index' => $index, 'row' => $row];
        }
        usort($decorated, function (array $left, array $right) use ($report): int {
            foreach ($report->sorts as $sort) {
                $comparison = $this->compareSort($left['row'], $right['row'], $sort);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['index'] <=> $right['index'];
        });
        $rows = array_map(static fn (array $item): array => $item['row'], $decorated);
    }

    /**
     * @param array<string, bool|int|string|null> $left
     * @param array<string, bool|int|string|null> $right
     * @since 2.0.0
     */
    private function compareSort(array $left, array $right, ReportSortDefinition $sort): int
    {
        $leftValue = $left[$sort->outputAlias] ?? null;
        $rightValue = $right[$sort->outputAlias] ?? null;
        if ($leftValue === null || $rightValue === null) {
            $comparison = $leftValue === $rightValue ? 0 : ($leftValue === null ? 1 : -1);
            if (!$sort->nullsLast) {
                $comparison *= -1;
            }
        } else {
            $comparison = $this->compare($leftValue, $rightValue);
        }

        return $sort->direction === ReportSortDirection::Ascending ? $comparison : -$comparison;
    }

    /** @since 2.0.0 */
    private function compare(bool|int|string $left, bool|int|string $right): int
    {
        if ((is_int($left) || $this->decimal($left)) && (is_int($right) || $this->decimal($right))) {
            return DecimalValue::fromString((string) $left)->compare(DecimalValue::fromString((string) $right));
        }

        return (string) $left <=> (string) $right;
    }

    /** @since 2.0.0 */
    private function decimal(bool|int|string $value): bool
    {
        return is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
    }

    /** @return array<string, string> @since 2.0.0 */
    private function labels(ReportDefinition $report): array
    {
        $labels = [];
        $groupAliases = array_fill_keys(array_map(
            static fn ($group): string => $group->columnAlias,
            $report->groups,
        ), true);
        foreach ($report->columns as $column) {
            if (($report->groups === [] && $report->aggregates === []) || isset($groupAliases[$column->alias])) {
                $labels[$column->alias] = $column->label;
            }
        }
        foreach ($report->aggregates as $aggregate) {
            $labels[$aggregate->alias] = $aggregate->alias;
        }
        foreach ($report->formulas as $formula) {
            $labels[$formula->alias] = $formula->label;
        }

        return $labels;
    }

    /** @return array<string, ReportValueType> @since 2.0.0 */
    private function types(ReportDefinition $report): array
    {
        $types = [];
        $columns = [];
        $groupAliases = array_fill_keys(array_map(
            static fn ($group): string => $group->columnAlias,
            $report->groups,
        ), true);
        foreach ($report->columns as $column) {
            $columns[$column->alias] = $column->type;
            if (($report->groups === [] && $report->aggregates === []) || isset($groupAliases[$column->alias])) {
                $types[$column->alias] = $column->type;
            }
        }
        foreach ($report->aggregates as $aggregate) {
            $types[$aggregate->alias] = match ($aggregate->function) {
                ReportAggregateFunction::Count => ReportValueType::Integer,
                ReportAggregateFunction::Average => ReportValueType::Decimal,
                default => $columns[$aggregate->columnAlias]
                    ?? throw new ReportUnavailable('A report aggregate source type is unavailable.'),
            };
        }
        foreach ($report->formulas as $formula) {
            $types[$formula->alias] = $formula->type;
        }

        return $types;
    }

    /** @since 2.0.0 */
    private function assertSurface(ReportDefinition $report, AuthenticatedSurface $surface): void
    {
        if (($surface === AuthenticatedSurface::Administrator && !$report->administratorVisible)
            || ($surface === AuthenticatedSurface::Portal && !$report->portalVisible)
            || $surface === AuthenticatedSurface::Recovery
        ) {
            throw new ReportUnavailable('The report is unavailable.');
        }
    }

    /** @since 2.0.0 */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('A report text filter requires one string parameter.');
        }

        return $value;
    }

    /** @return non-empty-list<mixed> @since 2.0.0 */
    private function set(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('A report set filter requires a non-empty list parameter.');
        }

        return $value;
    }
}
