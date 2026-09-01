<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Policy;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBoolean;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBooleanOperator;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyEvaluator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyNullCheck;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordPolicySet::class)]
#[CoversClass(RecordPolicySchema::class)]
#[CoversClass(RecordPolicyEvaluator::class)]
#[CoversClass(RecordPolicyBoolean::class)]
#[CoversClass(RecordPolicyNullCheck::class)]
#[CoversClass(RecordPolicyConstant::class)]
final class RecordPolicyTest extends TestCase
{
    public function testNoAllowDeniesAndMatchingDenyOverridesAllow(): void
    {
        $schema = new RecordPolicySchema([
            'owner_id' => RecordPolicyValueType::String,
            'status' => RecordPolicyValueType::String,
        ]);
        self::assertFalse((new RecordPolicySet($schema))->allows([
            'owner_id' => 'actor:one',
            'status' => 'ready',
        ]));

        $policy = new RecordPolicySet(
            $schema,
            [new RecordPolicyComparison(
                'owner_id',
                RecordPolicyComparisonOperator::Equal,
                RecordPolicyValueType::String,
                'actor:one',
            )],
            [new RecordPolicyComparison(
                'status',
                RecordPolicyComparisonOperator::Equal,
                RecordPolicyValueType::String,
                'blocked',
            )],
        );
        self::assertTrue($policy->allows(['owner_id' => 'actor:one', 'status' => 'ready']));
        self::assertFalse($policy->allows(['owner_id' => 'actor:one', 'status' => 'blocked']));
        self::assertFalse($policy->allows(['owner_id' => 'actor:two', 'status' => 'ready']));
    }

    public function testEvaluatorUsesDefiniteNullAndExactDecimalSemantics(): void
    {
        $schema = new RecordPolicySchema([
            'amount' => RecordPolicyValueType::Decimal,
            'assignee' => RecordPolicyValueType::String,
        ]);
        $policy = new RecordPolicySet($schema, [new RecordPolicyBoolean(
            RecordPolicyBooleanOperator::All,
            [
                new RecordPolicyComparison(
                    'amount',
                    RecordPolicyComparisonOperator::GreaterThanOrEqual,
                    RecordPolicyValueType::Decimal,
                    '12.30',
                ),
                new RecordPolicyNullCheck('assignee'),
            ],
        )]);
        $evaluator = new RecordPolicyEvaluator();

        self::assertTrue($evaluator->allows($policy, [
            'amount' => ExactDecimal::fromString('12.3000', 8, 4),
            'assignee' => null,
        ]));
        self::assertFalse($evaluator->allows($policy, [
            'amount' => ExactDecimal::fromString('12.2999', 8, 4),
            'assignee' => null,
        ]));
        self::assertFalse($evaluator->allows($policy, ['amount' => null, 'assignee' => null]));
    }

    public function testCanonicalOrderDoesNotChangePolicyDocument(): void
    {
        $schema = new RecordPolicySchema([
            'enabled' => RecordPolicyValueType::Boolean,
            'owner_id' => RecordPolicyValueType::String,
        ]);
        $owner = new RecordPolicyComparison(
            'owner_id',
            RecordPolicyComparisonOperator::Equal,
            RecordPolicyValueType::String,
            'actor:one',
        );
        $enabled = new RecordPolicyComparison(
            'enabled',
            RecordPolicyComparisonOperator::Equal,
            RecordPolicyValueType::Boolean,
            true,
        );

        self::assertSame(
            (new RecordPolicySet($schema, [$owner, $enabled]))->toArray(),
            (new RecordPolicySet($schema, [$enabled, $owner]))->toArray(),
        );
        self::assertSame(
            (new RecordPolicyBoolean(RecordPolicyBooleanOperator::Any, [$owner, $enabled]))->toArray(),
            (new RecordPolicyBoolean(RecordPolicyBooleanOperator::Any, [$enabled, $owner]))->toArray(),
        );
    }

    public function testTypeAndComplexityBoundsAreEnforcedBeforeEvaluation(): void
    {
        $schema = new RecordPolicySchema(['owner_id' => RecordPolicyValueType::String]);
        $this->expectException(InvalidArgumentException::class);
        new RecordPolicySet($schema, [new RecordPolicyComparison(
            'owner_id',
            RecordPolicyComparisonOperator::Equal,
            RecordPolicyValueType::Integer,
            7,
        )]);
    }

