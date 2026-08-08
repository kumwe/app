<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Query\BooleanFilter;
use Kumwe\CMS\BusinessRecord\Query\BooleanOperator;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonOperator;
use Kumwe\CMS\BusinessRecord\Query\RecordProjection;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessRecord\Query\RelationFilter;
use Kumwe\CMS\BusinessRecord\Query\RelationQuantifier;
use Kumwe\CMS\BusinessRecord\Query\SetFilter;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
final class BusinessRecordRelationshipIntegrationTest extends TestCase
{
    public function testEveryCardinalityOrderedLinesIncludesRestrictionAndCascadeUseGeneratedTables(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $definitions = $container->get(BusinessDefinitionService::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(Connection::class, $database);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $targetDocument = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        $targetDocument['fields'][] = [
            'handle' => 'classification',
            'label' => 'Classification',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 40,
            'filterable' => true,
        ];
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            $targetDocument,
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );

        $targetIds = [
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
        ];
        foreach ($targetIds as $offset => $targetId) {
            $values = ['label' => 'Target ' . ($offset + 1)];
            if ($offset !== 1) {
                $values['classification'] = $offset === 0 ? 'match' : 'other';
            }
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                $values,
                NeutralBusinessFixture::idempotencyKey('target' . $offset . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Relationship owner'],
            NeutralBusinessFixture::idempotencyKey('owner-' . $suffix),
            recordId: $ownerId,
        ));

        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            1,
            'primary_target',
            $targetIds[0],
            NeutralBusinessFixture::idempotencyKey('primary-' . $suffix),
        ))->version;
        self::assertSame(2, $version);
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'category',
            $targetIds[1],
            NeutralBusinessFixture::idempotencyKey('category-' . $suffix),
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'members',
            $targetIds[2],
            NeutralBusinessFixture::idempotencyKey('member-' . $suffix),
            0,
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $targetIds[0],
            NeutralBusinessFixture::idempotencyKey('tag0-' . $suffix),
            0,
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $targetIds[1],
            NeutralBusinessFixture::idempotencyKey('tag1-' . $suffix),
            1,
        ))->version;
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            [$targetIds[1], $targetIds[0]],
            NeutralBusinessFixture::idempotencyKey('tagorder-' . $suffix),
        ))->version;

        $lineIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($lineIds as $offset => $lineId) {
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'lines',
                $lineId,
                NeutralBusinessFixture::idempotencyKey('line' . $offset . '-' . $suffix),
                $offset,
                targetValues: [
                    'description' => 'Line ' . ($offset + 1),
                    'units' => ($offset + 1) . '.000',
                ],
            ))->version;
        }
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'lines',
            [$lineIds[1], $lineIds[0]],
            NeutralBusinessFixture::idempotencyKey('lineorder-' . $suffix),
        ))->version;
        self::assertSame(10, $version);

        $fieldLineIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($fieldLineIds as $offset => $lineId) {
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'field_lines',
                $lineId,
                NeutralBusinessFixture::idempotencyKey('fieldline' . $offset . '-' . $suffix),
                $offset,
                targetValues: [
                    'description' => 'Field line ' . ($offset + 1),
                    'units' => ($offset + 3) . '.000',
                ],
            ))->version;
        }
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'field_lines',
            [$fieldLineIds[1], $fieldLineIds[0]],
            NeutralBusinessFixture::idempotencyKey('fieldorder-' . $suffix),
        ))->version;
        self::assertSame(13, $version);

        try {
            $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version - 1,
                'tags',
                $targetIds[2],
                NeutralBusinessFixture::idempotencyKey('stalerelate-' . $suffix),
            ));
            self::fail('Every relationship mutation must enforce the source expected version.');
        } catch (BusinessRecordVersionConflict) {
            self::assertTrue(true);
        }

        $relations = self::browseIncludes(
            $records,
            $context,
            $owner->handle,
            ['primary_target', 'category', 'members', 'tags'],
        );
        self::assertSame($targetIds[0], $relations['primary_target'][0]->recordId);
        self::assertSame($targetIds[1], $relations['category'][0]->recordId);
        self::assertSame($targetIds[2], $relations['members'][0]->recordId);
        self::assertSame([$targetIds[1], $targetIds[0]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $relations['tags'],
        ));
        self::assertSame([0, 1], array_map(
            static fn (BusinessRecordRelationView $related): ?int => $related->position,
            $relations['tags'],
        ));

        $lineRelations = self::browseIncludes($records, $context, $owner->handle, ['lines']);
        self::assertSame([$lineIds[1], $lineIds[0]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $lineRelations['lines'],
        ));
        self::assertSame(['Line 2', 'Line 1'], array_map(
            static fn (BusinessRecordRelationView $related): string => (string) $related->values['description'],
            $lineRelations['lines'],
        ));
        self::assertInstanceOf(ExactDecimal::class, $lineRelations['lines'][0]->values['units']);

        $fieldLineRelations = self::browseIncludes($records, $context, $owner->handle, ['field_lines']);
        self::assertSame([$fieldLineIds[1], $fieldLineIds[0]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $fieldLineRelations['field_lines'],
        ));
        self::assertSame(['Field line 2', 'Field line 1'], array_map(
            static fn (BusinessRecordRelationView $related): string => (string) $related->values['description'],
            $fieldLineRelations['field_lines'],
        ));

        $relatedByTag = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new RelationFilter(
                'tags',
                RelationQuantifier::Any,
                new ComparisonFilter('label', ComparisonOperator::Equal, 'Target 2'),
            )),
        ));
        self::assertCount(1, $relatedByTag->records);
        self::assertSame($ownerId, $relatedByTag->records[0]->recordId);

        $allTagsMatch = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new RelationFilter(
                'tags',
                RelationQuantifier::All,
                new ComparisonFilter('classification', ComparisonOperator::Equal, 'match'),
            )),
        ));
        self::assertCount(0, $allTagsMatch->records, 'A nullable related value is a non-match for ALL.');
        $notMatchingTargets = $records->browse(new BrowseRecordsQuery(
            $context,
            $target->handle,
            new RecordQuerySpecification(new BooleanFilter(
                BooleanOperator::Not,
                [new ComparisonFilter('classification', ComparisonOperator::Equal, 'match')],
            )),
        ));
        self::assertCount(2, $notMatchingTargets->records, 'Boolean NOT treats SQL NULL as a non-match.');
        $notEqualTargets = $records->browse(new BrowseRecordsQuery(
            $context,
            $target->handle,
            new RecordQuerySpecification(new ComparisonFilter(
                'classification',
                ComparisonOperator::NotEqual,
                'match',
            )),
        ));
        self::assertCount(2, $notEqualTargets->records, 'Not-equal treats SQL NULL as distinct.');
        $notInTargets = $records->browse(new BrowseRecordsQuery(
            $context,
            $target->handle,
            new RecordQuerySpecification(new SetFilter('classification', ['match'], true)),
        ));
        self::assertCount(2, $notInTargets->records, 'Negated sets treat SQL NULL as outside the set.');

        $pendingTargetDocument = $targetDocument;
        $pendingTargetDocument['fields'][] = [
            'handle' => 'pending_marker',
            'label' => 'Pending marker',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 40,
            'filterable' => true,
        ];
        $pendingDraft = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray($pendingTargetDocument),
            0,
        );
        $pendingTarget = $definitions->publish(
            $context,
            $target->id,
            $pendingDraft->revision,
        )->definition;
        self::assertSame(2, $pendingTarget->definitionVersion);
        $pendingPlan = $schemas->createPlan($context, $target->id);
        self::assertSame(SchemaPlanStatus::PendingApproval, $pendingPlan->status);

        $stillInstalled = self::browseIncludes($records, $context, $owner->handle, ['tags']);
        self::assertSame([$targetIds[1], $targetIds[0]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $stillInstalled['tags'],
        ));
        $stillFiltered = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new RelationFilter(
                'tags',
                RelationQuantifier::Any,
                new ComparisonFilter('label', ComparisonOperator::Equal, 'Target 2'),
            )),
        ));
        self::assertCount(1, $stillFiltered->records);
        self::assertSame($ownerId, $stillFiltered->records[0]->recordId);

        $relatedByLine = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new RelationFilter(
                'lines',
                RelationQuantifier::Any,
                new ComparisonFilter('description', ComparisonOperator::Equal, 'Line 2'),
            )),
        ));
        self::assertCount(1, $relatedByLine->records);

        $relatedByFieldLine = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(new RelationFilter(
                'field_lines',
                RelationQuantifier::Any,
                new ComparisonFilter('description', ComparisonOperator::Equal, 'Field line 2'),
            )),
        ));
        self::assertCount(1, $relatedByFieldLine->records);

        $version = $records->unrelate(new UnrelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'field_lines',
            $fieldLineIds[0],
            NeutralBusinessFixture::idempotencyKey('unfieldline-' . $suffix),
        ))->version;
        self::assertSame(14, $version);
        $remainingFieldLines = self::browseIncludes($records, $context, $owner->handle, ['field_lines']);
        self::assertSame([$fieldLineIds[1]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $remainingFieldLines['field_lines'],
        ));

        try {
            $records->delete(new DeleteRecordCommand(
                $context,
                $target->handle,
                $targetIds[1],
                1,
                NeutralBusinessFixture::idempotencyKey('restricted-' . $suffix),
            ));
            self::fail('A referenced target with restrict semantics must not be hard deleted.');
        } catch (BusinessRecordReferenceConflict) {
            self::assertTrue(true);
        }

        $version = $records->unrelate(new UnrelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $targetIds[0],
            NeutralBusinessFixture::idempotencyKey('untag-' . $suffix),
        ))->version;
        self::assertSame(15, $version);
        $remainingTags = self::browseIncludes($records, $context, $owner->handle, ['tags']);
        self::assertSame([$targetIds[1]], array_map(
            static fn (BusinessRecordRelationView $related): string => $related->recordId,
            $remainingTags['tags'],
        ));

        $historyBeforeSetNull = $records->history(new RecordHistoryQuery(
            $context,
            $owner->handle,
            $ownerId,
        ));
        $setNullDelete = $records->delete(new DeleteRecordCommand(
            $context,
            $target->handle,
            $targetIds[0],
            1,
            NeutralBusinessFixture::idempotencyKey('setnull-' . $suffix),
        ));
        self::assertTrue($setNullDelete->deleted);
        $ownerAfterSetNull = $records->browse(new BrowseRecordsQuery(
            $context,
            $owner->handle,
            new RecordQuerySpecification(
                pageSize: 1,
                projection: new RecordProjection(['title'], ['primary_target']),
            ),
        ));
        self::assertCount(1, $ownerAfterSetNull->records);
        self::assertSame([], $ownerAfterSetNull->records[0]->includes['primary_target']);
        self::assertSame($version + 1, $ownerAfterSetNull->records[0]->version);
        $version = $ownerAfterSetNull->records[0]->version;
        $historyAfterSetNull = $records->history(new RecordHistoryQuery(
            $context,
            $owner->handle,
            $ownerId,
        ));
        self::assertCount(count($historyBeforeSetNull->revisions) + 1, $historyAfterSetNull->revisions);
        self::assertSame('unrelate.primary_target', $historyAfterSetNull->revisions[0]->operation);

        $deleted = $records->delete(new DeleteRecordCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            NeutralBusinessFixture::idempotencyKey('ownerdelete-' . $suffix),
        ));
        self::assertTrue($deleted->deleted);
        $installation = $schemas->installation($context, $owner->id);
        self::assertNotNull($installation);
        $lineTable = $installation->blueprint->table('line:lines');
        self::assertNotNull($lineTable);
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $database->getDatabasePlatform()->quoteIdentifier($lineTable->physicalName),
        )));
        $fieldLineTable = $installation->blueprint->table('line:field_lines');
        self::assertNotNull($fieldLineTable);
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $database->getDatabasePlatform()->quoteIdentifier($fieldLineTable->physicalName),
        )));
    }

    public function testStableBackupGraphSeederIsIdempotentAndPersistsJunctionLinesAndRevisions(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(Connection::class, $database);

        $first = NeutralBusinessFixture::seedBackupGraph($container, $context);
        $replayed = NeutralBusinessFixture::seedBackupGraph($container, $context);
        self::assertSame($first, $replayed);
        self::assertSame(7, $first['owner_version']);
        self::assertSame(NeutralBusinessFixture::OWNER_HANDLE, $first['owner_handle']);
        self::assertSame(NeutralBusinessFixture::OWNER_RECORD_ID, $first['owner_record_id']);

        $relations = self::browseIncludes(
            $records,
            $context,
            NeutralBusinessFixture::OWNER_HANDLE,
            ['tags', 'lines'],
        );
        self::assertSame(
            [NeutralBusinessFixture::SECOND_TARGET_RECORD_ID, NeutralBusinessFixture::TARGET_RECORD_ID],
            array_map(
                static fn (BusinessRecordRelationView $related): string => $related->recordId,
                $relations['tags'],
            ),
        );
        self::assertSame(
            [NeutralBusinessFixture::SECOND_LINE_RECORD_ID, NeutralBusinessFixture::LINE_RECORD_ID],
            array_map(
                static fn (BusinessRecordRelationView $related): string => $related->recordId,
                $relations['lines'],
            ),
        );
        self::assertSame(
            ['Backup line two', 'Backup line one'],
            array_map(
                static fn (BusinessRecordRelationView $related): string => (string) $related->values['description'],
                $relations['lines'],
            ),
        );

        $history = $records->history(new RecordHistoryQuery(
            $context,
            NeutralBusinessFixture::OWNER_HANDLE,
            NeutralBusinessFixture::OWNER_RECORD_ID,
        ));
        self::assertCount(7, $history->revisions);
        foreach ($history->revisions as $revision) {
            self::assertInstanceOf(BusinessRecordRevisionView::class, $revision);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision->integrityChecksum);
        }

        $installation = $schemas->installation($context, NeutralBusinessFixture::OWNER_DEFINITION_ID);
        self::assertNotNull($installation);
        $junction = $installation->blueprint->table('relation:tags');
        $lineTable = $installation->blueprint->table('line:lines');
        self::assertNotNull($junction);
        self::assertNotNull($lineTable);
        self::assertSame(2, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $database->getDatabasePlatform()->quoteIdentifier($junction->physicalName),
        )));
        self::assertSame(2, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $database->getDatabasePlatform()->quoteIdentifier($lineTable->physicalName),
        )));
    }

    public function testHardDeleteCannotSetNullAnInboundDisabledRecordImplicitly(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        $transactions = $container->get(TransactionManager::class);
        $clock = $container->get(ClockInterface::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $installations);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(Connection::class, $database);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $targetId = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Disabled inbound target'],
            NeutralBusinessFixture::idempotencyKey('disabled-target-' . $suffix),
            recordId: $targetId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Disabled inbound owner'],
            NeutralBusinessFixture::idempotencyKey('disabled-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            1,
            'primary_target',
            $targetId,
            NeutralBusinessFixture::idempotencyKey('disabled-relate-' . $suffix),
        ));

        $transactions->transactional(function () use ($installations, $clock, $owner): void {
            $installation = $installations->find($owner->id);
            self::assertNotNull($installation);
            $installations->save($installation->disable($clock->now()));
        });

        try {
            $records->delete(new DeleteRecordCommand(
                $context,
                $target->handle,
                $targetId,
                1,
                NeutralBusinessFixture::idempotencyKey('disabled-delete-' . $suffix),
            ));
            self::fail('A hard delete must not implicitly mutate a disabled inbound record.');
        } catch (BusinessRecordReferenceConflict) {
            self::assertTrue(true);
        }

        $installation = $schemas->installation($context, $owner->id);
        self::assertNotNull($installation);
        $table = $installation->blueprint->table('record');
        self::assertNotNull($table);
        $recordKey = $table->column('record_id');
        $version = $table->column('version');
        $reference = $table->column('relation:primary_target.target_id');
        self::assertNotNull($recordKey);
        self::assertNotNull($version);
        self::assertNotNull($reference);
        $row = $database->fetchAssociative(sprintf(
            'SELECT %s, %s FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteIdentifier($version->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($reference->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($table->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($recordKey->physicalName),
        ), [$ownerId]);
        self::assertIsArray($row);
        self::assertSame(2, (int) $row[$version->physicalName]);
        self::assertSame($targetId, $row[$reference->physicalName]);
        self::assertCount(2, $records->history(new RecordHistoryQuery(
            $context,
            $owner->handle,
            $ownerId,
        ))->revisions);
    }

    /**
     * @param list<string> $includes
     * @return array<string, list<BusinessRecordRelationView>>
     */
    private static function browseIncludes(
        BusinessRecordService $records,
        \Kumwe\CMS\Application\Authorization\ExecutionContext $context,
        string $definition,
        array $includes,
    ): array {
        $result = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            new RecordQuerySpecification(
                pageSize: 1,
                projection: new RecordProjection(['title'], $includes),
            ),
        ));
        self::assertCount(1, $result->records);

        return $result->records[0]->includes;
    }
}
