<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\App\BusinessSchema\Domain\SchemaRisk;
use Kumwe\App\BusinessSchema\Domain\SchemaStepStatus;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessDefinitionService::class)]
#[CoversClass(BusinessSchemaService::class)]
#[CoversClass(BusinessRecordService::class)]
final class BusinessRecordEvolutionIntegrationTest extends TestCase
{
    public function testTypedV2EvolutionIsPlannedApprovedExecutedAndUsedByRecords(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $records = $container->get(BusinessRecordService::class);
        $environment = $container->get(BusinessSchemaEnvironment::class);
        $clock = $container->get(ClockInterface::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaEnvironment::class, $environment);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(Connection::class, $database);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definitionId = Uuid::uuid7()->toString();
        $v1 = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        self::assertSame(1, $v1->definitionVersion);
        $v1RecordId = Uuid::uuid7()->toString();
        $v1Created = $records->create(new CreateRecordCommand(
            $context,
            $v1->handle,
            NeutralBusinessFixture::recordValues('Version one backfill'),
            NeutralBusinessFixture::idempotencyKey('v1-create-' . $suffix),
            recordId: $v1RecordId,
        ));
        $v1View = $records->read(new ReadRecordQuery($context, $v1->handle, $v1RecordId));
        self::assertSame(1, $v1View->definitionVersion);
        self::assertNull($v1View->values['evolution_code']);
        self::assertSame('draft', $v1View->values['status']);

        $draft = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray(NeutralBusinessFixture::evolutionDocument($suffix, $definitionId)),
            0,
        );
        $v2 = $definitions->publish($context, $definitionId, $draft->revision, true)->definition;
        self::assertSame(2, $v2->definitionVersion);

        $plan = $schemas->createPlan($context, $definitionId);
        self::assertSame(1, $plan->fromDefinitionVersion);
        self::assertSame(2, $plan->toDefinitionVersion);
        self::assertSame(SchemaRisk::RebuildOrLocking, $plan->risk);
        self::assertSame(SchemaPlanStatus::PendingApproval, $plan->status);
        $operations = $plan->operations();
        $kinds = array_map(
            static fn (SchemaOperation $operation): SchemaOperationKind => $operation->kind,
            $operations,
        );
        foreach (
            [
            SchemaOperationKind::RenameColumn,
            SchemaOperationKind::Backfill,
            SchemaOperationKind::AlterColumn,
            SchemaOperationKind::AddIndex,
            SchemaOperationKind::RepinRecords,
            ] as $requiredKind
        ) {
            self::assertContains($requiredKind, $kinds);
        }

        $rename = self::operation($operations, SchemaOperationKind::RenameColumn, 'lifecycle_status');
        self::assertSame('status', $rename->before['logical_name'] ?? null);
        self::assertSame('lifecycle_status', $rename->after['logical_name'] ?? null);
        $backfill = self::operation($operations, SchemaOperationKind::Backfill, 'evolution_code');
        self::assertTrue($backfill->requiresBackfill);
        self::assertSame(
            ['op' => 'field', 'type' => 'string', 'field' => 'name'],
            $backfill->after['expression'] ?? null,
        );
        self::assertArrayHasKey('name', $backfill->after['dependencies'] ?? []);
        $alter = self::operation($operations, SchemaOperationKind::AlterColumn, 'evolution_code');
        self::assertTrue($alter->before['nullable'] ?? false);
        self::assertFalse($alter->after['nullable'] ?? true);
        self::operation($operations, SchemaOperationKind::AddIndex, 'field.service_date');
        $repin = self::operation($operations, SchemaOperationKind::RepinRecords, 'definition_version');
        self::assertSame(['definition_version' => 2], $repin->after);

        $sourceChecksum = $plan->fromSchemaChecksum;
        self::assertNotNull($sourceChecksum);
        $now = $clock->now();
        $evidence = $schemas->recordRecoveryEvidence($context, new SchemaRecoveryEvidence(
            Uuid::uuid7()->toString(),
            $context->site()->identifier(),
            $environment->databaseDriver(),
            $environment->databaseServerVersion(),
            $environment->applicationRelease(),
            $sourceChecksum,
            hash('sha256', 'neutral-v2-evolution-' . $suffix),
            true,
            $now,
            $now,
            $context->actorId(),
            'neutral-v2-evolution-' . $suffix,
            [
                'clean_target_restore' => true,
                'blueprint_checksum_verified' => true,
                'typed_command_verified' => true,
                'record_revision_audit_checksums_verified' => true,
                'client_version' => 'integration-fixture-v2',
                'restore_target_reference' => 'clean-target-' . $suffix,
            ],
        ));
        $approved = $schemas->approve(
            $context,
            $plan->id,
            $plan->checksum(),
            $plan->checksum(),
            $evidence->id,
        );
        self::assertSame(SchemaPlanStatus::Approved, $approved->status);

