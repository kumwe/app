<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonOperator;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
final class BusinessRecordReferenceIdentityIntegrationTest extends TestCase
{
    public function testPublicReferencesRoundTripQueryAndHardDeleteHistoryFailsClosedOnReuse(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(Connection::class, $database);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::entityReferenceOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
            ),
        );

        $first = $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'First historical identity'],
            NeutralBusinessFixture::idempotencyKey('ref-first-' . $suffix),
            recordId: ' reused-001 ',
        ));
        self::assertSame('REUSED-001', $first->recordId);
        $records->delete(new DeleteRecordCommand(
            $context,
            $target->handle,
            'REUSED-001',
            1,
            NeutralBusinessFixture::idempotencyKey('ref-delete-one-' . $suffix),
        ));
        $history = $records->history(new RecordHistoryQuery($context, $target->handle, 'REUSED-001'));
        self::assertSame(
            ['delete', 'create'],
            array_map(
                static fn (BusinessRecordRevisionView $revision): string => $revision->operation,
                $history->revisions,
            ),
        );
        self::assertSame([$first->recordKey], array_values(array_unique(array_map(
            static fn (BusinessRecordRevisionView $revision): string => $revision->recordKey,
            $history->revisions,
        ))));

        $second = $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Second historical identity'],
            NeutralBusinessFixture::idempotencyKey('ref-second-' . $suffix),
            recordId: 'REUSED-001',
        ));
        self::assertNotSame($first->recordKey, $second->recordKey);
        $records->delete(new DeleteRecordCommand(
            $context,
            $target->handle,
            'REUSED-001',
            1,
            NeutralBusinessFixture::idempotencyKey('ref-delete-two-' . $suffix),
        ));
        try {
            $records->history(new RecordHistoryQuery($context, $target->handle, 'REUSED-001'));
            self::fail('Reused public identities must not merge two internal record histories.');
        } catch (BusinessRecordReferenceConflict $exception) {
            self::assertSame('business_record.reference_conflict', $exception->stableCode());
        }

        $liveTarget = $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Live reference target'],
            NeutralBusinessFixture::idempotencyKey('ref-live-' . $suffix),
            recordId: 'TARGET-001',
        ));
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Reference owner', 'target_ref' => ' target-001 '],
            NeutralBusinessFixture::idempotencyKey('ref-owner-' . $suffix),
            recordId: $ownerId,
        ));

        $view = $records->read(new ReadRecordQuery($context, $owner->handle, $ownerId));
        self::assertSame('TARGET-001', $view->values['target_ref']);
        $filtered = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new ComparisonFilter(
                'target_ref',
                ComparisonOperator::Equal,
                'target-001',
            )),
        ));
        self::assertCount(1, $filtered->records);
        self::assertSame($ownerId, $filtered->records[0]->recordId);

        $targetFiltered = $records->browse(new BrowseRecordsQuery(
            $context,
            $target->handle,
            new RecordQuerySpecification(new ComparisonFilter(
                'code',
                ComparisonOperator::Equal,
                ' target-001 ',
            )),
        ));
        self::assertCount(1, $targetFiltered->records);
        self::assertSame('TARGET-001', $targetFiltered->records[0]->recordId);

        $installation = $schemas->installation($context, $owner->id);
        self::assertNotNull($installation);
        $table = $installation->blueprint->table('record');
        self::assertNotNull($table);
        $stored = $database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteIdentifier(
                $table->column('target_ref')?->physicalName ?? 'missing',
            ),
            $database->getDatabasePlatform()->quoteIdentifier($table->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier(
                $table->column('record_id')?->physicalName ?? 'missing',
            ),
        ), [$ownerId]);
        self::assertSame($liveTarget->recordKey, $stored);
        self::assertNotSame('TARGET-001', $stored);
    }
}
