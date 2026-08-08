<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessSchema;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\CMS\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaStepStatus;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
final class BusinessSchemaSourceBindingRecoveryIntegrationTest extends TestCase
{
    public function testRecoveryRejectsTamperedSourceMetadataThenResumesPartialPhysicalWork(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $plans = $container->get(BusinessSchemaPlanRepository::class);
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        $compiler = $container->get(DefinitionPhysicalSchemaCompiler::class);
        $physical = $container->get(PhysicalSchemaGateway::class);
        $lock = $container->get(BusinessSchemaExecutionLock::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessSchemaPlanRepository::class, $plans);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $installations);
        self::assertInstanceOf(DefinitionPhysicalSchemaCompiler::class, $compiler);
        self::assertInstanceOf(PhysicalSchemaGateway::class, $physical);
        self::assertInstanceOf(BusinessSchemaExecutionLock::class, $lock);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $definitionId = Uuid::uuid7()->toString();
        $handle = 'site.default.recovery_source_' . self::suffix();
        NeutralBusinessFixture::install(
            $container,
            $context,
            self::document($definitionId, $handle, false),
        );
        $source = $installations->find($definitionId);
        self::assertNotNull($source);
        self::assertSame(1, $source->definitionVersion);

        $draft = $definitions->draft($context, $definitionId);
        $saved = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray(self::document($definitionId, $handle, true)),
            $draft->revision,
        );
        $v2 = $definitions->publish($context, $definitionId, $saved->revision)->definition;
        self::assertSame(2, $v2->definitionVersion);
        $pending = $schemas->createPlan($context, $definitionId);
        self::assertCount(1, $pending->operations());
        self::assertSame(SchemaOperationKind::AddColumn, $pending->operations()[0]->kind);
        $approved = $schemas->approve($context, $pending->id, $pending->checksum(), null, null);
        $target = $compiler->compile($v2, $context->site());

        $lock->synchronized($definitionId, function (int $fence) use (
            $plans,
            $installations,
            $physical,
            $clock,
            $context,
            $approved,
            $source,
            $target,
        ): void {
            $current = $plans->find($context->site(), $approved->id);
            self::assertNotNull($current);
            $executing = $current->begin($fence, $clock->now());
            $plans->replace($executing, $current->revision);
            $steps = $plans->steps($executing->id);
            self::assertCount(1, $steps);
            $operation = $executing->operations()[0];
            $before = $executing->fromSchemaChecksum;
            self::assertNotNull($before);
            $running = $steps[0]->start($fence, $before, $clock->now());
            $plans->replaceStep($running, null);

            $physical->execute($operation, $target);
            self::assertTrue($physical->operationSatisfied($operation, $target));
            $chain = hash('sha256', implode("\0", [
                $before,
                $operation->checksum(),
                (string) $fence,
                'applied',
            ]));
            $completed = $running->complete($chain, [
                'already_satisfied' => false,
                'processed_rows' => 0,
                'fence' => $fence,
                'simulated_process_crash' => true,
            ], $clock->now());
            $plans->replaceStep($completed, $fence);
            $installations->save(new SchemaInstallation(
                $source->definitionId,
                $source->siteIdentifier,
                $source->ownerIdentifier,
                $source->definitionVersion,
                $source->definitionChecksum,
                $source->schemaChecksum,
                $source->blueprint,
                SchemaInstallationStatus::Installing,
                $source->installedAt,
                $clock->now(),
            ));
            $interrupted = $executing->recoveryRequired(
                'process_crash',
                ['fence' => $fence, 'durable_steps' => 1],
                $clock->now(),
            );
            $plans->replace($interrupted, $executing->revision, $fence);
        });

        $installations->save(new SchemaInstallation(
            $definitionId,
            $context->site()->identifier(),
            $v2->owner->identifier,
            $v2->definitionVersion,
            $v2->checksum(),
            $target->checksum(),
            $target,
            SchemaInstallationStatus::Installing,
            $source->installedAt,
            $clock->now(),
        ));
        try {
            $schemas->recover($context, $approved->id);
            self::fail('Recovery must reject source installation metadata changed after approval.');
        } catch (BusinessSchemaConflict $exception) {
            self::assertStringContainsString('source installation metadata', $exception->getMessage());
        } finally {
            $installations->save(new SchemaInstallation(
                $source->definitionId,
                $source->siteIdentifier,
                $source->ownerIdentifier,
                $source->definitionVersion,
                $source->definitionChecksum,
                $source->schemaChecksum,
                $source->blueprint,
                SchemaInstallationStatus::Installing,
                $source->installedAt,
                $clock->now(),
            ));
        }

        self::assertSame(
            SchemaPlanStatus::RecoveryRequired,
            $schemas->plan($context, $approved->id)->status,
        );
        self::assertSame(
            SchemaStepStatus::Completed,
            $schemas->steps($context, $approved->id)[0]->state,
        );
        self::assertSame($target->checksum(), $physical->inspect($target)?->checksum());

        $outcome = $schemas->recover($context, $approved->id);
        $installation = $schemas->installation($context, $definitionId);
        self::assertTrue($outcome->resumed);
        self::assertSame(0, $outcome->completedSteps);
        self::assertSame(1, $outcome->skippedSteps);
        self::assertNotNull($installation);
        self::assertSame(SchemaInstallationStatus::Active, $installation->status);
        self::assertSame(2, $installation->definitionVersion);
        self::assertSame($target->checksum(), $installation->schemaChecksum);
        self::assertSame(SchemaPlanStatus::Completed, $schemas->plan($context, $approved->id)->status);
    }

    /** @return array<string, mixed> */
    private static function document(string $id, string $handle, bool $withOptionalField): array
    {
        $fields = [[
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
        ]];
        if ($withOptionalField) {
            $fields[] = [
                'handle' => 'note',
                'label' => 'Note',
                'type' => 'core.text',
                'required' => false,
                'nullable' => true,
                'length' => 120,
            ];
        }

        return [
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Recovery source fixture',
            'plural_label' => 'Recovery source fixtures',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => $fields,
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }

    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 12));
    }
}
