<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\DecimalValue;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessDefinition\Domain\ExpressionEvaluator;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Expression::class)]
#[CoversClass(ExpressionEvaluator::class)]
#[CoversClass(DecimalValue::class)]
final class ExpressionTest extends TestCase
{
    public function testExactDecimalFormulaHasCanonicalDependenciesAndNoFloatConversion(): void
    {
        $expression = Expression::fromArray([
            'op' => 'multiply',
            'type' => 'decimal',
            'args' => [
                ['op' => 'field', 'type' => 'decimal', 'field' => 'quantity'],
                ['op' => 'field', 'type' => 'decimal', 'field' => 'unit_price'],
            ],
        ]);

        self::assertSame(['quantity', 'unit_price'], $expression->dependencies());
        self::assertSame('24691357802469135780', $expression->evaluate([
            'quantity' => '2.00',
            'unit_price' => '12345678901234567890.00',
        ]));
    }

    public function testDecimalDivisionUsesExplicitScaleAndDeterministicRounding(): void
    {
        $expression = Expression::fromArray([
            'op' => 'divide',
            'type' => 'decimal',
            'scale' => 4,
            'args' => [
                ['op' => 'literal', 'type' => 'decimal', 'value' => '10'],
                ['op' => 'literal', 'type' => 'decimal', 'value' => '6'],
            ],
        ]);

        self::assertSame('1.6667', $expression->evaluate([]));
    }

    public function testFormulaRejectsStoredExecutableCodeAndFloatLiterals(): void
    {
        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray([
            'op' => 'literal',
            'type' => 'decimal',
            'value' => 12.50,
            'php' => 'return 12.5;',
        ]);
    }

    public function testFormulaRejectsMissingDependenciesAtEvaluation(): void
    {
        $expression = Expression::fromArray([
            'op' => 'field',
            'type' => 'string',
            'field' => 'missing',
        ]);

        $this->expectException(InvalidBusinessDefinition::class);
        $expression->evaluate([]);
    }
}
