<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\CMS\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRisk;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/** Produces and persists canonical plans; execution remains a separate authorized boundary. */
final readonly class BusinessSchemaPlanner implements PublishedDefinitionSchemaObserver
{
    public const PURGED_SCHEMA_CHECKSUM = 'c6cb2eb24aa57518c4c1639014771aa76c3fe4c909b1ff9c18ba7eda35c35e93';

    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaPlanRepository $plans,
        private PhysicalSchemaGateway $physicalSchema,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function plan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.schema.plan'),
            AuthorizationResource::collection('business_schema'),
        );
        $record = $this->definitions->published($context->site(), $definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);

        return $this->transactions->transactional(fn (): SchemaPlan => $this->persistPlan(
            $context->site(),
            $record,
            $context->actorId(),
            $this->clock->now(),
            true,
            $this->dependencyBlueprints($record->definition, $context->site()),
        ));
    }

    /** Persist an explicit, independently approved destructive purge plan. */
    public function purgePlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.schema.destructive'),
            AuthorizationResource::collection('business_schema'),
        );
        $installed = $this->installations->find($definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);
        if ($installed->siteIdentifier !== $context->site()->identifier()) {
            throw new BusinessSchemaNotFound($definitionId);
        }
        $record = $this->definitions->published($context->site(), $definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use ($context, $record, $installed, $now): SchemaPlan {
            $tables = $installed->blueprint->tables();
            usort($tables, static function (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int {
                if ($left->logicalName === 'record') {
                    return 1;
                }
                if ($right->logicalName === 'record') {
                    return -1;
                }

                return strcmp($right->logicalName, $left->logicalName);
            });
            $specifications = [];
            foreach ($tables as $table) {
                $specifications[] = $this->spec(
                    SchemaOperationKind::DropTable,
                    SchemaRisk::Destructive,
                    $table->logicalName,
                    $table->logicalName,
                    $table->toArray(),
                    null,
                    false,
                    'restore_required',
                );
            }
            $operations = $this->number($specifications);
            $plan = new SchemaPlan(
                Uuid::uuid7()->toString(),
                $installed->definitionId,
                $installed->siteIdentifier,
                null,
                $installed->definitionVersion,
                null,
                $installed->definitionChecksum,
                $installed->schemaChecksum,
                self::PURGED_SCHEMA_CHECKSUM,
                $operations,
                SchemaRisk::Destructive,
                SchemaPlanStatus::PendingApproval,
                1,
                $context->actorId(),
                $now,
            );
            $this->plans->save($plan);
            foreach ($operations as $operation) {
                $this->plans->saveStep(SchemaPlanStep::pending($plan->id, $operation, $now));
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.destructive.plan',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'definition_id' => $record->definition->id,
                    'plan_checksum' => $plan->checksum(),
                    'operation_count' => count($operations),
                ],
            ));

            return $plan;
        });
    }

    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array {
        if ($definitions === [] || count($definitions) > 128) {
            throw new InvalidBusinessSchema('A published definition graph is empty or unbounded.');
        }
        usort($definitions, static fn (DefinitionVersionRecord $left, DefinitionVersionRecord $right): int =>
            strcmp($left->definition->handle, $right->definition->handle));

        return $this->transactions->transactional(function () use (
            $site,
            $definitions,
            $actorIdentifier,
            $now,
        ): array {
            $byHandle = [];
            foreach ($definitions as $record) {
                $byHandle[$record->definition->handle] = $this->compiler->compile($record->definition, $site);
            }
            $result = [];
            foreach ($definitions as $record) {
                $dependencies = [];
                foreach ($this->dependencyHandles($record->definition) as $handle) {
                    $dependencies[] = $byHandle[$handle]
                        ?? $this->compiler->compile(
                            $this->definitions->published($site, $handle)?->definition
                                ?? throw new BusinessSchemaNotFound($handle),
                            $site,
                        );
                }
                $result[] = $this->persistPlan(
                    $site,
                    $record,
                    $actorIdentifier,
                    $now,
                    false,
                    $dependencies,
                );
            }

            return $result;
        });
    }

    private function persistPlan(
        SiteContext $site,
        DefinitionVersionRecord $record,
        string $actorIdentifier,
        DateTimeImmutable $now,
        bool $audit,
        array $dependencyBlueprints = [],
    ): SchemaPlan {
        $definition = $record->definition;
        if ($definition->siteIdentifier !== $site->identifier()) {
            throw new InvalidBusinessSchema('A published schema graph cannot cross site scope.');
        }
        $target = $this->compiler->compile($definition, $site);
        $installed = $this->installations->find($definition->id);
        $prior = null;
        if ($installed !== null) {
            if ($installed->siteIdentifier !== $site->identifier()) {
                throw new BusinessSchemaConflict('Installed schema metadata belongs to another site.');
            }
            if ($installed->definitionVersion >= $definition->definitionVersion) {
                $existing = $this->plans->latestForDefinition($site, $definition->id);
                if (
                    $installed->definitionVersion === $definition->definitionVersion
                    && hash_equals($installed->definitionChecksum, $definition->checksum())
                    && $existing !== null
                ) {
                    return $existing;
                }
                throw new BusinessSchemaConflict('The installed schema is not older than the published definition.');
            }
            $inspected = $this->physicalSchema->inspect($installed->blueprint);
            if ($inspected === null || !hash_equals($inspected->checksum(), $installed->schemaChecksum)) {
                throw new BusinessSchemaConflict('The installed physical schema checksum no longer matches metadata.');
            }
            $prior = $installed->blueprint;
        }
        $operations = $this->operations($prior, $target, $definition, $dependencyBlueprints);
        if ($prior !== null && $this->containsPinnedRowBreakingChange($operations)
            && !$this->hasRecordRepin($operations, $definition->definitionVersion)
            && $this->physicalSchema->hasRowsPinnedBefore($prior, $definition->definitionVersion)) {
            throw new InvalidBusinessSchema(
                'Older definition-version rows remain pinned; drop/type replacement requires a bounded re-pin plan.',
            );
        }
        $risk = SchemaRisk::highest(array_map(
            static fn (SchemaOperation $operation): SchemaRisk => $operation->risk,
            $operations,
        ));
        $plan = new SchemaPlan(
            Uuid::uuid7()->toString(),
            $definition->id,
            $site->identifier(),
            $installed?->definitionVersion,
            $definition->definitionVersion,
            $installed?->definitionChecksum,
            $definition->checksum(),
            $installed?->schemaChecksum,
            $target->checksum(),
            $operations,
            $risk,
            SchemaPlanStatus::PendingApproval,
            1,
            $actorIdentifier,
            $now,
        );
        $existing = $this->plans->latestForDefinition($site, $definition->id);
        if ($existing !== null && hash_equals($existing->checksum(), $plan->checksum())) {
            return $existing;
        }
        $this->plans->save($plan);
        foreach ($operations as $operation) {
            $this->plans->saveStep(SchemaPlanStep::pending($plan->id, $operation, $now));
        }
        if ($audit) {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorIdentifier,
                'business.schema.plan',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'definition_id' => $definition->id,
                    'target_version' => $definition->definitionVersion,
                    'plan_checksum' => $plan->checksum(),
                    'risk' => $plan->risk->value,
                    'operation_count' => count($operations),
                ],
            ));
        }

        return $plan;
    }

    /**
     * @return list<SchemaOperation>
     */
    private function operations(
        ?PhysicalSchemaBlueprint $prior,
        PhysicalSchemaBlueprint $target,
        EntityTypeDefinition $definition,
        array $dependencyBlueprints = [],
    ): array {
        if ($prior === null) {
            $tables = $target->tables();
            usort($tables, static function (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int {
                if ($left->logicalName === 'record') {
                    return -1;
                }
                if ($right->logicalName === 'record') {
                    return 1;
                }
                return strcmp($left->logicalName, $right->logicalName);
            });
            $specifications = [];
            foreach ($tables as $table) {
                $withoutKeys = $this->withoutForeignKeys($table);
                $specifications[] = $this->spec(
                    SchemaOperationKind::CreateTable,
                    SchemaRisk::OnlineSafeAdditive,
                    $table->logicalName,
                    $table->logicalName,
                    null,
                    $withoutKeys->toArray(),
                    false,
                    'compensate_safe_addition',
                );
            }
            foreach ($tables as $table) {
                foreach ($table->foreignKeys() as $foreignKey) {
                    $specifications[] = $this->spec(
                        SchemaOperationKind::AddForeignKey,
                        SchemaRisk::BehaviorChanging,
                        $table->logicalName,
                        $foreignKey->logicalName,
                        null,
                        $foreignKey->toArray(),
                        false,
                        'resume_required',
                    );
                }
            }

            return $this->number($specifications);
        }

        $hints = SchemaEvolutionHints::fromDefinition($definition);
        $this->validateEvolutionHints($prior, $target, $definition, $hints);

        $oldTables = $this->tablesByLogical($prior);
        $newTables = $this->tablesByLogical($target);
        $drops = [];
        $creates = [];
        $alters = [];
        foreach (array_diff_key($oldTables, $newTables) as $logical => $table) {
            $drops[] = [
                SchemaOperationKind::DropTable,
                SchemaRisk::Destructive,
                $logical,
                $logical,
                $table->toArray(),
                null,
                false,
                'restore_required',
            ];
        }
        foreach (array_diff_key($newTables, $oldTables) as $logical => $table) {
            $creates[] = [
                SchemaOperationKind::CreateTable,
                SchemaRisk::OnlineSafeAdditive,
                $logical,
                $logical,
                null,
                $table->toArray(),
                false,
                'compensate_safe_addition',
            ];
        }
        foreach (array_intersect_key($newTables, $oldTables) as $logical => $table) {
            array_push($alters, ...$this->tableOperations(
                $oldTables[$logical],
                $table,
                $hints,
            ));
        }
        usort($drops, static fn (array $left, array $right): int => strcmp($left[2], $right[2]));
        usort($creates, static fn (array $left, array $right): int => strcmp($left[2], $right[2]));

        $repin = [];
        if ($hints->repin($definition->handle) !== null) {
            $repin[] = $this->spec(
                SchemaOperationKind::RepinRecords,
                SchemaRisk::BackfillRequired,
                'record',
                'definition_version',
                ['before_version' => $prior->definitionVersion],
                ['definition_version' => $definition->definitionVersion],
                true,
                'resume_required',
            );
        }

        return $this->number([...$drops, ...$creates, ...$alters, ...$repin]);
    }

    /** @return list<array{SchemaOperationKind, SchemaRisk, string, string, ?array, ?array, bool, string}> */
    private function tableOperations(
        PhysicalTableBlueprint $prior,
        PhysicalTableBlueprint $target,
        SchemaEvolutionHints $hints,
    ): array {
        $dropConstraints = [];
        $columnWork = [];
        $addConstraints = [];
        $oldForeignKeys = $this->foreignKeysByLogical($prior);
        $newForeignKeys = $this->foreignKeysByLogical($target);
        $oldColumnsForTransform = $this->columnsByLogical($prior);
        $newColumnsForTransform = $this->columnsByLogical($target);
        $transformedPhysical = [];
        foreach ($hints->transforms() as $logical => $_expression) {
            if (
                $prior->logicalName === 'record'
                && isset($oldColumnsForTransform[$logical], $newColumnsForTransform[$logical])
                && $oldColumnsForTransform[$logical]->doctrineType !== $newColumnsForTransform[$logical]->doctrineType
            ) {
                $transformedPhysical[] = $oldColumnsForTransform[$logical]->physicalName;
            }
        }
        foreach ($oldForeignKeys as $logical => $key) {
            if (
                !isset($newForeignKeys[$logical])
                || $newForeignKeys[$logical]->toArray() !== $key->toArray()
                || array_intersect($key->localColumns, $transformedPhysical) !== []
            ) {
                $dropConstraints[] = $this->spec(
                    SchemaOperationKind::DropForeignKey,
                    SchemaRisk::BehaviorChanging,
                    $prior->logicalName,
                    $logical,
                    $key->toArray(),
                    null,
                    false,
                    'resume_required',
                );
            }
        }
        $oldIndexes = $this->indexesByLogical($prior);
        $newIndexes = $this->indexesByLogical($target);
        foreach ($oldIndexes as $logical => $index) {
            if (
                !isset($newIndexes[$logical])
                || $newIndexes[$logical]->toArray() !== $index->toArray()
                || array_intersect($index->columns, $transformedPhysical) !== []
            ) {
                $dropConstraints[] = $this->spec(
                    SchemaOperationKind::DropIndex,
                    SchemaRisk::RebuildOrLocking,
                    $prior->logicalName,
                    $logical,
                    $index->toArray(),
                    null,
                    false,
                    'resume_required',
                );
            }
        }

        $oldColumns = $this->columnsByLogical($prior);
        $newColumns = $this->columnsByLogical($target);
        $renamedOld = $hints->renameForTable($prior->logicalName);
        foreach ($renamedOld as $oldLogical => $newLogical) {
            if (!isset($oldColumns[$oldLogical], $newColumns[$newLogical])) {
                throw new InvalidBusinessSchema('A declared schema rename does not match compiled columns.');
            }
            $columnWork[] = $this->spec(
                SchemaOperationKind::RenameColumn,
                SchemaRisk::BehaviorChanging,
                $prior->logicalName,
                $newLogical,
                $oldColumns[$oldLogical]->toArray(),
                $newColumns[$newLogical]->toArray(),
                false,
                'resume_required',
            );
            unset($oldColumns[$oldLogical], $newColumns[$newLogical]);
        }
        foreach (array_diff_key($oldColumns, $newColumns) as $logical => $column) {
            $columnWork[] = $this->spec(
                SchemaOperationKind::DropColumn,
                SchemaRisk::Destructive,
                $prior->logicalName,
                $logical,
                $column->toArray(),
                null,
                false,
                'restore_required',
            );
        }
        foreach (array_diff_key($newColumns, $oldColumns) as $logical => $column) {
            if ($column->nullable) {
                $columnWork[] = $this->spec(
                    SchemaOperationKind::AddColumn,
                    SchemaRisk::OnlineSafeAdditive,
                    $target->logicalName,
                    $logical,
                    null,
                    $column->toArray(),
                    false,
                    'compensate_safe_addition',
                );
                continue;
            }
            $value = $column->options['default'] ?? $this->backfillValue($hints, $logical);
            $nullable = new PhysicalColumnBlueprint(
                $column->logicalName,
                $column->physicalName,
                $column->doctrineType,
                $column->options,
                true,
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::AddColumn,
                SchemaRisk::OnlineSafeAdditive,
                $target->logicalName,
                $logical,
                null,
                $nullable->toArray(),
                false,
                'compensate_safe_addition',
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::Backfill,
                SchemaRisk::BackfillRequired,
                $target->logicalName,
                $logical,
                null,
                $this->backfillState($nullable, $value, $oldColumns),
                true,
                'resume_required',
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::AlterColumn,
                SchemaRisk::BehaviorChanging,
                $target->logicalName,
                $logical,
                $nullable->toArray(),
                $column->toArray(),
                false,
                'resume_required',
            );
        }
        foreach (array_intersect_key($newColumns, $oldColumns) as $logical => $column) {
            $old = $oldColumns[$logical];
            if ($old->toArray() === $column->toArray()) {
                continue;
            }
            if ($old->doctrineType !== $column->doctrineType) {
                $expression = $hints->transform($logical)
                    ?? throw new InvalidBusinessSchema(
                        'A physical type change requires an explicit bounded transform expression.',
                    );
                $shadow = $this->transformShadowColumn($target, $column);
                $dependencies = [];
                foreach ($expression->dependencies() as $dependency) {
                    $dependencyColumn = $oldColumns[$dependency] ?? null;
                    if ($dependencyColumn === null) {
                        throw new InvalidBusinessSchema(
                            'A schema transform references a field unavailable in the prior physical table.',
                        );
                    }
                    $dependencies[$dependency] = $dependencyColumn->toArray();
                }
                ksort($dependencies, SORT_STRING);
                $columnWork[] = $this->spec(
                    SchemaOperationKind::AddColumn,
                    SchemaRisk::OnlineSafeAdditive,
                    $target->logicalName,
                    $logical . '.transform',
                    null,
                    $shadow->toArray(),
                    false,
                    'compensate_safe_addition',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::Transform,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical . '.transform',
                    $old->toArray(),
                    [
                        'source' => $old->toArray(),
                        'target' => $shadow->toArray(),
                        'expression' => $expression->toArray(),
                        'dependencies' => $dependencies,
                        'primary_key' => $prior->primaryKey,
                    ],
                    true,
                    'resume_required',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::DropColumn,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    $old->toArray(),
                    null,
                    false,
                    'resume_required',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::RenameColumn,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    $shadow->toArray(),
                    $column->toArray(),
                    false,
                    'resume_required',
                );
                continue;
            }
            if ($old->nullable && !$column->nullable) {
                $value = $column->options['default'] ?? $this->backfillValue($hints, $logical);
                $columnWork[] = $this->spec(
                    SchemaOperationKind::Backfill,
                    SchemaRisk::BackfillRequired,
                    $target->logicalName,
                    $logical,
                    null,
                    $this->backfillState($old, $value, $oldColumns),
                    true,
                    'resume_required',
                );
            }
            $columnWork[] = $this->spec(
                SchemaOperationKind::AlterColumn,
                SchemaRisk::BehaviorChanging,
                $target->logicalName,
                $logical,
                $old->toArray(),
                $column->toArray(),
                false,
                'resume_required',
            );
        }

        foreach ($newIndexes as $logical => $index) {
            if (
                !isset($oldIndexes[$logical])
                || $oldIndexes[$logical]->toArray() !== $index->toArray()
                || array_intersect($index->columns, $transformedPhysical) !== []
            ) {
                $addConstraints[] = $this->spec(
                    SchemaOperationKind::AddIndex,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    null,
                    $index->toArray(),
                    false,
                    'resume_required',
                );
            }
        }
        foreach ($newForeignKeys as $logical => $key) {
            if (
                !isset($oldForeignKeys[$logical])
                || $oldForeignKeys[$logical]->toArray() !== $key->toArray()
                || array_intersect($key->localColumns, $transformedPhysical) !== []
            ) {
                $addConstraints[] = $this->spec(
                    SchemaOperationKind::AddForeignKey,
                    SchemaRisk::BehaviorChanging,
                    $target->logicalName,
                    $logical,
                    null,
                    $key->toArray(),
                    false,
                    'resume_required',
                );
            }
        }

        return [...$dropConstraints, ...$columnWork, ...$addConstraints];
    }

    /**
     * @param list<array{SchemaOperationKind, SchemaRisk, string, string, ?array, ?array, bool, string}> $specs
     * @return list<SchemaOperation>
     */
    private function number(array $specs): array
    {
        $operations = [];
        foreach (array_values($specs) as $offset => $spec) {
            $operations[] = new SchemaOperation($offset + 1, ...$spec);
        }

        return $operations;
    }

    private function withoutForeignKeys(PhysicalTableBlueprint $table): PhysicalTableBlueprint
    {
        return new PhysicalTableBlueprint(
            $table->logicalName,
            $table->physicalName,
            $table->kind,
            $table->columns(),
            $table->primaryKey,
            $table->indexes(),
            [],
            $table->options,
        );
    }

    /** @param list<SchemaOperation> $operations */
    private function containsPinnedRowBreakingChange(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (in_array(
                $operation->kind,
                [
                    SchemaOperationKind::DropTable,
                    SchemaOperationKind::DropColumn,
                    SchemaOperationKind::Transform,
                    SchemaOperationKind::RenameColumn,
                ],
                true,
            )) {
                return true;
            }
            if ($operation->kind === SchemaOperationKind::AlterColumn
                && !$this->additiveColumnRelaxation($operation)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<SchemaOperation> $operations */
    private function hasRecordRepin(array $operations, int $targetVersion): bool
    {
        $repin = false;
        foreach ($operations as $operation) {
            if (
                $operation->kind === SchemaOperationKind::RepinRecords
                && ($operation->after['definition_version'] ?? null) === $targetVersion
            ) {
                $repin = true;
            }
            if ($operation->kind === SchemaOperationKind::DropTable) {
                return false;
            }
            if ($operation->kind === SchemaOperationKind::DropColumn) {
                $transform = false;
                foreach ($operations as $candidate) {
                    if (
                        $candidate->kind === SchemaOperationKind::Transform
                        && $candidate->subject === $operation->subject . '.transform'
                    ) {
                        $transform = true;
                        break;
                    }
                }
                if (!$transform) {
                    return false;
                }
            }
        }

        return $repin;
    }

    private function additiveColumnRelaxation(SchemaOperation $operation): bool
    {
        if ($operation->before === null || $operation->after === null) {
            return false;
        }
        $before = PhysicalColumnBlueprint::fromArray($operation->before);
        $after = PhysicalColumnBlueprint::fromArray($operation->after);
        if ($before->logicalName !== $after->logicalName
            || $before->physicalName !== $after->physicalName
            || $before->doctrineType !== $after->doctrineType
            || ($before->nullable && !$after->nullable)) {
            return false;
        }
        $oldOptions = $before->options;
        $newOptions = $after->options;
        unset($oldOptions['default'], $newOptions['default']);
        $oldLength = $oldOptions['length'] ?? null;
        $newLength = $newOptions['length'] ?? null;
        if (is_int($oldLength) && (!is_int($newLength) || $newLength < $oldLength)) {
            return false;
        }
        $oldPrecision = $oldOptions['precision'] ?? null;
        $newPrecision = $newOptions['precision'] ?? null;
        if (is_int($oldPrecision) && (!is_int($newPrecision) || $newPrecision < $oldPrecision)) {
            return false;
        }
        unset($oldOptions['length'], $newOptions['length'], $oldOptions['precision'], $newOptions['precision']);

        return $oldOptions === $newOptions;
    }

    /** @return list<string> */
    private function dependencyHandles(EntityTypeDefinition $definition): array
    {
        $handles = [];
        foreach ($definition->relationships() as $relationship) {
            $handles[] = $relationship->target;
        }
        foreach ($definition->fields() as $field) {
            if (!in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (is_string($target)) {
                $handles[] = $target;
            }
        }
        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }

    /** @return list<PhysicalSchemaBlueprint> */
    private function dependencyBlueprints(EntityTypeDefinition $definition, SiteContext $site): array
    {
        $blueprints = [];
        $hints = SchemaEvolutionHints::fromDefinition($definition);
        foreach ($this->dependencyHandles($definition) as $handle) {
            $record = $this->definitions->published($site, $handle, $hints->repin($handle))
                ?? throw new BusinessSchemaNotFound($handle);
            $blueprints[] = $this->compiler->compile($record->definition, $site);
        }

        return $blueprints;
    }

    /** @return array{SchemaOperationKind, SchemaRisk, string, string, ?array, ?array, bool, string} */
    private function spec(
        SchemaOperationKind $kind,
        SchemaRisk $risk,
        string $table,
        string $subject,
        ?array $before,
        ?array $after,
        bool $requiresBackfill,
        string $recovery,
    ): array {
        return [$kind, $risk, $table, $subject, $before, $after, $requiresBackfill, $recovery];
    }

    /** @return array<string, PhysicalTableBlueprint> */
    private function tablesByLogical(PhysicalSchemaBlueprint $blueprint): array
    {
        $result = [];
        foreach ($blueprint->tables() as $table) {
            $result[$table->logicalName] = $table;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, PhysicalColumnBlueprint> */
    private function columnsByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->columns() as $column) {
            $result[$column->logicalName] = $column;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, PhysicalIndexBlueprint> */
    private function indexesByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->indexes() as $index) {
            $result[$index->logicalName] = $index;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, PhysicalForeignKeyBlueprint> */
    private function foreignKeysByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->foreignKeys() as $key) {
            $result[$key->logicalName] = $key;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function backfillValue(
        SchemaEvolutionHints $hints,
        string $logicalColumn,
    ): bool|int|string|Expression
    {
        if (!$hints->hasBackfill($logicalColumn)) {
            throw new InvalidBusinessSchema(sprintf(
                'Non-null column %s requires canonical compatibility_metadata.backfills data.',
                $logicalColumn,
            ));
        }
        $value = $hints->backfill($logicalColumn);
        if (!is_bool($value) && !is_int($value) && !is_string($value) && !$value instanceof Expression) {
            throw new InvalidBusinessSchema('A validated schema backfill value became unavailable.');
        }

        return $value;
    }

    /**
     * @param array<string, PhysicalColumnBlueprint> $availableColumns
     * @return array<string, mixed>
     */
    private function backfillState(
        PhysicalColumnBlueprint $column,
        bool|int|string|Expression $value,
        array $availableColumns,
    ): array {
        $state = ['column' => $column->toArray()];
        if (!$value instanceof Expression) {
            return [...$state, 'value' => $value];
        }
        $dependencies = [];
        foreach ($value->dependencies() as $logical) {
            $dependency = $availableColumns[$logical] ?? null;
            if ($dependency === null) {
                throw new InvalidBusinessSchema(
                    'A schema backfill Expression references a field unavailable in the source table.',
                );
            }
            $dependencies[$logical] = $dependency->toArray();
        }
        ksort($dependencies, SORT_STRING);

        return [
            ...$state,
            'expression' => $value->toArray(),
            'dependencies' => $dependencies,
        ];
    }

    private function transformShadowColumn(
        PhysicalTableBlueprint $table,
        PhysicalColumnBlueprint $target,
    ): PhysicalColumnBlueprint {
        $physical = 'x_' . substr(hash(
            'sha256',
            $table->physicalName . "\0" . $target->physicalName . "\0transform",
        ), 0, 40);

        return new PhysicalColumnBlueprint(
            $target->logicalName . '.transform',
            $physical,
            $target->doctrineType,
            $target->options,
            true,
        );
    }

    private function validateEvolutionHints(
        PhysicalSchemaBlueprint $prior,
        PhysicalSchemaBlueprint $target,
        EntityTypeDefinition $definition,
        SchemaEvolutionHints $hints,
    ): void {
        $dependencies = $this->dependencyHandles($definition);
        foreach ($hints->repins() as $handle => $version) {
            if ($handle === $definition->handle) {
                if ($version !== $definition->definitionVersion || $definition->definitionVersion < 2) {
                    throw new InvalidBusinessSchema(
                        'A self record repin must target the exact newly published definition version.',
                    );
                }
                continue;
            }
            if (!in_array($handle, $dependencies, true)) {
                throw new InvalidBusinessSchema('A schema repin targets an undeclared definition dependency.');
            }
        }
        foreach ($hints->renames() as $tableLogical => $renames) {
            $oldTable = $prior->table($tableLogical);
            $newTable = $target->table($tableLogical);
            if ($oldTable === null || $newTable === null) {
                throw new InvalidBusinessSchema('A schema rename targets a table unavailable in this evolution.');
            }
            foreach ($renames as $old => $new) {
                if ($oldTable->column($old) === null || $newTable->column($new) === null) {
                    throw new InvalidBusinessSchema('A schema rename does not match prior and target columns.');
                }
            }
        }
        $oldRecord = $prior->table('record');
        $newRecord = $target->table('record');
        foreach ($hints->transforms() as $logical => $_expression) {
            $old = $oldRecord?->column($logical);
            $new = $newRecord?->column($logical);
            if ($old === null || $new === null || $old->doctrineType === $new->doctrineType) {
                throw new InvalidBusinessSchema(
                    'A schema transform must correspond to one explicit record-column type change.',
                );
            }
        }
        foreach ($hints->backfills() as $logical => $_literal) {
            $old = $oldRecord?->column($logical);
            $new = $newRecord?->column($logical);
            if ($new === null || $new->nullable || ($old !== null && !$old->nullable)) {
                throw new InvalidBusinessSchema(
                    'A schema backfill must correspond to one added or newly non-null record column.',
                );
            }
        }
    }
}
