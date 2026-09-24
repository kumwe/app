<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure\Computation;

use JsonException;
use Kumwe\App\BusinessReporting\Application\ReportMaterialization;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\Computation\CapabilitySet;
use Kumwe\Computation\CompiledProgram;
use Kumwe\Computation\ContractIdentity;
use Kumwe\Computation\DocumentBatch;
use Kumwe\Computation\DocumentInput;
use Kumwe\Computation\ExecutionLimits;
use Kumwe\Computation\ExecutionRefused;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeCompatibility;
use Kumwe\Computation\PlanIdentity;
use Kumwe\Computation\ProgramEnvelope;
use Kumwe\Computation\RefusalCode;
use Kumwe\Reporting\Domain\ReportDefinition;
use stdClass;

/**
 * Materializes report rows through the admitted native `report-materialization-draft/1` program executor.
 *
 * One report execution compiles one plan from the definition's columns, groups, aggregates, formulas and sorts,
 * executes it over the complete authorized row set as one document and releases the plan, so the runtime's
 * bounded plan pool never accumulates report plans. The Engine returns each row as an object whose members it
 * orders by name; the adapter restores the declared output order — group columns or every column, then
 * aggregates, then formulas — because the API and CSV deliveries publish rows in that order. The profile
 * admits at most 100000 rows and 16 MiB of result bytes; a larger report is refused as unavailable rather
 * than computed elsewhere.
 *
 * @since  2.0.0
 */