    public function testOperationBoundRejectsOversizedTrees(): void
    {
        $schema = new RecordPolicySchema(['owner_id' => RecordPolicyValueType::String]);
        $leaf = new RecordPolicyComparison(
            'owner_id',
            RecordPolicyComparisonOperator::Equal,
            RecordPolicyValueType::String,
            'actor:one',
        );
        $groups = [];
        for ($index = 0; $index < 16; ++$index) {
            $groups[] = new RecordPolicyBoolean(RecordPolicyBooleanOperator::Any, array_fill(0, 4, $leaf));
        }

        $this->expectException(InvalidArgumentException::class);
        new RecordPolicySet($schema, [new RecordPolicyBoolean(RecordPolicyBooleanOperator::All, $groups)]);
    }

    public function testSchemaBoundMatchesTheMaximumBusinessDefinitionFieldCount(): void
    {
        $fields = [];
        for ($index = 0; $index < 256; ++$index) {
            $fields['field_' . $index] = RecordPolicyValueType::String;
        }
        self::assertCount(256, (new RecordPolicySchema($fields))->toArray());

        $fields['field_256'] = RecordPolicyValueType::String;
        $this->expectException(InvalidArgumentException::class);
        new RecordPolicySchema($fields);
    }

    public function testDepthBoundRejectsNineLevelTree(): void
    {
        $schema = new RecordPolicySchema(['owner_id' => RecordPolicyValueType::String]);
        $predicate = new RecordPolicyComparison(
            'owner_id',
            RecordPolicyComparisonOperator::Equal,
            RecordPolicyValueType::String,
            'actor:one',
        );
        for ($depth = 0; $depth < 8; ++$depth) {
            $predicate = new RecordPolicyBoolean(RecordPolicyBooleanOperator::All, [$predicate]);
        }

        $this->expectException(InvalidArgumentException::class);
        new RecordPolicySet($schema, [$predicate]);
    }

    public function testTemporalEvaluatorUsesTheSameDateTimeAndInstantDomainsAsPersistence(): void
    {
        $schema = new RecordPolicySchema([
            'service_date' => RecordPolicyValueType::Temporal,
            'service_time' => RecordPolicyValueType::Temporal,
            'recorded_at' => RecordPolicyValueType::Temporal,
        ]);
        $policy = new RecordPolicySet($schema, [new RecordPolicyBoolean(
            RecordPolicyBooleanOperator::All,
            [
                new RecordPolicyComparison(
                    'service_date',
                    RecordPolicyComparisonOperator::Equal,
                    RecordPolicyValueType::Temporal,
                    '2026-08-09',
                ),
                new RecordPolicyComparison(
                    'service_time',
                    RecordPolicyComparisonOperator::Equal,
                    RecordPolicyValueType::Temporal,
                    '12:30:15.120000',
                ),
                new RecordPolicyComparison(
                    'recorded_at',
                    RecordPolicyComparisonOperator::LessThanOrEqual,
                    RecordPolicyValueType::Temporal,
                    '2026-08-09T10:30:15.120000Z',
                ),
            ],
        )]);

        self::assertTrue($policy->allows([
            'service_date' => new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
            'service_time' => new DateTimeImmutable('1970-01-01T12:30:15.120000+00:00'),
            'recorded_at' => new DateTimeImmutable('2026-08-09T10:30:15.120000+00:00'),
        ]));
        self::assertTrue($policy->allows([
            'service_date' => '2026-08-09T00:00:00.000000+00:00',
            'service_time' => '1970-01-01T12:30:15.120000+00:00',
            'recorded_at' => '2026-08-09T10:30:15.120000+00:00',
        ]));

        $wholeSecond = new RecordPolicySet(
            new RecordPolicySchema(['service_time' => RecordPolicyValueType::Temporal]),
            [new RecordPolicyComparison(
                'service_time',
                RecordPolicyComparisonOperator::Equal,
                RecordPolicyValueType::Temporal,
                '12:30:15',
            )],
        );
        self::assertTrue($wholeSecond->allows([
            'service_time' => new DateTimeImmutable('1970-01-01T12:30:15.000000+00:00'),
        ]));
        self::assertFalse($wholeSecond->allows([
            'service_time' => new DateTimeImmutable('1970-01-01T12:30:15.120000+00:00'),
        ]));
    }
}
