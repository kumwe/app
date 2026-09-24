<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting\Infrastructure\Computation;

use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessReporting\Infrastructure\Computation\NativeReportMaterialization;
use Kumwe\App\Tests\Support\NativeComputationContainer;
use Kumwe\BusinessDefinition\Domain\Expression;
use Kumwe\Computation\CapabilitySet;
use Kumwe\Computation\ExecutionLimits;
use Kumwe\Computation\ExecutionRefused;
use Kumwe\Computation\NativeCompatibility;
use Kumwe\Computation\RefusalCode;
use Kumwe\Reporting\Domain\ReportColumnDefinition;
use Kumwe\Reporting\Domain\ReportDefinition;
use Kumwe\Reporting\Domain\ReportFormulaDefinition;
use Kumwe\Reporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the App's composed report contract by driving every `report-materialization-draft/1` fixture through
 * the port.
 *
 * The 116 fixtures and their semantics belong to `kumwe/reporting`; the Engine owns their native execution.
 * What the App owns, and what this test pins, is the composition: the installed corpus is the one the
 * admitted tuple pins, the signed definition's columns, groups, aggregates, formulas and sorts form the
 * program, the authorized rows cross as one document, every result row comes back keyed in the order the
 * definition publishes its outputs, and every refusal reaches the delivery layer as `ReportUnavailable`.
 *
 * @since  2.0.0
 */
