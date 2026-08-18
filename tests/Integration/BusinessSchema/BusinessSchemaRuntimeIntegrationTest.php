<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessSchema;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessSchemaService::class)]
final class BusinessSchemaRuntimeIntegrationTest extends TestCase
{
    public function testPublishPlanApproveExecuteAndIntrospectTypedSchema(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $draft = $definitions->saveDraft($context, EntityTypeDefinition::fromArray(self::document($suffix)));
        $published = $definitions->publish($context, $draft->definition->id, $draft->revision);

        $plan = $schemas->createPlan($context, $published->definition->id);
        self::assertSame(SchemaPlanStatus::PendingApproval, $plan->status);
        $approved = $schemas->approve($context, $plan->id, $plan->checksum(), null, null);
        self::assertSame(SchemaPlanStatus::Approved, $approved->status);
        $outcome = $schemas->execute($context, $approved->id);
        $installation = $schemas->installation($context, $published->definition->id);

        self::assertNotNull($installation);
        self::assertSame(SchemaInstallationStatus::Active, $installation->status);
        self::assertSame($installation->schemaChecksum, $outcome->schemaChecksum);
        self::assertSame($installation->schemaChecksum, $installation->blueprint->checksum());
        $record = $installation->blueprint->table('record');
        self::assertNotNull($record);
        $actual = $database->createSchemaManager()->introspectTableByUnquotedName($record->physicalName);
        self::assertTrue($actual->hasColumn($record->column('record_id')?->physicalName ?? 'missing'));
        self::assertTrue($actual->hasColumn($record->column('amount')?->physicalName ?? 'missing'));
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE id = ? AND status = ?',
            $tables->quoted('business_schema_plans'),
        ), [$plan->id, SchemaPlanStatus::Completed->value]));
    }

    /** @return array<string, mixed> */
    private static function document(string $suffix): array
    {
        return [
            'id' => Uuid::uuid7()->toString(),
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.schema_runtime_' . $suffix,
            'singular_label' => 'Schema runtime',
            'plural_label' => 'Schema runtimes',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'amount',
                    'label' => 'Amount',
                    'type' => 'core.decimal',
                    'precision' => 24,
                    'scale' => 6,
                    'indexed' => true,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }
}
