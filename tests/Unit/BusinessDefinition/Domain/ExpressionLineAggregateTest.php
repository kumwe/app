<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\DecimalValue;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessDefinition\Domain\ExpressionEvaluator;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one leaf that lets a published rule reach past a record into its owned lines.
 *
 * The vocabulary grew for the first time since it was written, so what is pinned here is both halves of
 * the bargain: that "the total equals the sum of the lines" is now expressible and exact, and that the
 * leaf stays as narrow as it was decided to be — one declared collection, one closed reduction, one line
 * field — rather than becoming a query language inside a definition document.
 *
 * @since  2.0.0
 */
#[CoversClass(Expression::class)]
#[CoversClass(ExpressionEvaluator::class)]
#[CoversClass(DecimalValue::class)]
final class ExpressionLineAggregateTest extends TestCase
{
    /**
     * Proves a header total held against the sum of its lines is expressible and reads exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATotalAgreeingWithItsLinesIsExpressibleAndExact(): void
    {
        $expression = self::totalEqualsLineSum();

        self::assertTrue($expression->evaluate(['total' => '30.75'], ['lines' => [
            ['amount' => '10.25'],
            ['amount' => '20.50'],
        ]]));
        self::assertFalse($expression->evaluate(['total' => '30.76'], ['lines' => [
            ['amount' => '10.25'],
            ['amount' => '20.50'],
        ]]));
    }

    /**
     * Proves a thousand exact decimals fold without a float ever appearing in the result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLinesFoldThroughExactDecimalArithmetic(): void
    {
        $expression = Expression::fromArray(self::sumNode());
        $lines = [];
        for ($index = 0; $index < 1000; ++$index) {
            $lines[] = ['amount' => '0.10'];
        }

        $total = $expression->evaluate([], ['lines' => $lines]);

        self::assertIsString($total);
        self::assertSame('100', $total);
    }

    /**
     * Proves the total rule holds when the header's stored scale spells more digits than the sum does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTotalRuleIsJudgedByValueRatherThanBySpelling(): void
    {
        self::assertTrue(self::totalEqualsLineSum()->evaluate(['total' => '30.750'], ['lines' => [
            ['amount' => '10.250'],
            ['amount' => '20.500'],
        ]]));
    }

    /**
     * Proves the reduction skips a line that carries no value rather than reading it as zero.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASumSkipsALineWithNoValueForTheFoldedField(): void
    {
        $expression = Expression::fromArray(self::sumNode());

        self::assertSame('5', $expression->evaluate([], ['lines' => [
            ['amount' => '5.00'],
            ['amount' => null],
        ]]));
    }

    /**
     * Proves an empty collection is a legitimate document that counts zero and sums to zero.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyCollectionCountsZeroAndSumsToZero(): void
    {
        self::assertSame('0', Expression::fromArray(self::sumNode())->evaluate([], ['lines' => []]));
        self::assertSame(0, Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'integer',
            'lines' => 'lines',
            'aggregate' => 'count',
        ])->evaluate([], ['lines' => []]));
    }

    /**
     * Proves a rule reducing a collection the caller never gathered is refused, never reported satisfied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUngatheredCollectionIsRefusedRatherThanJudgedAgainstNothing(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray(self::sumNode())->evaluate([], []);
    }

    /**
     * Proves the folded line field never reaches the header's own dependency list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheFoldedLineFieldIsNamedAsALineDependencyAndNotAsAHeaderField(): void
    {
        $expression = self::totalEqualsLineSum();

        self::assertSame(['total'], $expression->dependencies());
        self::assertSame(['lines' => ['amount']], $expression->lineDependencies());
    }

    /**
     * Proves a count names its collection while folding no line field at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACountNamesItsCollectionWithoutFoldingAField(): void
    {
        $expression = Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'integer',
            'lines' => 'lines',
            'aggregate' => 'count',
        ]);

        self::assertSame(['lines' => []], $expression->lineDependencies());
        self::assertSame(2, $expression->evaluate([], ['lines' => [['amount' => '1'], ['amount' => '2']]]));
    }

    /**
     * Proves the aggregation survives the canonical document a published definition is checksummed over.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAggregationRoundTripsThroughItsCanonicalDocument(): void
    {
        $document = self::totalEqualsLineSum()->toArray();

        self::assertSame($document, Expression::fromArray($document)->toArray());
    }

    /**
     * Proves the reduction vocabulary is closed rather than open to anything a definition names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnsupportedReductionIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'average',
        ]);
    }

    /**
     * Proves a sum must name the one line field it folds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASumWithoutALineFieldIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'aggregate' => 'sum',
        ]);
    }

    /**
     * Proves a count measures the collection and refuses to be pointed at a field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACountCarryingALineFieldIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'integer',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'count',
        ]);
    }

    /**
     * Proves a reduction cannot declare a result type it could not honestly produce.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReductionDeclaringAnImpossibleResultTypeIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'string',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'sum',
        ]);
    }

    /**
     * Proves a malformed collection handle is refused before it can reach a relationship lookup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedCollectionHandleIsRefused(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'Lines; DROP TABLE',
            'field' => 'amount',
            'aggregate' => 'sum',
        ]);
    }

    /**
     * Proves the leaf takes no nested arguments, so no subtree can be smuggled into it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAggregationRefusesNestedArguments(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'sum',
            'args' => [['op' => 'literal', 'type' => 'decimal', 'value' => '1']],
        ]);
    }

    /**
     * Proves a stored line value that is not an exact decimal is refused rather than coerced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASumRefusesALineValueThatIsNotAnExactDecimal(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray(self::sumNode())->evaluate([], ['lines' => [['amount' => 'twelve']]]);
    }

    /**
     * Build the canonical "the total equals the sum of the lines" condition used across this class.
     *
     * @return  Expression  A boolean condition comparing a header field to a line reduction.
     *
     * @since   2.0.0
     */
    private static function totalEqualsLineSum(): Expression
    {
        return Expression::fromArray([
            'op' => 'eq',
            'type' => 'boolean',
            'args' => [
                ['op' => 'field', 'type' => 'decimal', 'field' => 'total'],
                self::sumNode(),
            ],
        ]);
    }

    /**
     * Build the bare decimal reduction node the assertions here fold with.
     *
     * @return  array<string, mixed>  A `line_aggregate` node summing `amount` over the `lines` collection.
     *
     * @since   2.0.0
     */
    private static function sumNode(): array
    {
        return [
            'op' => 'line_aggregate',
            'type' => 'decimal',
            'lines' => 'lines',
            'field' => 'amount',
            'aggregate' => 'sum',
        ];
    }
}
