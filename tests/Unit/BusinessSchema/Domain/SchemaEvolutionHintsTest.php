<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Domain;

use Kumwe\App\BusinessDefinition\Domain\Expression;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaEvolutionHints::class)]
final class SchemaEvolutionHintsTest extends TestCase
{
    public function testLiteralAndExpressionBackfillsRoundTripCanonically(): void
    {
        $hints = SchemaEvolutionHints::fromArray([
            'backfills' => [
                'counter' => 0,
                'display_name' => ['expression' => [
                    'op' => 'coalesce',
                    'type' => 'string',
                    'args' => [
                        ['op' => 'field', 'type' => 'string', 'field' => 'legacy_name'],
                        ['op' => 'literal', 'type' => 'string', 'value' => 'Unknown'],
                    ],
                ]],
            ],
        ]);

        self::assertSame(0, $hints->backfill('counter'));
        self::assertInstanceOf(Expression::class, $hints->backfill('display_name'));
        self::assertSame($hints->checksum(), SchemaEvolutionHints::fromArray($hints->toArray())->checksum());
    }

    public function testAmbiguousRenameAndEvolutionKeyTyposFailClosed(): void
    {
        $this->expectException(InvalidBusinessSchema::class);
        SchemaEvolutionHints::fromArray([
            'column_renames' => ['old_name' => 'name', 'older_name' => 'name'],
        ]);
    }
}
