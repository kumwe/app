<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSchema;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutor;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins the one column change the executor is allowed to treat as safe for rows pinned to an older version.
 *
 * Before a plan may touch a table that still holds rows validated under an earlier definition, the executor
 * asks whether the plan narrows anything. Widening a column narrows nothing — every value the old column
 * accepted is still valid — so a pure relaxation has to pass that gate without a re-pin step. Getting this
 * wrong in either direction is expensive: too strict and every harmless widening demands a full re-pin of
 * the table, too loose and a genuine narrowing strands rows the old definition allowed.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSchemaExecutor::class)]
#[CoversClass(BusinessSchemaService::class)]
final class BusinessSchemaColumnRelaxationIntegrationTest extends TestCase
{
    /**
     * A widening evolution executes over pinned rows without being blocked or demanding a re-pin.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPureColumnWideningExecutesOverRowsPinnedToTheOlderVersion(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definitionId = Uuid::uuid7()->toString();
        $installed = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        self::assertSame(1, $installed->definitionVersion);

        $records->create(new CreateRecordCommand(
            $context,
            $installed->handle,
            NeutralBusinessFixture::recordValues('Pinned to version one'),
            NeutralBusinessFixture::idempotencyKey('relaxation-create-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        ));

        $draft = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray(
                NeutralBusinessFixture::relaxationDocument($suffix, $definitionId),
            ),
            0,
        );
        $definitions->publish($context, $definitionId, $draft->revision, true);

        $plan = $schemas->createPlan($context, $definitionId);
        self::assertNotNull($plan->fromSchemaChecksum);
        $kinds = array_map(
            static fn (SchemaOperation $operation): SchemaOperationKind => $operation->kind,
            $plan->operations(),
        );
        self::assertContains(SchemaOperationKind::AlterColumn, $kinds);
        foreach (
            [
            SchemaOperationKind::DropTable,
            SchemaOperationKind::DropColumn,
            SchemaOperationKind::RenameColumn,
            SchemaOperationKind::Transform,
            SchemaOperationKind::RepinRecords,
            ] as $absent
        ) {
            self::assertNotContains($absent, $kinds);
        }

        $approved = $schemas->approve(
            $context,
            $plan->id,
            $plan->checksum(),
            $plan->checksum(),
            null,
        );
        self::assertSame(SchemaPlanStatus::Approved, $approved->status);

        $schemas->execute($context, $approved->id);

        $installation = $schemas->installation($context, $definitionId);
        self::assertNotNull($installation);
        self::assertSame(SchemaInstallationStatus::Active, $installation->status);
        self::assertSame(2, $installation->definitionVersion);
        self::assertSame(400, $installation->blueprint->table('record')?->column('evolution_code')?->options['length']);
    }
}
