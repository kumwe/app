<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRelatedRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Query\AggregateFunction;
use Kumwe\App\BusinessRecord\Query\ComparisonFilter;
use Kumwe\App\BusinessRecord\Query\ComparisonOperator;
use Kumwe\App\BusinessRecord\Query\RecordAggregate;
use Kumwe\App\BusinessRecord\Query\RecordProjection;
use Kumwe\App\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Query\RelationFilter;
use Kumwe\App\BusinessRecord\Query\RelationQuantifier;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
#[CoversClass(DoctrineBusinessRecordAccessController::class)]
final class BusinessRecordPolicyEnforcementIntegrationTest extends TestCase
{
    public function testPolicyPrecedesDirectPageCountAggregateIncludeAndRelationReads(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
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
        $targetIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($targetIds as $offset => $targetId) {
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Policy target ' . ($offset + 1)],
                NeutralBusinessFixture::idempotencyKey('policy-target-' . $offset . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Policy owner'],
            NeutralBusinessFixture::idempotencyKey('policy-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            1,
            'primary_target',
            $targetIds[0],
            NeutralBusinessFixture::idempotencyKey('policy-relate-' . $suffix),
        ));
        $allowedChoices = $records->browseRelated(new BrowseRelatedRecordsQuery(
            $context,
            $owner->handle,
            'primary_target',
            'business.record.relate',
            $ownerId,
            new RecordQuerySpecification(
                pageSize: 2,
                projection: new RecordProjection(['label']),
            ),
        ));
        self::assertCount(2, $allowedChoices->page->records);
        self::assertSame($target->id, $allowedChoices->definition->id);
        self::assertSame([['handle' => 'label', 'label' => 'Label']], $allowedChoices->searchFields);
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);

        $policyCodes = [];
        $browseFields = ['list' => ['label'], 'filter' => ['label'], 'relation' => ['label']];
        $browseCode = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.browse',
            ['type' => 'constant', 'value' => true],
            $browseFields,
            $context->actorId(),
        );
        $policyCodes[] = $browseCode;
        try {
            $firstPage = $records->browse(new BrowseRecordsQuery(
                $context,
                $target->handle,
                new RecordQuerySpecification(
                    pageSize: 1,
                    projection: new RecordProjection(['label']),
                ),
            ));
            self::assertCount(1, $firstPage->records);
            self::assertNotNull($firstPage->nextCursor);

            $database->update(
                $tables->raw('resource_policies'),
                ['policy_version' => 2],
                ['policy_code' => $browseCode],
            );
            try {
                $records->browse(new BrowseRecordsQuery(
                    $context,
                    $target->handle,
                    new RecordQuerySpecification(
                        after: $firstPage->nextCursor,
                        pageSize: 1,
                        projection: new RecordProjection(['label']),
                    ),
                ));
                self::fail('A cursor must not survive a changed access-plan digest.');
            } catch (InvalidBusinessRecordQuery) {
                self::assertTrue(true);
            }

            $never = [
                'type' => 'comparison',
                'field' => 'label',
                'operator' => 'equal',
                'value_type' => 'string',
                'value' => '__policy_never_matches__',
            ];
            $database->update($tables->raw('resource_policies'), [
                'canonical_ast' => CanonicalDefinitionJson::encode($never),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $never,
                    'fields' => $browseFields,
                ]),
                'policy_version' => 3,
            ], ['policy_code' => $browseCode]);
            $reportCode = self::insertPolicy(
                $database,
                $tables,
                $target->id,
                'business.record.report',
                $never,
                ['report' => []],
                $context->actorId(),
            );
            $readCode = self::insertPolicy(
                $database,
                $tables,
                $target->id,
                'business.record.read',
                $never,
                ['detail' => ['label']],
                $context->actorId(),
            );
            $exportCode = self::insertPolicy(
                $database,
                $tables,
                $target->id,
                'business.record.export',
                $never,
                ['export' => []],
                $context->actorId(),
            );
            array_push($policyCodes, $reportCode, $readCode, $exportCode);

            $deniedPage = $records->browse(new BrowseRecordsQuery(
                $context,
                $target->handle,
                new RecordQuerySpecification(projection: new RecordProjection(['label'])),
            ));
            self::assertSame([], $deniedPage->records);
            self::assertNull($deniedPage->nextCursor);

            $deniedChoices = $records->browseRelated(new BrowseRelatedRecordsQuery(
                $context,
                $owner->handle,
                'primary_target',
                'business.record.relate',
                $ownerId,
                new RecordQuerySpecification(projection: new RecordProjection(['label'])),
            ));
            self::assertSame([], $deniedChoices->page->records);
            self::assertSame([], $deniedChoices->searchFields);

            $deniedReport = $records->browse(new BrowseRecordsQuery(
                $context,
                $target->handle,
                new RecordQuerySpecification(projection: new RecordProjection(
                    aggregates: [new RecordAggregate('row_count', AggregateFunction::Count)],
                )),
            ));
            self::assertSame([], $deniedReport->records);
            self::assertSame(0, (int) $deniedReport->aggregates['row_count']);

            $deniedExport = $records->browse(new BrowseRecordsQuery(
                $context,
                $target->handle,
                new RecordQuerySpecification(),
                purpose: BusinessRecordQueryPurpose::Export,
            ));
            self::assertSame([], $deniedExport->records);

            $included = $records->browse(new BrowseRecordsQuery(
                $context,
                $owner->handle,
                new RecordQuerySpecification(projection: new RecordProjection(
                    ['title'],
                    ['primary_target'],
                )),
            ));
            self::assertCount(1, $included->records);
            self::assertSame([], $included->records[0]->includes['primary_target']);

            $filterOnly = ['list' => ['label'], 'filter' => ['label']];
            $database->update($tables->raw('resource_policies'), [
                'field_rules' => CanonicalDefinitionJson::encode($filterOnly),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $never,
                    'fields' => $filterOnly,
                ]),
                'policy_version' => 4,
            ], ['policy_code' => $browseCode]);
            try {
                $records->browse(new BrowseRecordsQuery(
                    $context,
                    $owner->handle,
                    new RecordQuerySpecification(new RelationFilter(
                        'primary_target',
                        RelationQuantifier::Any,
                        new ComparisonFilter('label', ComparisonOperator::Equal, 'Policy target 1'),
                    )),
                ));
                self::fail('A direct filter grant must not authorize a relationship selector.');
            } catch (InvalidBusinessRecordQuery) {
                self::assertTrue(true);
            }
            $database->update($tables->raw('resource_policies'), [
                'field_rules' => CanonicalDefinitionJson::encode($browseFields),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $never,
                    'fields' => $browseFields,
                ]),
                'policy_version' => 5,
            ], ['policy_code' => $browseCode]);
            $related = $records->browse(new BrowseRecordsQuery(
                $context,
                $owner->handle,
                new RecordQuerySpecification(new RelationFilter(
                    'primary_target',
                    RelationQuantifier::Any,
                    new ComparisonFilter('label', ComparisonOperator::Equal, 'Policy target 1'),
                )),
            ));
            self::assertSame([], $related->records);

            $denied = self::readFailure($records, new ReadRecordQuery(
                $context,
                $target->handle,
                $targetIds[0],
            ));
            $missing = self::readFailure($records, new ReadRecordQuery(
                $context,
                $target->handle,
                Uuid::uuid7()->toString(),
            ));
            self::assertSame($missing->stableCode(), $denied->stableCode());
            self::assertSame($missing->getMessage(), $denied->getMessage());
        } finally {
            foreach ($policyCodes as $policyCode) {
                $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
            }
        }
    }

    public function testIncludedRowsRequirePublicIdentityAndUseOnlyIncludeFieldDisclosure(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
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
        $targetId = 'PUBLIC-' . strtoupper($suffix);
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Public-reference policy target'],
            NeutralBusinessFixture::idempotencyKey('public-reference-target-' . $suffix),
            recordId: $targetId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Public-reference policy owner'],
            NeutralBusinessFixture::idempotencyKey('public-reference-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            1,
            'primary_target',
            $targetId,
            NeutralBusinessFixture::idempotencyKey('public-reference-relate-' . $suffix),
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $ast = ['type' => 'constant', 'value' => true];
        $fieldRules = ['include' => ['label'], 'public_reference' => []];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.browse',
            $ast,
            $fieldRules,
            $context->actorId(),
        );

        try {
            $hiddenIdentity = $records->browse(new BrowseRecordsQuery(
                $context,
                $owner->handle,
                new RecordQuerySpecification(projection: new RecordProjection(
                    ['title'],
                    ['primary_target'],
                )),
            ));
            self::assertSame([], $hiddenIdentity->records[0]->includes['primary_target']);

            $fieldRules = ['include' => [], 'public_reference' => ['code']];
            $database->update($tables->raw('resource_policies'), [
                'field_rules' => CanonicalDefinitionJson::encode($fieldRules),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $ast,
                    'fields' => $fieldRules,
                ]),
                'policy_version' => 2,
            ], ['policy_code' => $policyCode]);
            $hiddenFields = $records->browse(new BrowseRecordsQuery(
                $context,
                $owner->handle,
                new RecordQuerySpecification(projection: new RecordProjection(
                    ['title'],
                    ['primary_target'],
                )),
            ));
            $included = $hiddenFields->records[0]->includes['primary_target'];
            self::assertCount(1, $included);
            self::assertSame($targetId, $included[0]->recordId);
            self::assertSame([], $included[0]->values);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    public function testEntityReferenceNeverReleasesRawKeyWithoutTargetPublicReferenceGrant(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
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
        $targetId = 'REFERENCE-' . strtoupper($suffix);
        $ownerId = Uuid::uuid7()->toString();
        $targetCreate = $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Entity-reference policy target'],
            NeutralBusinessFixture::idempotencyKey('public-field-target-' . $suffix),
            recordId: $targetId,
        ));
        self::assertNotSame($targetId, $targetCreate->recordKey);
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Entity-reference policy owner', 'target_ref' => $targetId],
            NeutralBusinessFixture::idempotencyKey('public-field-owner-' . $suffix),
            recordId: $ownerId,
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $ast = ['type' => 'constant', 'value' => true];
        $fieldRules = ['public_reference' => []];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.read',
            $ast,
            $fieldRules,
            $context->actorId(),
        );

        try {
            $redacted = $records->read(new ReadRecordQuery($context, $owner->handle, $ownerId));
            self::assertArrayNotHasKey('target_ref', $redacted->values);
            self::assertStringNotContainsString($targetCreate->recordKey, json_encode(
                $redacted->values,
                JSON_THROW_ON_ERROR,
            ));

            $fieldRules = ['public_reference' => ['code']];
            $database->update($tables->raw('resource_policies'), [
                'field_rules' => CanonicalDefinitionJson::encode($fieldRules),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $ast,
                    'fields' => $fieldRules,
                ]),
                'policy_version' => 2,
            ], ['policy_code' => $policyCode]);
            $released = $records->read(new ReadRecordQuery($context, $owner->handle, $ownerId));
            self::assertSame($targetId, $released->values['target_ref']);
            self::assertNotSame($targetCreate->recordKey, $released->values['target_ref']);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    /**
     * Proves entity-reference writes use the source operation's nested target policy plan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEntityReferenceWritesUseTheSourceOperationsNestedTargetPlan(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
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
        $targetId = 'NESTED-' . strtoupper($suffix);
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Nested-policy target'],
            NeutralBusinessFixture::idempotencyKey('nested-target-' . $suffix),
            recordId: $targetId,
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $target->id);
        $always = ['type' => 'constant', 'value' => true];
        $never = ['type' => 'constant', 'value' => false];
        $readFields = ['public_reference' => ['code']];
        $createFields = ['public_reference' => []];
        $readPolicy = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.read',
            $always,
            $readFields,
            $context->actorId(),
        );
        $createPolicy = self::insertPolicy(
            $database,
            $tables,
            $target->id,
            'business.record.create',
            $always,
            $createFields,
            $context->actorId(),
        );

        try {
            try {
                $records->create(new CreateRecordCommand(
                    $context,
                    $owner->handle,
                    ['title' => 'Denied nested reference', 'target_ref' => $targetId],
                    NeutralBusinessFixture::idempotencyKey('nested-denied-field-' . $suffix),
                    recordId: Uuid::uuid7()->toString(),
                ));
                self::fail('A direct read grant must not bypass a nested public-reference denial.');
            } catch (BusinessRecordValidationFailed $exception) {
                self::assertSame('target_ref', $exception->violations[0]->field);
                self::assertSame('reference', $exception->violations[0]->code);
            }

            $createFields = ['public_reference' => ['code']];
            $database->update($tables->raw('resource_policies'), [
                'canonical_ast' => CanonicalDefinitionJson::encode($never),
                'field_rules' => CanonicalDefinitionJson::encode($createFields),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $never,
                    'fields' => $createFields,
                ]),
                'policy_version' => 2,
            ], ['policy_code' => $createPolicy]);
            try {
                $records->create(new CreateRecordCommand(
                    $context,
                    $owner->handle,
                    ['title' => 'Denied nested row', 'target_ref' => $targetId],
                    NeutralBusinessFixture::idempotencyKey('nested-denied-row-' . $suffix),
                    recordId: Uuid::uuid7()->toString(),
                ));
                self::fail('A direct read grant must not bypass a nested row-policy denial.');
            } catch (BusinessRecordValidationFailed $exception) {
                self::assertSame('target_ref', $exception->violations[0]->field);
                self::assertSame('reference', $exception->violations[0]->code);
            }

            $database->update($tables->raw('resource_policies'), [
                'canonical_ast' => CanonicalDefinitionJson::encode($always),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $always,
                    'fields' => $createFields,
                ]),
                'policy_version' => 3,
            ], ['policy_code' => $createPolicy]);
            $created = $records->create(new CreateRecordCommand(
                $context,
                $owner->handle,
                ['title' => 'Allowed nested reference', 'target_ref' => $targetId],
                NeutralBusinessFixture::idempotencyKey('nested-allowed-' . $suffix),
                recordId: Uuid::uuid7()->toString(),
            ));
            self::assertSame(1, $created->version);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $createPolicy]);
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $readPolicy]);
        }
    }

    public function testActionUsesItsOwnRowAndActionPolicyBeforeMutation(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $definitionId = Uuid::uuid7()->toString();
        $suffix = strtolower(substr(str_replace('-', '', $definitionId), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Policy action target'),
            NeutralBusinessFixture::idempotencyKey('policy-action-create-' . $suffix),
            recordId: $recordId,
        ));
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['business_record', $definition->id . ':' . $recordId]));
        NeutralBusinessFixture::removeRecordAccess($container, $definition->id);
        $never = [
            'type' => 'comparison',
            'field' => 'name',
            'operator' => 'equal',
            'value_type' => 'string',
            'value' => '__policy_never_matches__',
        ];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $definition->id,
            'business.record.action',
            $never,
            ['detail' => ['name'], 'actions' => ['approve']],
            $context->actorId(),
        );

        try {
            $records->action(new ExecuteRecordActionCommand(
                $context,
                $definition->handle,
                $recordId,
                1,
                'approve',
                NeutralBusinessFixture::idempotencyKey('policy-action-denied-' . $suffix),
            ));
            self::fail('An action must not enumerate or mutate a row hidden by its action policy.');
        } catch (BusinessRecordNotFound) {
            self::assertTrue(true);
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    public function testHardDeletedHistoryIsPolicyFilteredBeforeRevisionRowsAreMapped(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $definitionId = Uuid::uuid7()->toString();
        $suffix = strtolower(substr(str_replace('-', '', $definitionId), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, $definitionId),
        );
        $recordId = Uuid::uuid7()->toString();
        $created = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Policy-hidden hard delete'],
            NeutralBusinessFixture::idempotencyKey('policy-history-create-' . $suffix),
            recordId: $recordId,
        ));
        $resourceId = $definition->id . ':' . $created->recordKey;
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['business_record', $resourceId]));
        $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            1,
            NeutralBusinessFixture::idempotencyKey('policy-history-delete-' . $suffix),
        ));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['business_record', $resourceId]));
        NeutralBusinessFixture::removeRecordAccess($container, $definition->id);
        $never = [
            'type' => 'comparison',
            'field' => 'label',
            'operator' => 'equal',
            'value_type' => 'string',
            'value' => '__policy_never_matches__',
        ];
        $fieldRules = ['audit' => ['label']];
        $policyCode = self::insertPolicy(
            $database,
            $tables,
            $definition->id,
            'business.record.history',
            $never,
            $fieldRules,
            $context->actorId(),
        );
        $database->update(
            $tables->raw('business_record_revisions'),
            ['checksum' => str_repeat('0', 64)],
            ['definition_id' => $definition->id],
        );

        try {
            $denied = self::historyFailure($records, new RecordHistoryQuery(
                $context,
                $definition->handle,
                $recordId,
            ));
            $missing = self::historyFailure($records, new RecordHistoryQuery(
                $context,
                $definition->handle,
                Uuid::uuid7()->toString(),
            ));
            self::assertSame($missing->stableCode(), $denied->stableCode());
            self::assertSame($missing->getMessage(), $denied->getMessage());

            $allow = ['type' => 'constant', 'value' => true];
            $database->update($tables->raw('resource_policies'), [
                'canonical_ast' => CanonicalDefinitionJson::encode($allow),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $allow,
                    'fields' => $fieldRules,
                ]),
                'policy_version' => 2,
            ], ['policy_code' => $policyCode]);
            try {
                $records->history(new RecordHistoryQuery($context, $definition->handle, $recordId));
                self::fail('The checksum sentinel must be reached only when SQL row policy admits the revision.');
            } catch (BusinessRecordSchemaUnavailable) {
                self::assertTrue(true);
            }
        } finally {
            $database->delete($tables->raw('resource_policies'), ['policy_code' => $policyCode]);
        }
    }

    public function testHardDeleteClearsPolicyHiddenSetNullReferrersWithoutDisclosingThem(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
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
            ['label' => 'Hidden integrity target'],
            NeutralBusinessFixture::idempotencyKey('policy-integrity-target-' . $suffix),
            recordId: $targetId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Policy-hidden referrer'],
            NeutralBusinessFixture::idempotencyKey('policy-integrity-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            1,
            'primary_target',
            $targetId,
            NeutralBusinessFixture::idempotencyKey('policy-integrity-relate-' . $suffix),
        ));
        NeutralBusinessFixture::removeRecordAccess($container, $owner->id);

        $deleted = $records->delete(new DeleteRecordCommand(
            $context,
            $target->handle,
            $targetId,
            1,
            NeutralBusinessFixture::idempotencyKey('policy-integrity-delete-' . $suffix),
        ));
        self::assertTrue($deleted->deleted);
        $ownerResolved = $resolver->forCreate($context, $owner->handle);
        $table = $ownerResolved->installation->blueprint->table('record');
        self::assertNotNull($table);
        $identity = $table->column('record_id');
        $version = $table->column('version');
        $reference = $table->column('relation:primary_target.target_id');
        self::assertNotNull($identity);
        self::assertNotNull($version);
        self::assertNotNull($reference);
        $row = $database->fetchAssociative(sprintf(
            'SELECT %s, %s FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteIdentifier($version->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($reference->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($table->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier($identity->physicalName),
        ), [$ownerId]);
        self::assertIsArray($row);
        self::assertSame(3, (int) $row[$version->physicalName]);
        self::assertNull($row[$reference->physicalName]);
        self::readFailure($records, new ReadRecordQuery($context, $owner->handle, $ownerId));
    }

    /**
     * Insert one definition-specific policy row in canonical stored form.
     *
     * @param   Connection           $database      Integration database.
     * @param   TableNames           $tables        Portable table-name compiler.
     * @param   string               $definitionId  Definition UUID protected by the row.
     * @param   string               $operation     Exact business-record operation.
     * @param   array<string,mixed>  $ast           Canonical typed row predicate.
     * @param   array<string,mixed>  $fieldRules    Explicit field and action rules.
     * @param   string               $actorId       Actor recorded as policy author.
     *
     * @return  string  Unique policy code for later update and cleanup.
     *
     * @since   2.0.0
     */
    private static function insertPolicy(
        Connection $database,
        TableNames $tables,
        string $definitionId,
        string $operation,
        array $ast,
        array $fieldRules,
        string $actorId,
    ): string {
        $policyCode = 'test.business.record.' . Uuid::uuid7()->toString();
        $database->insert($tables->raw('resource_policies'), [
            'id' => Uuid::uuid7()->toString(),
            'policy_code' => $policyCode,
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
            'capability_code' => $operation,
            'resource_type' => 'business_record',
            'action' => $operation,
            'effect' => 'allow',
            'scope_type' => 'global',
            'organization_id' => null,
            'entity_definition_id' => $definitionId,
            'canonical_ast' => CanonicalDefinitionJson::encode($ast),
            'field_rules' => CanonicalDefinitionJson::encode($fieldRules),
            'ast_checksum' => CanonicalDefinitionJson::checksum([
                'ast' => $ast,
                'fields' => $fieldRules,
            ]),
            'policy_version' => 1,
            'priority' => 10,
            'status' => 'active',
            'created_by' => $actorId,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        return $policyCode;
    }

    /**
     * Capture the uniform not-found result of a direct record read.
     *
     * @param   BusinessRecordService  $records  Shared record application service.
     * @param   ReadRecordQuery        $query    Existing, denied, or absent identity read.
     *
     * @return  BusinessRecordNotFound  Non-enumerating failure returned by the service.
     *
     * @since   2.0.0
     */
    private static function readFailure(
        BusinessRecordService $records,
        ReadRecordQuery $query,
    ): BusinessRecordNotFound {
        try {
            $records->read($query);
        } catch (BusinessRecordNotFound $exception) {
            return $exception;
        }

        self::fail('A policy-hidden or absent record must not be returned.');
    }

    /**
     * Capture the uniform not-found result of a hard-deleted record history lookup.
     *
     * @param   BusinessRecordService  $records  Shared record application service.
     * @param   RecordHistoryQuery     $query    Existing denied or genuinely absent identity history.
     *
     * @return  BusinessRecordNotFound  Non-enumerating failure returned by the service.
     *
     * @since   2.0.0
     */
    private static function historyFailure(
        BusinessRecordService $records,
        RecordHistoryQuery $query,
    ): BusinessRecordNotFound {
        try {
            $records->history($query);
        } catch (BusinessRecordNotFound $exception) {
            return $exception;
        }

        self::fail('Policy-hidden or absent hard-deleted history must not be returned.');
    }
}
