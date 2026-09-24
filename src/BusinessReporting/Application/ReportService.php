<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeInterface;
use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Value\ConvertedQuantityValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Record\Query\BooleanFilter;
use Kumwe\Record\Query\BooleanOperator;
use Kumwe\Record\Query\ComparisonFilter;
use Kumwe\Record\Query\ComparisonOperator;
use Kumwe\Record\Query\NullFilter;
use Kumwe\Record\Query\RecordCursor;
use Kumwe\Record\Query\RecordFilter;
use Kumwe\Record\Query\RecordProjection;
use Kumwe\Record\Query\RecordQuerySpecification;
use Kumwe\Record\Query\RelationFilter;
use Kumwe\Record\Query\RelationQuantifier;
use Kumwe\Record\Query\SetFilter;
use Kumwe\Record\Query\TextFilter;
use Kumwe\Record\Query\TextOperator;
use Kumwe\Reporting\Domain\ReportAggregateFunction;
use Kumwe\Reporting\Domain\ReportColumnDefinition;
use Kumwe\Reporting\Domain\ReportDefinition;
use Kumwe\Reporting\Domain\ReportFilterDefinition;
use Kumwe\Reporting\Domain\ReportFilterOperator;
use Kumwe\Reporting\Domain\ReportRelationQuantifier;
use Kumwe\Reporting\Domain\ReportValueType;
use Kumwe\Access\Capability;
use Throwable;

/**
 * Executes immutable reports exclusively over policy-filtered business-record browse pages.
 *
 * Grouping, aggregates, formulas and ordering happen only after `BusinessRecordService` has applied row policy
 * and omitted disallowed fields, and they run through the report materialization port over the complete
 * authorized row set. Missing fields remain missing through formulas and aggregates, preventing a conditional
 * field denial from being converted into a value or grouping key downstream.
 *
 * @since  2.0.0
 */
