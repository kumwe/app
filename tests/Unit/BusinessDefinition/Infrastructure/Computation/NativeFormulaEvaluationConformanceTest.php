<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Infrastructure\Computation;

use Kumwe\App\BusinessDefinition\Infrastructure\Computation\NativeFormulaEvaluation;
use Kumwe\App\Tests\Support\NativeComputationContainer;
use Kumwe\BusinessDefinition\Domain\Expression;
use Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Computation\CapabilitySet;
use Kumwe\Computation\ExecutionLimits;
use Kumwe\Computation\ExecutionRefused;
use Kumwe\Computation\NativeCompatibility;
use Kumwe\Computation\RefusalCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the App's composed formula contract by driving every `formula-draft/1` corpus vector through the port.
 *
 * The 101 vectors and their semantics belong to `kumwe/business-definition`; the Engine owns their native
 * execution. What the App owns, and what this test pins, is the composition: the installed corpus is the one
 * the admitted tuple pins, an invalid tree is refused by the package parser before anything crosses the
 * boundary, the values and owned-line collections a caller gathers are marshalled as the profile documents,
 * every accepted value comes back in its declared type, and every refusal reaches the callers as the
 * `InvalidBusinessDefinition` they turn into a validation violation — with the corpus wording for the three
 * refusals the App decides itself and one stable text for the categories the Engine transports.
 *
 * @since  2.0.0
 */
