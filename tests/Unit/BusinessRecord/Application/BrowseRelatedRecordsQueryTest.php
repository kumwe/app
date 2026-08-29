<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRelatedRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrowseRelatedRecordsQuery::class)]
/**
 * Proves relation selectors accept only closed, bounded, source-aware queries.
 *
 * @since  2.0.0
 */
final class BrowseRelatedRecordsQueryTest extends TestCase
{
    /**
     * Proves create and update selectors accept a bounded fifty-row target projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsAClosedFiftyRowRelationshipChoiceQuery(): void
    {
        $specification = new RecordQuerySpecification(
            pageSize: 50,
            projection: new RecordProjection(['name']),
        );
        $query = new BrowseRelatedRecordsQuery(
            AuthorizationContext::human(['business.record.relate']),
            'site.default.invoice',
            'customer',
            'business.record.relate',
            'invoice-1',
            $specification,
        );

        self::assertSame('customer', $query->relatedHandle);
        self::assertSame($specification, $query->specification);

        $create = new BrowseRelatedRecordsQuery(
            AuthorizationContext::human(['business.record.create']),
            'site.default.invoice',
            'customer',
            'business.record.create',
            null,
            $specification,
        );
        self::assertNull($create->sourceRecordId);
    }

    /**
     * Proves selectors reject excess fan-out, lifecycle widening, and nested includes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsSelectorFanoutLifecycleAndIncludesBeforeRepositoryAccess(): void
    {
        $context = AuthorizationContext::human(['business.record.relate']);
        foreach (
            [
            new RecordQuerySpecification(pageSize: 51),
            new RecordQuerySpecification(includeArchived: true),
            new RecordQuerySpecification(includeDeleted: true),
            new RecordQuerySpecification(projection: new RecordProjection(includes: ['orders'])),
            ] as $specification
        ) {
            try {
                new BrowseRelatedRecordsQuery(
                    $context,
                    'site.default.invoice',
                    'customer',
                    'business.record.relate',
                    'invoice-1',
                    $specification,
                );
                self::fail('The unsafe relationship selector query must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * Proves a relation selector refuses a malformed source handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnInvalidSourceHandle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrowseRelatedRecordsQuery(
            AuthorizationContext::human(['business.record.relate']),
            'site.default.invoice',
            'Customer ID',
            'business.record.relate',
            'invoice-1',
            new RecordQuerySpecification(),
        );
    }

    /**
     * Proves update and relate selectors require an existing source record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsARecordlessUpdateSelector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrowseRelatedRecordsQuery(
            AuthorizationContext::human(['business.record.update']),
            'site.default.invoice',
            'customer',
            'business.record.update',
            null,
            new RecordQuerySpecification(),
        );
    }
}
