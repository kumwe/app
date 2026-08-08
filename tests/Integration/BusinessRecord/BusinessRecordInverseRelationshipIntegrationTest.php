<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use Kumwe\CMS\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonOperator;
use Kumwe\CMS\BusinessRecord\Query\RecordProjection;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessRecord\Query\RelationFilter;
use Kumwe\CMS\BusinessRecord\Query\RelationQuantifier;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
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
final class BusinessRecordInverseRelationshipIntegrationTest extends TestCase
{
    public function testInverseFiltersAndHistorySurviveDisabledAndPreservedOwnerState(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $synchronizer = $container->get(PackageDefinitionSynchronizer::class);
        $transactions = $container->get(TransactionManager::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        $records = $container->get(BusinessRecordService::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $synchronizer);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $installations);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $extensionIdentifier = 'testing/record_' . $suffix;
        $documents = NeutralBusinessFixture::inverseRelationshipDocuments(
            $suffix,
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            $extensionIdentifier,
        );
        $left = EntityTypeDefinition::fromArray($documents['left']);
        $right = EntityTypeDefinition::fromArray($documents['right']);
        $transactions->transactional(static function () use (
            $synchronizer,
            $extensionIdentifier,
            $context,
            $left,
            $right,
        ): void {
            $synchronizer->synchronize(
                $extensionIdentifier,
                '1.0.0',
                $context->site(),
                [],
                [$left, $right],
                true,
                $context->actorId(),
            );
        });
        $plans = array_values(array_filter(
            $schemas->plans($context),
            static fn (SchemaPlan $plan): bool => in_array(
                $plan->definitionId,
                [$left->id, $right->id],
                true,
            ),
        ));
        self::assertCount(2, $plans);
        foreach ($plans as $plan) {
            if ($plan->status === SchemaPlanStatus::PendingApproval) {
                $schemas->approve(
                    $context,
                    $plan->id,
                    $plan->checksum(),
                    $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null,
                    null,
                );
            }
        }
        foreach ($plans as $plan) {
            $current = $schemas->plan($context, $plan->id);
            if ($current->status === SchemaPlanStatus::Approved) {
                $schemas->execute($context, $current->id);
            }
            self::assertSame(SchemaPlanStatus::Completed, $schemas->plan($context, $plan->id)->status);
        }

        $leftId = Uuid::uuid7()->toString();
        $rightId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $left->handle,
            ['label' => 'Left record'],
            NeutralBusinessFixture::idempotencyKey('inverse-left-' . $suffix),
            recordId: $leftId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $right->handle,
            ['label' => 'Right record'],
            NeutralBusinessFixture::idempotencyKey('inverse-right-' . $suffix),
            recordId: $rightId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $left->handle,
            $leftId,
            1,
            'rights',
            $rightId,
            NeutralBusinessFixture::idempotencyKey('inverse-relate-' . $suffix),
            0,
        ));

        $leftInstallation = $schemas->installation($context, $left->id);
        $rightInstallation = $schemas->installation($context, $right->id);
        self::assertNotNull($leftInstallation);
        self::assertNotNull($rightInstallation);
        $leftOwnsStorage = $leftInstallation->blueprint->table('relation:rights') !== null;
        $rightOwnsStorage = $rightInstallation->blueprint->table('relation:lefts') !== null;
        self::assertNotSame($leftOwnsStorage, $rightOwnsStorage);

        $queryHandle = $leftOwnsStorage ? $right->handle : $left->handle;
        $queryId = $leftOwnsStorage ? $rightId : $leftId;
        $queryRelationship = $leftOwnsStorage ? 'lefts' : 'rights';
        $targetLabel = $leftOwnsStorage ? 'Left record' : 'Right record';
        $targetId = $leftOwnsStorage ? $leftId : $rightId;
        $inverseFiltered = $records->browse(new BrowseRecordsQuery(
            $context,
            $queryHandle,
            new RecordQuerySpecification(
                new RelationFilter(
                    $queryRelationship,
                    RelationQuantifier::Any,
                    new ComparisonFilter('label', ComparisonOperator::Equal, $targetLabel),
                ),
                projection: new RecordProjection(['label'], [$queryRelationship]),
            ),
        ));
        self::assertCount(1, $inverseFiltered->records);
        self::assertSame($queryId, $inverseFiltered->records[0]->recordId);
        self::assertSame($targetId, $inverseFiltered->records[0]->includes[$queryRelationship][0]->recordId);
        self::assertContainsOnlyInstancesOf(
            BusinessRecordRelationView::class,
            $inverseFiltered->records[0]->includes[$queryRelationship],
        );

        $transactions->transactional(static function () use (
            $synchronizer,
            $extensionIdentifier,
            $context,
        ): void {
            $synchronizer->setActive($extensionIdentifier, false, $context->actorId());
        });
        self::assertSame(
            SchemaInstallationStatus::Disabled,
            $schemas->installation($context, $left->id)?->status,
        );
        self::assertSame(
            SchemaInstallationStatus::Disabled,
            $schemas->installation($context, $right->id)?->status,
        );
        try {
            $records->read(new ReadRecordQuery($context, $left->handle, $leftId));
            self::fail('An inactive definition owner must block executable record reads.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::assertTrue(true);
        }

        $disabledHistory = $records->history(new RecordHistoryQuery($context, $left->handle, $leftId));
        self::assertNotEmpty($disabledHistory->revisions);
        self::assertSame($leftId, $disabledHistory->revisions[0]->snapshot['id']);

        $disabledLeft = $installations->find($left->id);
        self::assertNotNull($disabledLeft);
        $installations->save($disabledLeft->preserve($clock->now()));
        self::assertSame(
            SchemaInstallationStatus::Preserved,
            $schemas->installation($context, $left->id)?->status,
        );
        $preservedHistory = $records->history(new RecordHistoryQuery($context, $left->handle, $leftId));
        self::assertSame(
            array_map(
                static fn (BusinessRecordRevisionView $revision): string => $revision->integrityChecksum,
                $disabledHistory->revisions,
            ),
            array_map(
                static fn (BusinessRecordRevisionView $revision): string => $revision->integrityChecksum,
                $preservedHistory->revisions,
            ),
        );

        $transactions->transactional(static function () use (
            $synchronizer,
            $extensionIdentifier,
            $context,
        ): void {
            $synchronizer->setActive($extensionIdentifier, true, $context->actorId());
        });
        self::assertSame(SchemaInstallationStatus::Active, $schemas->installation($context, $left->id)?->status);
        self::assertSame(SchemaInstallationStatus::Active, $schemas->installation($context, $right->id)?->status);
        self::assertSame(
            'Left record',
            $records->read(new ReadRecordQuery($context, $left->handle, $leftId))->values['label'],
        );
    }
}
