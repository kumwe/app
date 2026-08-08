<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Query\AggregateFunction;
use Kumwe\CMS\BusinessRecord\Query\RecordAggregate;
use Kumwe\CMS\BusinessRecord\Query\RecordCursor;
use Kumwe\CMS\BusinessRecord\Query\RecordProjection;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessRecord\Query\RecordSort;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
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
        $document['fields'][] = [
            'handle' => 'bucket',
            'label' => 'Bucket',
            'type' => 'core.text',
            'required' => true,
            'nullable' => false,
            'length' => 32,
            'filterable' => true,
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
                ['label' => sprintf('Large row %03d', $index), 'bucket' => 'shared'],
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
