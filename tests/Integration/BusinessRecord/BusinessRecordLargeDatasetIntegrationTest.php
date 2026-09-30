<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\Record\Query\AggregateFunction;
use Kumwe\Record\Query\RecordAggregate;
use Kumwe\Record\Query\RecordCursor;
use Kumwe\Record\Query\RecordProjection;
use Kumwe\Record\Query\RecordQuerySpecification;
use Kumwe\Record\Query\RecordSort;
use Kumwe\Record\Query\SortDirection;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
#[CoversClass(DoctrineBusinessRecordQueryCompiler::class)]
final class BusinessRecordLargeDatasetIntegrationTest extends TestCase
{
    private const RECORD_COUNT = 225;

    private const PAGE_SIZE = 37;

    public function testLargeDatasetUsesBoundedStableKeysetPaginationAndExactAggregates(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $document = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        self::assertIsArray($document['fields']);
        self::assertIsArray($document['fields'][1]);
        $document['fields'][1]['reportable'] = true;
        $document['fields'][] = [
            'handle' => 'bucket',
            'label' => 'Bucket',
            'type' => 'core.text',
            'required' => true,
            'nullable' => false,
            'length' => 32,
            'filterable' => true,
            'sortable' => true,
            'reportable' => true,
        ];
        $document['fields'][] = [
            'handle' => 'nullable_bucket',
            'label' => 'Nullable bucket',
            'type' => 'core.text',
            'nullable' => true,
            'length' => 32,
            'sortable' => true,
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);

        $expectedRecordIds = [];
        for ($index = self::RECORD_COUNT - 1; $index >= 0; --$index) {
            $recordId = self::recordId($index);
            $expectedRecordIds[$index] = $recordId;
            $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                [
                    'label' => sprintf('Large row %03d', $index),
                    'bucket' => 'shared',
                    'nullable_bucket' => $index % 2 === 0 ? 'shared' : null,
                ],
                NeutralBusinessFixture::idempotencyKey(sprintf('load-%s-%03d', substr($suffix, 0, 8), $index)),
                recordId: $recordId,
            ));
        }
        ksort($expectedRecordIds);
        $expectedRecordIds = array_values($expectedRecordIds);

        $projection = new RecordProjection(
            ['label', 'bucket'],
            aggregates: [new RecordAggregate('row_count', AggregateFunction::Count)],
        );
        $sorts = [new RecordSort('bucket')];

        $maximumPage = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            new RecordQuerySpecification(sorts: $sorts, pageSize: 200, projection: $projection),
        ));
        self::assertCount(200, $maximumPage->records);
        self::assertNotNull($maximumPage->nextCursor);
        self::assertSame(self::RECORD_COUNT, (int) $maximumPage->aggregates['row_count']);

        $maximumPageTail = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            new RecordQuerySpecification(
                sorts: $sorts,
                after: $maximumPage->nextCursor,
                pageSize: 200,
                projection: $projection,
            ),
        ));
        self::assertCount(self::RECORD_COUNT - 200, $maximumPageTail->records);
        self::assertNull($maximumPageTail->nextCursor);
        self::assertSame(self::RECORD_COUNT, (int) $maximumPageTail->aggregates['row_count']);

        try {
            new RecordQuerySpecification(sorts: $sorts, pageSize: 201, projection: $projection);
            self::fail('A query must reject a page size above the documented bound.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'A business-record query page or sort count exceeds its bound.',
                $exception->getMessage(),
            );
        }

        $specification = new RecordQuerySpecification(
            sorts: $sorts,
            pageSize: self::PAGE_SIZE,
            projection: $projection,
        );
        $firstPage = $records->browse(new BrowseRecordsQuery($context, $definition->handle, $specification));
        $repeatedFirstPage = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            $specification,
        ));
        self::assertSame(self::recordIds($firstPage), self::recordIds($repeatedFirstPage));
        self::assertSame($firstPage->nextCursor?->value(), $repeatedFirstPage->nextCursor?->value());

        $actualRecordIds = [];
        $seenCursors = [];
        $pageCount = 0;
        $page = $firstPage;
        while (true) {
            ++$pageCount;
            self::assertLessThanOrEqual(self::PAGE_SIZE, count($page->records));
            self::assertSame(self::RECORD_COUNT, (int) $page->aggregates['row_count']);
            array_push($actualRecordIds, ...self::recordIds($page));

            if ($page->nextCursor === null) {
                break;
            }
            $token = $page->nextCursor->value();
            self::assertArrayNotHasKey($token, $seenCursors, 'A keyset cursor must always advance.');
            $seenCursors[$token] = true;
            $page = $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(
                    sorts: $sorts,
                    after: RecordCursor::fromString($token),
                    pageSize: self::PAGE_SIZE,
                    projection: $projection,
                ),
            ));
        }

        self::assertSame(7, $pageCount);
        self::assertCount(self::RECORD_COUNT, array_unique($actualRecordIds));
        self::assertSame($expectedRecordIds, $actualRecordIds);

        // Native and explicit null placement must page identically, including descending identity ties.
        $even = array_values(array_filter($expectedRecordIds, static fn (string $id): bool =>
            hexdec(substr($id, -12)) % 2 === 1));
        $odd = array_values(array_diff($expectedRecordIds, $even));
        foreach (
            [
                [[new RecordSort('bucket', SortDirection::Descending)], array_reverse($expectedRecordIds)],
                [[new RecordSort('nullable_bucket', SortDirection::Ascending, false)], [...$odd, ...$even]],
                [[new RecordSort('nullable_bucket', SortDirection::Ascending, true)], [...$even, ...$odd]],
                [[new RecordSort('bucket'), new RecordSort('nullable_bucket')], [...$even, ...$odd]],
                [[new RecordSort('nullable_bucket', SortDirection::Descending, false)],
                    [...array_reverse($odd), ...array_reverse($even)]],
                [[new RecordSort('nullable_bucket', SortDirection::Descending, true)],
                    [...array_reverse($even), ...array_reverse($odd)]],
                [[], null],
            ] as [$ordering, $expected]
        ) {
            $cursor = null;
            $actual = [];
            do {
                $page = $records->browse(new BrowseRecordsQuery(
                    $context,
                    $definition->handle,
                    new RecordQuerySpecification(sorts: $ordering, after: $cursor, pageSize: self::PAGE_SIZE),
                ));
                array_push($actual, ...self::recordIds($page));
                self::assertLessThanOrEqual(self::RECORD_COUNT, count($actual), 'A cursor must always advance.');
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            self::assertCount(self::RECORD_COUNT, array_unique($actual));
            if ($expected !== null) {
                self::assertSame($expected, $actual);
            }
        }
    }

    private static function recordId(int $index): string
    {
        return sprintf('0191574f-f0b8-7bf3-a9ab-%012x', $index + 1);
    }

    /** @return list<string> */
    private static function recordIds(RecordBrowseResult $page): array
    {
        return array_map(
            static fn (BusinessRecordView $record): string => $record->recordId,
            $page->records,
        );
    }
}
