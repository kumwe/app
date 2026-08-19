<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\App\BusinessRecord\Query\BooleanFilter;
use Kumwe\App\BusinessRecord\Query\BooleanOperator;
use Kumwe\App\BusinessRecord\Query\ComparisonFilter;
use Kumwe\App\BusinessRecord\Query\ComparisonOperator;
use Kumwe\App\BusinessRecord\Query\CursorPosition;
use Kumwe\App\BusinessRecord\Query\RecordCursor;
use Kumwe\App\BusinessRecord\Query\RecordProjection;
use Kumwe\App\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessRecord\Query\RecordSort;
use Kumwe\App\BusinessRecord\Query\RelationFilter;
use Kumwe\App\BusinessRecord\Query\RelationQuantifier;
use Kumwe\App\BusinessRecord\Query\SetFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordQuerySpecification::class)]
#[CoversClass(RecordCursorCodec::class)]
#[CoversClass(CursorPosition::class)]
#[CoversClass(RecordCursor::class)]
#[CoversClass(RecordProjection::class)]
#[CoversClass(BooleanFilter::class)]
#[CoversClass(ComparisonFilter::class)]
#[CoversClass(RelationFilter::class)]
#[CoversClass(SetFilter::class)]
final class RecordQuerySpecificationTest extends TestCase
{
    public function testSignedCursorRoundTripsAndRejectsPayloadOrSignatureTampering(): void
    {
        $codec = new RecordCursorCodec(str_repeat('cursor-key-', 4));
        $position = new CursorPosition(
            str_repeat('a', 64),
            ['Alpha', '12.340000'],
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
        );
        $cursor = $codec->encode($position);
        $decoded = $codec->decode($cursor);

        self::assertSame($position->toArray(), $decoded->toArray());

        $wide = new CursorPosition(
            str_repeat('b', 64),
            array_fill(0, 5, str_repeat("\u{1F680}", 512)),
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
        );
        self::assertSame($wide->toArray(), $codec->decode($codec->encode($wide))->toArray());

        $token = $cursor->value();
        $replacement = str_ends_with($token, 'A') ? 'B' : 'A';
        $tampered = RecordCursor::fromString(substr($token, 0, -1) . $replacement);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('signature');
        $codec->decode($tampered);
    }

    public function testCanonicalDigestExcludesCursorButBindsEveryOtherQueryChoice(): void
    {
        $base = new RecordQuerySpecification(
            new ComparisonFilter('amount', ComparisonOperator::GreaterThanOrEqual, '1.000000'),
            sorts: [new RecordSort('name')],
            pageSize: 25,
            projection: new RecordProjection(['name', 'amount']),
        );
        $withCursor = new RecordQuerySpecification(
            $base->filter,
            sorts: $base->sorts,
            after: (new RecordCursorCodec(str_repeat('x', 32)))->encode(new CursorPosition(
                str_repeat('b', 64),
                ['Alpha'],
                '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            )),
            pageSize: 25,
            projection: $base->projection,
        );
        $differentPage = new RecordQuerySpecification(
            $base->filter,
            sorts: $base->sorts,
            pageSize: 26,
            projection: $base->projection,
        );

        self::assertSame($base->digest(), $withCursor->digest());
        self::assertNotSame($base->digest(), $differentPage->digest());
    }

    public function testPageProjectionSetDepthRelationAndFloatBoundsFailAtConstruction(): void
    {
        self::assertRejected(static fn (): RecordQuerySpecification => new RecordQuerySpecification(pageSize: 201));
        self::assertRejected(static fn (): RecordProjection => new RecordProjection(
            includes: ['a', 'b', 'c', 'd', 'e'],
        ));
        self::assertRejected(static fn (): SetFilter => new SetFilter('name', range(1, 101)));
        self::assertRejected(static fn (): SetFilter => new SetFilter('name', ['Alpha', null], true));
        self::assertRejected(static fn (): ComparisonFilter => new ComparisonFilter(
            'name',
            ComparisonOperator::Equal,
            null,
        ));
        self::assertRejected(static fn (): ComparisonFilter => new ComparisonFilter(
            'amount',
            ComparisonOperator::Equal,
            1.5,
        ));
        self::assertRejected(static fn (): RecordQuerySpecification => new RecordQuerySpecification(
            sorts: [new RecordSort('name'), new RecordSort('name')],
        ));

        $deep = new ComparisonFilter('name', ComparisonOperator::Equal, 'Alpha');
        for ($depth = 0; $depth < 8; ++$depth) {
            $deep = new BooleanFilter(BooleanOperator::All, [$deep]);
        }
        self::assertRejected(static fn (): RecordQuerySpecification => new RecordQuerySpecification($deep));

        $relations = new ComparisonFilter('name', ComparisonOperator::Equal, 'Alpha');
        for ($depth = 0; $depth < 3; ++$depth) {
            $relations = new RelationFilter('children', RelationQuantifier::Any, $relations);
        }
        self::assertRejected(static fn (): RecordQuerySpecification => new RecordQuerySpecification($relations));
    }

    /** @param callable(): object $operation */
    private static function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('The bounded business-record query must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