#[CoversClass(NativeFormulaEvaluation::class)]
final class NativeFormulaEvaluationConformanceTest extends TestCase
{
    /**
     * Installed corpus of the profile, relative to the repository.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CORPUS = 'vendor/kumwe/business-definition/resources/corpus/formula-v1.json';

    /**
     * Refusal messages the adapter decides before the native call, exactly as the corpus spells them.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array HOST_REFUSALS = [
        'A formula dependency is unavailable.',
        'An owned-line collection an invariant reduces was not supplied.',
        'Formula inputs cannot contain PHP floats.',
    ];

    /**
     * Stable text every Engine-transported evaluation refusal is reported with.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string NATIVE_REFUSAL = 'A condition or formula could not be evaluated against the supplied values.';

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
            if ($contract->profile === NativeFormulaEvaluation::PROFILE) {
                $digest = $contract->corpusDigest;
                self::assertSame('kumwe/business-definition', $contract->owner);
            }
        }
        self::assertSame(hash_file('sha256', dirname(__DIR__, 5) . '/' . self::CORPUS), $digest);
    }

    /**
     * Every corpus vector replays through the port: values in their declared type, refusals as definition failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCorpusVectorReplaysThroughThePort(): void
    {
        $corpus = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/' . self::CORPUS),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($corpus);
        self::assertIsArray($corpus['vectors']);
        self::assertCount(101, $corpus['vectors']);
        $formulas = NativeComputationContainer::formulas();
        $counts = ['value' => 0, 'parse' => 0, 'evaluate' => 0];
        foreach ($corpus['vectors'] as $vector) {
            self::assertIsArray($vector);
            $id = $vector['id'];
            self::assertIsString($id);
            self::assertIsArray($vector['expression']);
            self::assertIsArray($vector['expected']);
            $expected = $vector['expected'];
            try {
                $expression = Expression::fromArray($vector['expression']);
            } catch (InvalidBusinessDefinition $refused) {
                self::assertSame(['invalid_ast', 'parse'], [$expected['refusal'], $expected['phase']], $id);
                self::assertSame($expected['message'], $refused->getMessage(), $id);
                ++$counts['parse'];
                continue;
            }
            self::assertIsArray($vector['fields']);
            self::assertIsArray($vector['lines']);
            try {
                $value = $formulas->evaluate($expression, $vector['fields'], $vector['lines']);
            } catch (InvalidBusinessDefinition $refused) {
                self::assertSame(['evaluation_refused', 'evaluate'], [$expected['refusal'], $expected['phase']], $id);
                self::assertSame(
                    in_array($expected['message'], self::HOST_REFUSALS, true)
                        ? $expected['message']
                        : self::NATIVE_REFUSAL,
                    $refused->getMessage(),
                    $id,
                );
                ++$counts['evaluate'];
                continue;
            }
            self::assertArrayHasKey('value', $expected, $id);
            self::assertSame($expected['value'], $value, $id);
            ++$counts['value'];
        }
        self::assertSame(['value' => 59, 'parse' => 17, 'evaluate' => 25], $counts);
    }

    /**
     * A batch returns one result per document in order, an empty batch nothing, and a plan is reused across calls.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABatchReturnsOneResultPerDocumentInOrder(): void
    {
        $formulas = NativeComputationContainer::formulas();
        $expression = self::sum();

        self::assertSame([], $formulas->evaluateAll($expression, []));
        self::assertSame(['3.5', '0', '-1'], $formulas->evaluateAll($expression, [
            ['fields' => ['left' => '1', 'right' => '2.5'], 'lines' => []],
            ['fields' => ['left' => '0', 'right' => '0'], 'lines' => []],
            ['fields' => ['left' => '1', 'right' => '-2'], 'lines' => []],
        ]));
        self::assertSame('7', $formulas->evaluate($expression, ['left' => '3', 'right' => '4']));
    }

    /**
     * A batch larger than the document budget is executed in bounded chunks against one plan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABatchLargerThanTheDocumentBudgetIsChunked(): void
    {
        $formulas = new NativeFormulaEvaluation(
            NativeComputationContainer::isolatedAdapter(),
            NativeComputationContainer::compatibility(),
            new ExecutionLimits(maxDocuments: 2),
        );
        $documents = [];
        $expected = [];
        for ($index = 0; $index < 5; ++$index) {
            $documents[] = ['fields' => ['left' => (string) $index, 'right' => '1'], 'lines' => []];
            $expected[] = (string) ($index + 1);
        }

        self::assertSame($expected, $formulas->evaluateAll(self::sum(), $documents));
    }

    /**
     * Plans pushed out of the bounded cache are released and the evaluation keeps working.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlansBeyondTheCacheBoundAreReleased(): void
    {
        $formulas = new NativeFormulaEvaluation(
            NativeComputationContainer::isolatedAdapter(),
            NativeComputationContainer::compatibility(),
        );
        for ($index = 0; $index < 40; ++$index) {
            $literal = Expression::fromArray(['op' => 'literal', 'type' => 'integer', 'value' => $index]);
            self::assertSame($index, $formulas->evaluate($literal, []));
        }
        $first = Expression::fromArray(['op' => 'literal', 'type' => 'integer', 'value' => 0]);

        self::assertSame(0, $formulas->evaluate($first, []));
    }

    /**
     * The three refusals the App decides itself carry the corpus wording and never reach the runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHostDecidedRefusalsKeepTheCorpusWording(): void
    {
        $formulas = NativeComputationContainer::formulas();
        $count = Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'aggregate' => 'sum',
            'field' => 'amount',
        ]);

        self::assertSame('5.25', $formulas->evaluate($count, [], ['lines' => [['amount' => '5.25'], []]]));
        foreach (
            [
                [self::sum(), ['left' => '1'], [], 'A formula dependency is unavailable.'],
                [self::sum(), ['left' => '1', 'right' => 2.5], [], 'Formula inputs cannot contain PHP floats.'],
                [$count, [], [], 'An owned-line collection an invariant reduces was not supplied.'],
                [$count, [], ['lines' => [['amount' => 1.5]]], 'Formula inputs cannot contain PHP floats.'],
                [
                    self::sum(),
                    ['left' => "\xff", 'right' => '1'],
                    [],
                    'A formula input cannot be encoded for evaluation.',
                ],
            ] as [$expression, $fields, $lines, $message]
        ) {
            try {
                $formulas->evaluate($expression, $fields, $lines);
                self::fail($message);
            } catch (InvalidBusinessDefinition $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }
    }

    /**
     * A native budget refusal is reported as a definition failure with its own stable text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANativeBudgetRefusalIsReportedAsADefinitionFailure(): void
    {
        $formulas = new NativeFormulaEvaluation(
            NativeComputationContainer::isolatedAdapter(),
            NativeComputationContainer::compatibility(),
            new ExecutionLimits(maxInstructions: 1),
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('A condition or formula exceeded its native execution budget.');
        $formulas->evaluate(self::sum(), ['left' => '1', 'right' => '2']);
    }

    /**
     * A tuple that does not advertise the profile refuses composition instead of guessing a corpus.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATupleWithoutTheFormulaContractRefusesComposition(): void
    {
        $compatibility = NativeComputationContainer::compatibility();
        $capabilities = $compatibility->capabilities->toArray();
        self::assertIsArray($capabilities['contracts']);
        $capabilities['contracts'] = array_values(array_filter(
            $capabilities['contracts'],
            static fn (mixed $contract): bool => is_array($contract)
                && $contract['profile'] !== NativeFormulaEvaluation::PROFILE,
        ));
        $narrowed = new NativeCompatibility(
            CapabilitySet::fromArray($capabilities),
            $compatibility->extensionVersion,
            $compatibility->embeddedEngineCommit,
            $compatibility->embeddedSourceSha256,
            $compatibility->bindingBuildDigest,
        );

        try {
            new NativeFormulaEvaluation(NativeComputationContainer::isolatedAdapter(), $narrowed);
            self::fail('A tuple without the profile was admitted.');
        } catch (ExecutionRefused $refused) {
            self::assertSame(RefusalCode::IncompatibleCorpus, $refused->reason);
        }
    }

    /**
     * A decimal addition of two fields, the shape every computed field and invariant reduces to.
     *
     * @return  Expression  `left + right` over decimal fields.
     *
     * @since   2.0.0
     */
    private static function sum(): Expression
    {
        return Expression::fromArray([
            'op' => 'add',
            'type' => 'decimal',
            'args' => [
                ['op' => 'field', 'type' => 'decimal', 'field' => 'left'],
                ['op' => 'field', 'type' => 'decimal', 'field' => 'right'],
            ],
        ]);
    }
}
