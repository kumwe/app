<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationFilter;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordQueryFactory::class)]
/**
 * Proves every adapter shares one closed and bounded generated-business query grammar.
 *
 * @since  2.0.0
 */
final class BusinessRecordQueryFactoryTest extends TestCase
{
    /**
     * Proves the complete documented query object maps to the canonical typed specification.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuildsTheCompleteClosedBoundedQueryGrammar(): void
    {
        $query = (new BusinessRecordQueryFactory())->create([
            'filter' => [
                'type' => 'boolean',
                'operator' => 'all',
                'children' => [
                    ['type' => 'comparison', 'field' => 'amount', 'operator' => 'gte', 'value' => '10.00'],
                    [
                        'type' => 'relation',
                        'relationship' => 'customer',
                        'quantifier' => 'any',
                        'target' => [
                            'type' => 'text',
                            'field' => 'name',
                            'operator' => 'starts_with',
                            'text' => 'A',
                        ],
                    ],
                ],
            ],
            'search' => ['term' => 'priority', 'fields' => ['name', 'reference']],
            'sorts' => [['field' => 'amount', 'direction' => 'desc', 'nulls_last' => false]],
            'page_size' => 25,
            'projection' => [
                'fields' => ['name', 'amount'],
                'includes' => ['customer'],
                'aggregates' => [['alias' => 'total', 'function' => 'sum', 'field' => 'amount']],
            ],
            'include_archived' => true,
            'include_deleted' => false,
        ]);

        self::assertInstanceOf(BooleanFilter::class, $query->filter);
        self::assertInstanceOf(ComparisonFilter::class, $query->filter->children[0]);
        self::assertInstanceOf(RelationFilter::class, $query->filter->children[1]);
        self::assertSame(25, $query->pageSize);
        self::assertSame(['name', 'amount'], $query->projection->fields);
        self::assertSame(['customer'], $query->projection->includes);
        self::assertSame('10.00', $query->filter->children[0]->value);
        self::assertTrue($query->includeArchived);
        self::assertFalse($query->includeDeleted);
    }

    /**
     * Proves caller-controlled properties outside the query vocabulary are rejected.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnknownMembersInsteadOfIgnoringThem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown property');

        (new BusinessRecordQueryFactory())->create(['organization' => 'untrusted']);
    }
}