        $outcome = $schemas->execute($context, $approved->id);
        $completed = $schemas->plan($context, $approved->id);
        $installation = $schemas->installation($context, $definitionId);
        self::assertSame(SchemaPlanStatus::Completed, $completed->status);
        self::assertNotNull($installation);
        self::assertSame(SchemaInstallationStatus::Active, $installation->status);
        self::assertSame(2, $installation->definitionVersion);
        self::assertSame($installation->schemaChecksum, $outcome->schemaChecksum);
        $processedRows = [];
        foreach ($schemas->steps($context, $approved->id) as $step) {
            self::assertSame(SchemaStepStatus::Completed, $step->state);
            if (
                in_array(
                    $step->operationKind,
                    [SchemaOperationKind::Backfill, SchemaOperationKind::RepinRecords],
                    true,
                )
            ) {
                $processedRows[$step->operationKind->value] = $step->outcome['processed_rows'] ?? null;
            }
        }
        self::assertSame(1, $processedRows[SchemaOperationKind::Backfill->value] ?? null);
        self::assertSame(1, $processedRows[SchemaOperationKind::RepinRecords->value] ?? null);
        $recordTable = $installation->blueprint->table('record');
        self::assertNotNull($recordTable);
        self::assertNull($recordTable->column('status'));
        self::assertNotNull($recordTable->column('lifecycle_status'));
        self::assertFalse($recordTable->column('evolution_code')?->nullable ?? true);
        self::assertNotEmpty(array_filter(
            $recordTable->indexes(),
            static fn (object $index): bool => $index->logicalName === 'field.service_date',
        ));

        $physicalRow = $database->fetchAssociative(sprintf(
            'SELECT %s AS evolution_code, %s AS lifecycle_status, %s AS definition_version '
                . 'FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('evolution_code')?->physicalName ?? 'missing',
            ),
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('lifecycle_status')?->physicalName ?? 'missing',
            ),
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('definition_version')?->physicalName ?? 'missing',
            ),
            $database->getDatabasePlatform()->quoteIdentifier($recordTable->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('record_id')?->physicalName ?? 'missing',
            ),
        ), [$v1Created->recordKey]);
        self::assertNotFalse($physicalRow);
        self::assertSame('Version one backfill', $physicalRow['evolution_code'] ?? null);
        self::assertSame('draft', $physicalRow['lifecycle_status'] ?? null);
        self::assertSame(2, (int) ($physicalRow['definition_version'] ?? 0));

        $repinnedView = $records->read(new ReadRecordQuery($context, $v2->handle, $v1RecordId));
        self::assertSame(2, $repinnedView->definitionVersion);
        self::assertSame('Version one backfill', $repinnedView->values['evolution_code']);
        self::assertSame('draft', $repinnedView->values['lifecycle_status']);
        self::assertArrayNotHasKey('status', $repinnedView->values);

        $recordId = Uuid::uuid7()->toString();
        $created = $records->create(new CreateRecordCommand(
            $context,
            $v2->handle,
            NeutralBusinessFixture::evolutionRecordValues('Version two', 'VERSION-TWO'),
            NeutralBusinessFixture::idempotencyKey('v2-create-' . $suffix),
            recordId: $recordId,
        ));
        self::assertSame(2, $created->definitionVersion);
        $view = $records->read(new ReadRecordQuery($context, $v2->handle, $recordId));
        self::assertSame('draft', $view->values['lifecycle_status']);
        self::assertSame('VERSION-TWO', $view->values['evolution_code']);
        self::assertArrayNotHasKey('status', $view->values);
        self::assertSame('13:14:15.123456', $view->values['local_time']->format('H:i:s.u'));
        self::assertSame(
            '2026-08-08T11:14:15.123456+00:00',
            $view->values['recorded_at']->format('Y-m-d\TH:i:s.uP'),
        );
        self::assertSame(
            '2026-08-08T11:14:15.123456Z',
            $view->values['scheduled_for']->instant->format('Y-m-d\TH:i:s.u\Z'),
        );

        $browse = $records->browse(new BrowseRecordsQuery(
            $context,
            $v2->handle,
            new RecordQuerySpecification(new ComparisonFilter(
                'lifecycle_status',
                ComparisonOperator::Equal,
                'draft',
            )),
        ));
        self::assertCount(2, $browse->records);
        self::assertContains($recordId, array_map(
            static fn (BusinessRecordView $record): string => $record->recordId,
            $browse->records,
        ));
    }

    /**
     * @param list<SchemaOperation> $operations
     */
    private static function operation(
        array $operations,
        SchemaOperationKind $kind,
        string $subject,
    ): SchemaOperation {
        foreach ($operations as $operation) {
            if ($operation->kind === $kind && $operation->subject === $subject) {
                return $operation;
            }
        }

        self::fail(sprintf('Missing %s schema operation for %s.', $kind->value, $subject));
    }
}