final readonly class ReportService
{
    /**
     * Wire reporting to definitions, the policy-aware record seam and capability enforcement.
     *
     * @param   ReportDefinitionRegistry     $reports            Active report contributions.
     * @param   BusinessRecordReportReader   $records            Canonical record browse adapter.
     * @param   AuthorizationGateway         $authorization      Deny-by-default permission gateway.
     * @param   ReportScopeResolver          $scopes             Installed source-scope resolver.
     * @param   ReportMaterialization        $materialization    Port that groups, aggregates, computes and
     *          orders the authorized rows.
     * @param   int                          $maximumExportRows  Absolute expanded-row bound for one export.
     * @param   ?RecordExportReportProvider  $recordExports      Derived record-set export reports; null
     *          keeps resolution limited to contributed reports.
     *
     * @throws  InvalidArgumentException  When the export bound is outside 1 to 100000.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ReportDefinitionRegistry $reports,
        private BusinessRecordReportReader $records,
        private AuthorizationGateway $authorization,
        private ReportScopeResolver $scopes,
        private ReportMaterialization $materialization,
        private int $maximumExportRows = 100_000,
        private ?RecordExportReportProvider $recordExports = null,
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
     * @throws  ReportUnavailable  When the report is absent or not exposed to the current surface.
     * @throws  ReportRowLimitExceeded  When the materialized row count passes its purpose-specific bound.
     *
     * @since   2.0.0
     */
    public function execute(ReportExecutionRequest $request): ReportExecutionResult
    {
        $report = $this->report($request->context, $request->reportIdentifier);
        if (!$this->isAvailable($request->context, $report, $request->purpose)) {
            throw new ReportUnavailable('The report is unavailable.');
        }
        $parameters = $this->bindParameters($report, $request->parameters);
        $organizationIdentifier = $this->scopes->resolve(
            $request->context,
            $report,
            $request->organizationIdentifier,
        );
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
            try {
                $page = $this->records->browse(
                    $request->context,
                    $report->sourceDefinition,
                    $specification,
                    $organizationIdentifier,
                    $request->purpose,
                );
            } catch (InvalidBusinessRecordQuery $exception) {
                throw new ReportUnavailable('The report is unavailable.', previous: $exception);
            }
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

        $this->assertCompleteProjection($report, $rows);
        if ($this->materializes($report)) {
            $rows = $this->materialization->materialize($report, $rows);
        }
        if (count($rows) > $limit) {
            throw new ReportRowLimitExceeded('The report result exceeds its row limit.');
        }
        $labels = $this->labels($report);
        $types = $this->types($report);
        $queryDigest = CanonicalDefinitionJson::checksum([
            'report_checksum' => $report->checksum(),
            'parameters' => $parameters,
            'organization' => $organizationIdentifier,
            'purpose' => $request->purpose->value,
        ]);

        return new ReportExecutionResult(
            $report->identifier(),
            $report->checksum(),
            $queryDigest,
            $labels,
            $types,
            $rows,
            $report->drillDowns,
        );
    }

    /**
     * List active reports that the current actor can actually execute on this surface and exact resource.
     *
     * @param   ExecutionContext            $context  Authenticated actor and current site or membership scope.
     * @param   BusinessRecordQueryPurpose  $purpose  Report or export authority to evaluate.
     *
     * @return  list<ReportDefinition>  Authorized definitions in stable registry order.
     *
     * @since   2.0.0
     */
    public function available(
        ExecutionContext $context,
        BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Report,
    ): array {
        return array_values(array_filter(
            $this->reports->all(),
            fn (ReportDefinition $report): bool => $this->isAvailable($context, $report, $purpose),
        ));
    }

    /**
     * Evaluate one report with the same surface, custom-capability, and core-capability rules as execution.
     *
     * @param   ExecutionContext            $context  Authenticated actor and current site or membership scope.
     * @param   ReportDefinition            $report   Active immutable report definition.
     * @param   BusinessRecordQueryPurpose  $purpose  Report or export authority to evaluate.
     *
     * @return  bool  True only when both audited authorization decisions permit the exact report item.
     *
     * @since   2.0.0
     */
    public function isAvailable(
        ExecutionContext $context,
        ReportDefinition $report,
        BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Report,
    ): bool {
        if (!$this->surfaceAvailable($report, $context->surface())) {
            return false;
        }
        $resource = AuthorizationResource::item('business_report', $report->identifier());
        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString($report->requiredCapability),
                $resource,
            )->allowed
        ) {
            return false;
        }

        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString('business.record.' . $purpose->value),
                $resource,
            )->allowed
        ) {
            return false;
        }
        try {
            $this->scopes->resolve($context, $report, $context->organization()?->identifier());
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a contributed report, or fall back to one derived record-set export report.
     *
     * @param   ExecutionContext  $context     Authenticated actor and site scope.
     * @param   string            $identifier  Namespaced report handle.
     *
     * @return  ReportDefinition  Contributed or derived immutable definition.
     *
     * @throws  ReportUnavailable  When neither the registry nor derivation can answer the handle.
     *
     * @since   2.0.0
     */
    private function report(ExecutionContext $context, string $identifier): ReportDefinition
    {
        try {
            return $this->reports->get($identifier);
        } catch (ReportUnavailable $exception) {
            if ($this->recordExports === null) {
                throw $exception;
            }

            return $this->recordExports->resolve($context, $identifier);
        }
    }

    /**
     * Bind and validate caller-supplied report parameters.
     *
     * @param   ReportDefinition      $report    Signed report definition governing query behavior.
     * @param   array<string, mixed>  $supplied  Caller-provided values keyed by report parameter identifier.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
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

    /**
     * Compile declared report filters into business-record filters.
     *
     * @param   ReportDefinition      $report      Signed report definition governing query behavior.
     * @param   array<string, mixed>  $parameters  Validated parameter values used to compile report filters.
     *
     * @return  ?RecordFilter  Combined predicate, or null when the report declares no filters.
     *
     * @since   2.0.0
     */
    private function compileFilters(ReportDefinition $report, array $parameters): ?RecordFilter
    {
        $filters = [];
        foreach ($report->filters as $definition) {
            $parameter = $definition->parameter;
            if ($parameter !== null && !array_key_exists($parameter, $parameters)) {
                continue;
            }
            [$relationship, $field] = $this->splitPath($definition->fieldPath);
            $filter = $this->compileFilter(
                $definition,
                $field,
                $parameter === null ? null : $parameters[$parameter],
            );
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

    /**
     * Compile one declared report filter into a safe query predicate.
     *
     * @param   ReportFilterDefinition  $definition  Signed contribution definition governing the operation.
     * @param   string                  $field       Declared field path targeted by the predicate.
     * @param   mixed                   $value       Candidate value being validated or normalized.
     *
     * @return  RecordFilter  Business-record predicate compiled from the declared filter.
     *
     * @since   2.0.0
     */
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

    /**
     * Split a declared one-hop field path into its components.
     *
     * @param   string  $path  Declared one-hop field path to split and validate.
     *
     * @return  array{0: ?string, 1: string}
     *
     * @since   2.0.0
     */
    private function splitPath(string $path): array
    {
        $parts = explode('.', $path, 2);

        return count($parts) === 1 ? [null, $parts[0]] : [$parts[0], $parts[1]];
    }

    /**
     * Compile the report column projection for policy-safe record access.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array{0: list<string>, 1: list<string>}
     *
     * @since   2.0.0
     */
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

    /**
     * Project one policy-filtered business record into report cells.
     *
     * @param   ReportDefinition    $report  Signed report definition governing query behavior.
     * @param   BusinessRecordView  $record  Policy-filtered business record being projected.
     *
     * @return  list<array<string, bool|int|string|null>>
     *
     * @since   2.0.0
     */
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
                if (array_key_exists($field, $related->values)) {
                    $row[$column->alias] = $this->cell($related->values[$field], $column);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Refuse a partially disclosed projection before grouping, formulas, labels, or ordering can expose it.
     *
     * @param   ReportDefinition                           $report  Signed definition naming every required source.
     * @param   list<array<string, bool|int|string|null>>  $rows    Policy-filtered rows before materialization.
     *
     * @return  void
     *
     * @throws  ReportUnavailable  When policy or conditional visibility omitted any requested source value.
     *
     * @since   2.0.0
     */
    private function assertCompleteProjection(ReportDefinition $report, array $rows): void
    {
        if ($rows === []) {
            foreach ($report->columns as $column) {
                if (str_contains($column->sourcePath, '.')) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
            }

            return;
        }
        foreach ($rows as $row) {
            foreach ($report->columns as $column) {
                if (!array_key_exists($column->alias, $row)) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
            }
        }
    }

    /**
     * Normalize one projected value for its declared report column.
     *
     * A converted amount or quantity is spelled out in full rather than reduced to its figure. The report
     * row is the last place the structure exists — from here the value travels as a cell in a downloaded
     * artifact somebody keeps — so the rate or factor, the as-at instant, the provider and the rounding
     * are written into the value itself, and a reader outside the system can still tell a converted
     * figure from an agreed one.
     *
     * @param   mixed                   $value   Candidate value being validated or normalized.
     * @param   ReportColumnDefinition  $column  Column definition controlling value normalization.
     *
     * @return  bool|int|string|null
     *
     * @since   2.0.0
     */
    private function cell(mixed $value, ReportColumnDefinition $column): bool|int|string|null
    {
        if ($value instanceof ConvertedMoneyValue || $value instanceof ConvertedQuantityValue) {
            $value = $value->toPortableString();
        } elseif ($value instanceof ExactDecimal) {
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
     * Decide whether the definition declares anything the materialization port has to compute.
     *
     * A report with no groups, aggregates, formulas or sorts publishes its authorized rows exactly as they were
     * projected, so nothing crosses to the native executor for it.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  bool  True when at least one group, aggregate, formula or sort is declared.
     *
     * @since   2.0.0
     */
    private function materializes(ReportDefinition $report): bool
    {
        return $report->groups !== [] || $report->aggregates !== [] || $report->formulas !== []
            || $report->sorts !== [];
    }

    /**
     * Return output labels keyed by report column identifier.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array<string, string>
     *
     * @since   2.0.0
     */
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

    /**
     * Return output value types keyed by report column identifier.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array<string, ReportValueType>
     *
     * @since   2.0.0
     */
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
                ReportAggregateFunction::Sum, ReportAggregateFunction::Average => ReportValueType::Decimal,
                default => $aggregate->columnAlias === null
                    ? throw new ReportUnavailable('A report aggregate source type is unavailable.')
                    : ($columns[$aggregate->columnAlias]
                        ?? throw new ReportUnavailable('A report aggregate source type is unavailable.')),
            };
        }
        foreach ($report->formulas as $formula) {
            $types[$formula->alias] = $formula->type;
        }

        return $types;
    }

    /**
     * Test the definition's signed delivery-surface exposure without performing an authorization decision.
     *
     * @param   ReportDefinition      $report   Active immutable report definition.
     * @param   AuthenticatedSurface  $surface  Delivery surface requesting discovery or execution.
     *
     * @return  bool  Whether the definition exposes itself to this surface.
     *
     * @since   2.0.0
     */
    private function surfaceAvailable(ReportDefinition $report, AuthenticatedSurface $surface): bool
    {
        return !(
            ($surface === AuthenticatedSurface::Administrator && !$report->administratorVisible)
            || ($surface === AuthenticatedSurface::Portal && !$report->portalVisible)
            || $surface === AuthenticatedSurface::Recovery
        );
    }

    /**
     * Normalize a scalar report value into bounded text.
     *
     * @param   mixed  $value  Candidate value being validated or normalized.
     *
     * @return  string  Bounded textual representation safe for report output.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('A report text filter requires one string parameter.');
        }

        return $value;
    }

    /**
     * Normalize a report value into a deterministic string set.
     *
     * @param   mixed  $value  Candidate value being validated or normalized.
     *
     * @return  non-empty-list<mixed>
     *
     * @since   2.0.0
     */
    private function set(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('A report set filter requires a non-empty list parameter.');
        }

        return $value;
    }
}
