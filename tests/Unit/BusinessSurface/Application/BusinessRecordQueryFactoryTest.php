<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Query\BooleanFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\RelationFilter;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordQueryFactory;
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

    /**
     * Proves approximate floating-point comparison values cannot enter exact queries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsApproximateComparisonValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be a float');

        (new BusinessRecordQueryFactory())->create(['filter' => [
            'type' => 'comparison',
            'field' => 'amount',
            'operator' => 'eq',
            'value' => 1.25,
        ]]);
    }

    /**
     * Proves a boolean filter cannot exceed its declared child fan-out.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAFilterWiderThanTheDeclaredBooleanBound(): void
    {
        $children = array_fill(0, 17, [
            'type' => 'null',
            'field' => 'reference',
            'is_null' => true,
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 16 children');

        (new BusinessRecordQueryFactory())->create(['filter' => [
            'type' => 'boolean',
            'operator' => 'all',
            'children' => $children,
        ]]);
    }
}