final readonly class NativeReportMaterialization implements ReportMaterialization
{
    /**
     * Semantic profile the App composes for report materialization.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PROFILE = 'report-materialization-draft/1';

    /**
     * Program version the App declares for every report plan it compiles.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROGRAM_VERSION = '1.0.0';

    /**
     * Prefix of the host generation token, completed with the report definition version.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GENERATION_PREFIX = 'report-version-';

    /**
     * Name of the one document shape this adapter marshals: a `rows` list under `fields`.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DOCUMENT_SCHEMA = 'app-report-rows-1';

    /**
     * Correlation token of the single document one execution carries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CORRELATION = 'report';

    /**
     * Definition members that form the materialization program, in the order the profile documents them.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PROGRAM_KEYS = ['columns', 'groups', 'aggregates', 'formulas', 'sorts'];

    /**
     * Encoder flags for the opaque document bytes: exact spellings, unescaped text, errors thrown.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * Exact contract the admitted tuple advertises for the profile.
     *
     * @var    ContractIdentity
     * @since  2.0.0
     */
    private ContractIdentity $contract;

    /**
     * Complete capability tuple every plan identity is bound to.
     *
     * @var    CapabilitySet
     * @since  2.0.0
     */
    private CapabilitySet $capabilities;

    /**
     * Digest of the document shape name, carried as the plan's schema identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $schemaDigest;

    /**
     * Digest of the empty compilation options the App applies.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $optionsDigest;

    /**
     * Bind the adapter to the shared native plan owner and the admitted contract.
     *
     * @param   NativeAdapter        $adapter        Shared package adapter that compiles, executes and
     *          releases plans.
     * @param   NativeCompatibility  $compatibility  Independently recorded tuple naming the contracts.
     * @param   ExecutionLimits      $limits         Budgets for one execution; the input ceiling admits the
     *          100000-row export and the output budget is the profile's 16 MiB ceiling.
     *
     * @throws  ExecutionRefused  When the admitted tuple advertises no `report-materialization-draft/1` contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        private NativeAdapter $adapter,
        NativeCompatibility $compatibility,
        private ExecutionLimits $limits = new ExecutionLimits(maxInputBytes: 67108864, maxOutputBytes: 16777216),
    ) {
        $contract = null;
        foreach ($compatibility->capabilities->contracts() as $candidate) {
            if ($candidate->profile === self::PROFILE) {
                $contract = $candidate;
                break;
            }
        }
        if ($contract === null) {
            throw new ExecutionRefused(RefusalCode::IncompatibleCorpus);
        }
        $this->contract = $contract;
        $this->capabilities = $compatibility->capabilities;
        $this->schemaDigest = hash('sha256', self::DOCUMENT_SCHEMA);
        $this->optionsDigest = hash('sha256', '{}');
    }

    /**
     * Materialize the declared groups, aggregates, formulas and sorts over the authorized rows.
     *
     * @param   ReportDefinition                           $report  Signed definition to apply.
     * @param   list<array<string, bool|int|string|null>>  $rows    Complete policy-filtered rows.
     *
     * @return  list<array<string, bool|int|string|null>>  Result rows in declared output order.
     *
     * @throws  ReportUnavailable  When the Engine refuses the rows, the plan, or the budget.
     *
     * @since   2.0.0
     */
    public function materialize(ReportDefinition $report, array $rows): array
    {
        $program = new ProgramEnvelope(
            $this->contract,
            self::PROGRAM_VERSION,
            CanonicalDefinitionJson::encode(
                array_intersect_key($report->toArray(), array_fill_keys(self::PROGRAM_KEYS, true)),
            ),
        );
        $identity = new PlanIdentity(
            $this->contract,
            self::PROGRAM_VERSION,
            $program->digest(),
            self::GENERATION_PREFIX . $report->version,
            $report->checksum(),
            $this->schemaDigest,
            $this->optionsDigest,
            $this->capabilities,
        );
        try {
            $plan = $this->adapter->compile($program, $identity, $this->limits);
        } catch (ExecutionRefused $refused) {
            throw $this->refusal($refused);
        }
        try {
            $bytes = $this->execute($plan, $this->document($rows));
        } finally {
            $this->adapter->release($plan);
        }

        return $this->rows($report, $bytes);
    }

    /**
     * Execute the plan over the one document and return the opaque result bytes.
     *
     * @param   CompiledProgram  $plan      Plan compiled for this execution.
     * @param   string           $document  Opaque row document.
     *
     * @return  string  Engine-authored `{"findings":[],"rows":[...]}` bytes.
     *
     * @throws  ReportUnavailable  When the Engine refuses the rows or the budget.
     *
     * @since   2.0.0
     */
    private function execute(CompiledProgram $plan, string $document): string
    {
        try {
            $input = new DocumentInput(self::CORRELATION, $this->contract, $document);
            $batch = new DocumentBatch([$input], $this->limits);

            return $this->adapter->execute($plan, $batch, $this->limits)->results()[0]->bytes;
        } catch (ExecutionRefused $refused) {
            throw $this->refusal($refused);
        }
    }

    /**
     * Marshal the authorized rows into the opaque document bytes.
     *
     * @param   list<array<string, bool|int|string|null>>  $rows  Complete policy-filtered rows.
     *
     * @return  string  JSON object whose `fields.rows` is the list of row objects and whose `lines` is empty.
     *
     * @throws  ReportUnavailable  When a cell cannot be encoded.
     *
     * @since   2.0.0
     */
    private function document(array $rows): string
    {
        $items = [];
        foreach ($rows as $row) {
            $item = new stdClass();
            foreach ($row as $alias => $cell) {
                $item->{$alias} = $cell;
            }
            $items[] = $item;
        }
        try {
            return json_encode(['fields' => ['rows' => $items], 'lines' => new stdClass()], self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new ReportUnavailable('A report row cannot be encoded for materialization.', previous: $exception);
        }
    }

    /**
     * Decode the opaque result bytes and restore the declared output order of every row.
     *
     * @param   ReportDefinition  $report  Definition naming the outputs and their order.
     * @param   string            $bytes   Engine-authored result bytes.
     *
     * @return  list<array<string, bool|int|string|null>>  Rows keyed by alias in declared order.
     *
     * @throws  ExecutionRefused  When the bytes are not the profile's result shape.
     *
     * @since   2.0.0
     */
    private function rows(ReportDefinition $report, string $bytes): array
    {
        try {
            $decoded = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }
        $results = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
        if (
            !is_array($decoded)
            || ($decoded['findings'] ?? null) !== []
            || !is_array($results)
            || !array_is_list($results)
        ) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }
        $outputs = $this->outputs($report);
        $rows = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                throw new ExecutionRefused(RefusalCode::InternalFailure);
            }
            $ordered = [];
            foreach ($outputs as $alias) {
                if (array_key_exists($alias, $row)) {
                    $ordered[$alias] = self::cell($row[$alias]);
                    unset($row[$alias]);
                }
            }
            if ($row !== []) {
                throw new ExecutionRefused(RefusalCode::InternalFailure);
            }
            $rows[] = $ordered;
        }

        return $rows;
    }

    /**
     * Name the output aliases in the order the definition publishes them.
     *
     * @param   ReportDefinition  $report  Definition whose outputs are ordered.
     *
     * @return  list<string>  Group columns, or every column when nothing is grouped or aggregated, then the
     *          aggregates, then the formulas.
     *
     * @since   2.0.0
     */
    private function outputs(ReportDefinition $report): array
    {
        $outputs = [];
        if ($report->groups === [] && $report->aggregates === []) {
            foreach ($report->columns as $column) {
                $outputs[] = $column->alias;
            }
        }
        foreach ($report->groups as $group) {
            $outputs[] = $group->columnAlias;
        }
        foreach ($report->aggregates as $aggregate) {
            $outputs[] = $aggregate->alias;
        }
        foreach ($report->formulas as $formula) {
            $outputs[] = $formula->alias;
        }

        return $outputs;
    }

    /**
     * Admit one result cell, refusing anything the profile does not return.
     *
     * @param   mixed  $value  Decoded cell.
     *
     * @return  bool|int|string|null  The cell unchanged.
     *
     * @throws  ExecutionRefused  When the cell is a float or a structure.
     *
     * @since   2.0.0
     */
    private static function cell(mixed $value): bool|int|string|null
    {
        if ($value !== null && !is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new ExecutionRefused(RefusalCode::InternalFailure);
        }

        return $value;
    }

    /**
     * Map a native refusal to the report exception the delivery layer already handles.
     *
     * @param   ExecutionRefused  $refused  Package refusal with its stable category.
     *
     * @return  ExecutionRefused|ReportUnavailable  The report-shaped refusal, or the fault itself.
     *
     * @since   2.0.0
     */
    private function refusal(ExecutionRefused $refused): ExecutionRefused|ReportUnavailable
    {
        return match ($refused->reason) {
            RefusalCode::InvalidInput => new ReportUnavailable('The report is unavailable.', previous: $refused),
            RefusalCode::InvalidProgram => new ReportUnavailable(
                'The report computation plan is invalid.',
                previous: $refused,
            ),
            RefusalCode::ExhaustedLimit => new ReportUnavailable(
                'The report exceeds the native materialization budget.',
                previous: $refused,
            ),
            default => $refused,
        };
    }
}
