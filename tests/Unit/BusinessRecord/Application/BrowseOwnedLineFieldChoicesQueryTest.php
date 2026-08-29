<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\Query\BrowseOwnedLineFieldChoicesQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\AggregateFunction;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordAggregate;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrowseOwnedLineFieldChoicesQuery::class)]
/**
 * Pins the bounded two-hop selector transport before owned-line policy planning begins.
 *
 * @since  2.0.0
 */
final class BrowseOwnedLineFieldChoicesQueryTest extends TestCase
{
    /**
     * Preserve every exact source and target-field binding for a valid fifty-row query.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsAClosedOwnedLineFieldChoiceQuery(): void
    {
        $specification = new RecordQuerySpecification(
            pageSize: 50,
            projection: new RecordProjection(['name']),
        );
        $query = new BrowseOwnedLineFieldChoicesQuery(
            AuthorizationContext::human(['business.record.relate']),
            'site.default.order',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb710',
            'lines',
            'product',
            $specification,
        );

        self::assertSame('lines', $query->relationship);
        self::assertSame('product', $query->field);
        self::assertSame($specification, $query->specification);
    }

    /**
     * Reject fan-out, lifecycle, includes, and aggregates before repository access.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnsafeOwnedLineFieldSelectorShapes(): void
    {
        foreach (
            [
            new RecordQuerySpecification(pageSize: 51),
            new RecordQuerySpecification(includeArchived: true),
            new RecordQuerySpecification(includeDeleted: true),
            new RecordQuerySpecification(projection: new RecordProjection(includes: ['orders'])),
            new RecordQuerySpecification(projection: new RecordProjection(
                aggregates: [new RecordAggregate('total', AggregateFunction::Count)],
            )),
            ] as $specification
        ) {
            try {
                new BrowseOwnedLineFieldChoicesQuery(
                    AuthorizationContext::human(['business.record.relate']),
                    'site.default.order',
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb710',
                    'lines',
                    'product',
                    $specification,
                );
                self::fail('The unsafe owned-line field selector query must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
