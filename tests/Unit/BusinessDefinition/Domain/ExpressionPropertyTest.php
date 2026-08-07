<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Expression::class)]
#[CoversClass(CanonicalDefinitionJson::class)]
final class ExpressionPropertyTest extends TestCase
{
    public function testIntegerAndExactDecimalOperatorsRemainDeterministicAcrossGeneratedInputs(): void
    {
        mt_srand(20260807);
        for ($iteration = 0; $iteration < 500; ++$iteration) {
            $left = mt_rand(-1_000_000, 1_000_000);
            $right = mt_rand(-1_000_000, 1_000_000);
            $integer = Expression::fromArray([
                'op' => 'add', 'type' => 'integer', 'args' => [
                    ['op' => 'literal', 'type' => 'integer', 'value' => $left],
                    ['op' => 'literal', 'type' => 'integer', 'value' => $right],
                ],
            ]);
            self::assertSame($left + $right, $integer->evaluate([]));

            $decimal = Expression::fromArray([
                'op' => 'multiply', 'type' => 'decimal', 'args' => [
                    ['op' => 'literal', 'type' => 'decimal', 'value' => $left . '.25'],
                    ['op' => 'literal', 'type' => 'decimal', 'value' => '4'],
                ],
            ]);
            self::assertSame((string) (($left * 4) + ($left < 0 ? -1 : 1)), $decimal->evaluate([]));
        }
    }

    public function testCanonicalObjectsHaveTheSameChecksumForEveryKeyPermutation(): void
    {
        $expected = CanonicalDefinitionJson::checksum(['alpha' => 1, 'beta' => ['x' => true, 'y' => 'z']]);
        self::assertSame($expected, CanonicalDefinitionJson::checksum([
            'beta' => ['y' => 'z', 'x' => true],
            'alpha' => 1,
        ]));
    }

    public function testFuzzedExecutableOperatorsAreAlwaysRejected(): void
    {
        foreach (['eval', 'php', 'sql', 'twig', 'javascript', 'shell_exec'] as $operator) {
            try {
                Expression::fromArray(['op' => $operator, 'type' => 'string', 'args' => []]);
                self::fail('Executable expression operator ' . $operator . ' was accepted.');
            } catch (InvalidBusinessDefinition) {
                self::assertTrue(true);
            }
        }
    }

    public function testGeneratedExpressionTreesCannotExceedComplexityLimits(): void
    {
        $tree = ['op' => 'literal', 'type' => 'boolean', 'value' => true];
        for ($depth = 0; $depth < 8; ++$depth) {
            $tree = ['op' => 'and', 'type' => 'boolean', 'args' => [$tree, $tree]];
        }

        $this->expectException(InvalidBusinessDefinition::class);
        Expression::fromArray($tree);
    }

    public function testStrictAstShapesAndTypesRejectAmbiguousExecution(): void
    {
        foreach (
            [
            ['op' => 'field', 'type' => 'string', 'field' => 'name', 'value' => 'hidden'],
            [
                'op' => 'add', 'type' => 'integer', 'args' => [
                    ['op' => 'literal', 'type' => 'integer', 'value' => 1],
                    ['op' => 'literal', 'type' => 'decimal', 'value' => '2'],
                ],
            ],
            [
                'op' => 'contains', 'type' => 'boolean', 'args' => [
                    ['op' => 'literal', 'type' => 'integer', 'value' => 1],
                    ['op' => 'literal', 'type' => 'integer', 'value' => 2],
                ],
            ],
            ] as $document
        ) {
            try {
                Expression::fromArray($document);
                self::fail('An ambiguous or incorrectly typed AST was accepted.');
            } catch (InvalidBusinessDefinition) {
                self::assertTrue(true);
            }
        }
    }

    public function testEvaluationRejectsWrongRuntimeTypesAndIntegerOverflow(): void
    {
        $field = Expression::fromArray(['op' => 'field', 'type' => 'integer', 'field' => 'amount']);
        try {
            $field->evaluate(['amount' => '1']);
            self::fail('A runtime value that conflicts with its AST type was accepted.');
        } catch (InvalidBusinessDefinition) {
            self::assertTrue(true);
        }

        $overflow = Expression::fromArray([
            'op' => 'add', 'type' => 'integer', 'args' => [
                ['op' => 'literal', 'type' => 'integer', 'value' => PHP_INT_MAX],
                ['op' => 'literal', 'type' => 'integer', 'value' => 1],
            ],
        ]);
        $this->expectException(InvalidBusinessDefinition::class);
        $overflow->evaluate([]);
    }
}