#[CoversClass(NativeReportMaterialization::class)]
final class NativeReportMaterializationConformanceTest extends TestCase
{
    /**
     * Installed corpus of the profile, relative to the repository.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CORPUS = 'vendor/kumwe/reporting/resources/conformance/report-materialization-v1.json';

    /**
     * The admitted tuple pins the exact installed corpus for the profile the port composes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAdmittedContractIsTheInstalledCorpus(): void
    {
        $digest = null;
        foreach (NativeComputationContainer::compatibility()->capabilities->contracts() as $contract) {
            if ($contract->profile === NativeReportMaterialization::PROFILE) {
                $digest = $contract->corpusDigest;
                self::assertSame('kumwe/reporting', $contract->owner);
            }
        }
        self::assertSame(hash_file('sha256', dirname(__DIR__, 5) . '/' . self::CORPUS), $digest);
    }

    /**
     * Every fixture replays through the port: rows in declared output order, refusals as unavailable reports.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryFixtureReplaysThroughThePort(): void
    {
        $corpus = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/' . self::CORPUS),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($corpus);
        self::assertIsArray($corpus['fixtures']);
        self::assertCount(116, $corpus['fixtures']);
        $reports = NativeComputationContainer::reports();
        $counts = ['rows' => 0, 'refused' => 0];
        foreach ($corpus['fixtures'] as $fixture) {
            self::assertIsArray($fixture);
            $id = $fixture['id'];
            self::assertIsString($id);
            self::assertIsArray($fixture['plan']);
            self::assertIsArray($fixture['authorized_rows']);
            self::assertIsArray($fixture['expected']);
            $report = ReportDefinition::fromArray($fixture['plan']);
            try {
                $rows = $reports->materialize($report, $fixture['authorized_rows']);
            } catch (ReportUnavailable $refused) {
                self::assertSame(ReportUnavailable::class, $fixture['expected']['refusal_class'] ?? null, $id);
                self::assertSame('The report is unavailable.', $refused->getMessage(), $id);
                ++$counts['refused'];
                continue;
            }
            self::assertSame($fixture['expected']['rows'] ?? null, $rows, $id);
            ++$counts['rows'];
        }
        self::assertSame(['rows' => 54, 'refused' => 62], $counts);
    }

    /**
     * A row the port cannot encode is refused before anything crosses to the runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnencodableRowIsRefusedBeforeTheNativeCall(): void
    {
        $this->expectException(ReportUnavailable::class);
        $this->expectExceptionMessage('A report row cannot be encoded for materialization.');
        NativeComputationContainer::reports()->materialize(self::report(), [['n' => "\xff"]]);
    }

    /**
     * A budget the Engine exhausts is reported as an unavailable report with its own stable text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExhaustedNativeBudgetIsReportedAsUnavailable(): void
    {
        $reports = new NativeReportMaterialization(
            NativeComputationContainer::isolatedAdapter(),
            NativeComputationContainer::compatibility(),
            new ExecutionLimits(maxInputBytes: 67108864, maxOutputBytes: 16777216, maxInstructions: 1),
        );

        $this->expectException(ReportUnavailable::class);
        $this->expectExceptionMessage('The report exceeds the native materialization budget.');
        $reports->materialize(self::report(), [['n' => 2], ['n' => 3]]);
    }

    /**
     * A definition the Engine cannot plan, such as a formula reducing owned lines, is reported as unavailable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnplannableDefinitionIsReportedAsUnavailable(): void
    {
        $report = new ReportDefinition(
            'acme.report',
            1,
            'Report',
            'acme.record',
            'acme.report.read',
            [],
            [],
            [new ReportColumnDefinition('n', 'N', 'n', ReportValueType::Integer)],
            formulas: [new ReportFormulaDefinition('lines', 'Lines', ReportValueType::Integer, Expression::fromArray([
                'op' => 'line_aggregate',
                'type' => 'integer',
                'lines' => 'hidden',
                'aggregate' => 'count',
            ]))],
        );

        $this->expectException(ReportUnavailable::class);
        $this->expectExceptionMessage('The report computation plan is invalid.');
        NativeComputationContainer::reports()->materialize($report, [['n' => 1]]);
    }

    /**
     * A tuple that does not advertise the profile refuses composition instead of guessing a corpus.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATupleWithoutTheReportContractRefusesComposition(): void
    {
        $compatibility = NativeComputationContainer::compatibility();
        $capabilities = $compatibility->capabilities->toArray();
        self::assertIsArray($capabilities['contracts']);
        $capabilities['contracts'] = array_values(array_filter(
            $capabilities['contracts'],
            static fn (mixed $contract): bool => is_array($contract)
                && $contract['profile'] !== NativeReportMaterialization::PROFILE,
        ));
        $narrowed = new NativeCompatibility(
            CapabilitySet::fromArray($capabilities),
            $compatibility->extensionVersion,
            $compatibility->embeddedEngineCommit,
            $compatibility->embeddedSourceSha256,
            $compatibility->bindingBuildDigest,
        );

        try {
            new NativeReportMaterialization(NativeComputationContainer::isolatedAdapter(), $narrowed);
            self::fail('A tuple without the profile was admitted.');
        } catch (ExecutionRefused $refused) {
            self::assertSame(RefusalCode::IncompatibleCorpus, $refused->reason);
        }
    }

    /**
     * One integer column doubled by a formula and sorted descending: the shape the Engine's own tests use.
     *
     * @return  ReportDefinition  Definition with one column, one formula and one sort.
     *
     * @since   2.0.0
     */
    private static function report(): ReportDefinition
    {
        return ReportDefinition::fromArray([
            'identifier' => 'acme.report',
            'version' => 1,
            'title' => 'Report',
            'source_definition' => 'acme.record',
            'required_capability' => 'acme.report.read',
            'administrator_visible' => true,
            'portal_visible' => false,
            'parameters' => [],
            'filters' => [],
            'columns' => [['alias' => 'n', 'label' => 'N', 'source' => 'n', 'type' => 'integer']],
            'groups' => [],
            'aggregates' => [],
            'formulas' => [[
                'alias' => 'double_n',
                'label' => 'Double',
                'type' => 'integer',
                'expression' => [
                    'op' => 'multiply',
                    'type' => 'integer',
                    'args' => [
                        ['op' => 'field', 'type' => 'integer', 'field' => 'n'],
                        ['op' => 'literal', 'type' => 'integer', 'value' => 2],
                    ],
                ],
            ]],
            'sorts' => [['output' => 'double_n', 'direction' => 'desc', 'nulls_last' => true]],
            'drill_downs' => [],
            'synchronous_row_cap' => 1000,
        ]);
    }
}
