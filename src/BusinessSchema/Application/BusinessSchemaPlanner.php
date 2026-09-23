<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\BusinessSchema\Domain\SchemaOperation;
use Kumwe\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\BusinessSchema\Domain\SchemaPlan;
use Kumwe\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\BusinessSchema\Domain\SchemaRisk;
use Kumwe\BusinessSchema\Planner\SchemaChangePlanner;
use Kumwe\Access\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Compiles what a published business definition would change about its physical schema, and writes it down.
 *
 * This is the only producer of `SchemaPlan` records, and it never touches the live database. It compiles the
 * published version into the blueprint that version installs, diffs that against the blueprint recorded as
 * installed, turns the difference into risk-classified `SchemaOperation` steps, and stores the plan together
 * with one pending journal row per step. The diff itself is the package `SchemaChangePlanner`, a pure
 * function of the two blueprints and the definition; what stays here is everything that needs authority
 * or storage around it. Applying any of it is a separate act that `BusinessSchemaExecutor` performs only
 * once an operator has approved the exact checksum produced here.
 *
 * Two entry points share that derivation: `plan()` answers an operator asking for one definition's install
 * or upgrade, and `observePublishedGraph()` is the seam `BusinessDefinitionService` calls from inside its
 * own publication transaction, so definitions published together are planned together. Both are idempotent
 * by content — a plan whose checksum equals the latest one already on record is returned instead of a
 * second one, so a repeated publication leaves the approval queue unchanged — and both refuse a change
 * that would break rows still pinned to an older definition version unless the definition declares the
 * bounded re-pin that carries those rows across. `purgePlan()` stands apart: it composes the destructive
 * teardown of everything a definition installed, which is never folded into an upgrade.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaPlanner implements PublishedDefinitionSchemaObserver
{
    /**
     * Sentinel target-schema checksum a purge plan names, since a purge arrives at no blueprint at all.
     *
     * Every plan has to declare the physical schema it ends at, and a completed purge ends with the
     * definition's tables gone, which no `PhysicalSchemaBlueprint` can describe. `BusinessSchemaExecutor`
     * therefore recognises a plan as a purge by comparing its `targetSchemaChecksum` against this value,
     * and reports it as the schema the run arrived at.
     *
     * @var    string
     * @since  2.0.0
     */
    public const PURGED_SCHEMA_CHECKSUM = 'c6cb2eb24aa57518c4c1639014771aa76c3fe4c909b1ff9c18ba7eda35c35e93';

    /**
     * Wire the catalog, compiler, stores, and gateways one planning run reads from and writes to.
     *
     * @param  BusinessDefinitionRepository          $definitions     Catalog the published versions are read from.
     * @param  DefinitionPhysicalSchemaCompiler      $compiler        Turns a version into the tables it installs.
     * @param  SchemaChangePlanner                   $planner         Diffs two blueprints into ordered operations.
     * @param  BusinessSchemaInstallationRepository  $installations   Records what each definition has installed.
     * @param  BusinessSchemaPlanRepository          $plans           Store the plan and its journal are written to.
     * @param  PhysicalSchemaGateway                 $physicalSchema  Live database, asked about drift and pinned rows.
     * @param  AuthorizationGateway                  $authorization   Guards the two operator-facing entry points.
     * @param  AuditRecorder                         $audit           Trail the operator-facing plans are recorded in.
     * @param  TransactionManager                    $transactions    Scope a whole planning run is persisted in.
     * @param  ClockInterface                        $clock           Source of the instant plans are stamped with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private SchemaChangePlanner $planner,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaPlanRepository $plans,
        private PhysicalSchemaGateway $physicalSchema,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Propose the plan that takes one definition's tables to the version its site currently publishes.
     *
     * The target version is read from the catalog rather than named by the caller, so an operator cannot
     * plan against a version that is not live. Everything is written inside one transaction and audited as
     * `business.schema.plan`; no DDL runs and no row is rewritten here.
     *
     * @param   ExecutionContext  $context       Actor and site the plan is authorized, scoped, and credited to.
     * @param   string            $definitionId  UUID or handle of the definition whose published version to plan.
     *
     * @return  SchemaPlan  A plan awaiting approval, or the identical plan already on record for it.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not exercise
     *          `business.schema.plan` over the business-schema collection.
     * @throws  BusinessSchemaNotFound  When this site publishes no definition under that identifier, or a
     *          handle the definition references resolves to no published version.
     * @throws  BusinessSchemaConflict  When the installed schema belongs to another site, is not older than
     *          the published version, no longer matches the blueprint recorded for it, or an identical plan
     *          is inserted concurrently.
     * @throws  InvalidBusinessSchema  When the definition crosses site scope, its evolution hints do not
     *          describe this evolution, or a narrowing change would reach rows pinned to an older version.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the derived plan holds
     *          more than 512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
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

    /**
     * Propose the destructive plan that drops every table one definition has installed.
     *
     * Purging is never a side effect of an upgrade or an uninstall: it is asked for on its own, guarded by
     * `business.schema.destructive` here as well as at approval, and audited as
     * `business.schema.destructive.plan`. The drops are ordered so that `record` goes last and the
     * remaining tables descend by logical name, which retires the tables referencing `record` before the
     * table they point at. Unlike `plan()` this does not reuse an existing plan, so proposing the same
     * purge twice collides on the plan store's uniqueness rule instead.
     *
     * @param   ExecutionContext  $context       Actor and site the purge is authorized, scoped, and credited to.
     * @param   string            $definitionId  UUID of the definition whose installed tables are to be dropped.
     *
     * @return  SchemaPlan  A destructive plan awaiting its own approval and recovery evidence.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not exercise
     *          `business.schema.destructive` over the business-schema collection.
     * @throws  BusinessSchemaNotFound  When this site has nothing installed under that identifier, when the
     *          installation belongs to another site, or when the definition is no longer published.
     * @throws  BusinessSchemaConflict  When an identical purge plan is already stored.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
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
            $operations = [];
            foreach ($tables as $ordinal => $table) {
                $operations[] = new SchemaOperation(
                    $ordinal + 1,
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

    /**
     * Plan every definition of a freshly published graph inside the publisher's own transaction.
     *
     * The whole graph is taken at once because definitions published together reference one another: each
     * one is compiled up front and keyed by handle, so a sibling's blueprint is reused rather than reloaded
     * when it turns up as a dependency, and only handles outside the graph are read back from the catalog —
     * at their currently published version, since a graph is planned as it was published. Definitions are
     * then processed in handle order, which fixes the order the plans come back in. This entry point
     * authorizes nothing and records no audit event of its own; the publication it accompanies has already
     * done both, and its transaction is what discards these plans if that publication fails.
     *
     * @param   SiteContext                    $site             Site every published definition belongs to.
     * @param   list<DefinitionVersionRecord>  $definitions      Versions published in this graph; 1 to 128 of them.
     * @param   string                         $actorIdentifier  Actor credited as the author of the plans.
     * @param   DateTimeImmutable              $now              Instant the plans and journal rows are stamped with.
     *
     * @return  list<SchemaPlan>  One plan per supplied definition in handle order; an unchanged definition
     *          yields the plan already on record rather than a new one.
     *
     * @throws  InvalidBusinessSchema  When the graph is empty or holds more than 128 definitions, one of
     *          them belongs to another site, its evolution hints do not describe this evolution, or a
     *          narrowing change would reach rows pinned to an older version.
     * @throws  BusinessSchemaNotFound  When a handle one definition references is published nowhere on this
     *          site.
     * @throws  BusinessSchemaConflict  When an installed schema belongs to another site, is not older than
     *          the version being published, no longer matches the blueprint recorded for it, or an
     *          identical plan is inserted concurrently.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When a derived plan holds
     *          more than 512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
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
                foreach ($this->planner->dependencyHandles($record->definition) as $handle) {
                    $dependencies[$handle] = $byHandle[$handle]
                        ?? $this->compiler->compile(
                            $this->definitions->published($site, $handle)->definition
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

    /**
     * Derive one definition's plan, refuse it if the ground has moved, and store it with its journal.
     *
     * Every entry point funnels through here, which is where the guards that make a plan trustworthy live.
     * The definition may not cross site scope; an installation recorded for another site, or one that is
     * not older than the version being published, is a conflict; and the live schema is re-inspected so a
     * plan is never derived from a blueprint the database no longer matches. Two deduplication paths make
     * replanning harmless — republishing the exact version already installed returns its existing plan, and
     * a freshly derived plan whose checksum matches the latest one on record is dropped in favour of it.
     * Only after both is the plan written, followed by one pending journal row per operation.
     *
     * @param   SiteContext                             $site                  Site the definition must belong to.
     * @param   DefinitionVersionRecord                 $record                Published version the schema is moved to.
     * @param   string                                  $actorIdentifier       Actor credited as the author of the plan.
     * @param   DateTimeImmutable                       $now                   Instant the plan and its journal carry.
     * @param   bool                                    $audit                 Whether to record the audit event;
     *          false where the caller already audits the operation these plans accompany.
     * @param   array<string, PhysicalSchemaBlueprint>  $dependencyBlueprints  Compiled schemas of the
     *          definitions this one references, keyed by handle in handle order.
     *
     * @return  SchemaPlan  The stored plan, or the equivalent one that was already on record.
     *
     * @throws  InvalidBusinessSchema  When the definition belongs to another site, its evolution hints do
     *          not describe this evolution, or a narrowing change would reach rows pinned to an older
     *          definition version without a bounded re-pin to carry them across.
     * @throws  BusinessSchemaConflict  When the installed metadata belongs to another site, the installed
     *          version is not older than the published one, the live schema has drifted from that metadata,
     *          or an identical plan is inserted concurrently.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
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
        $operations = $this->planner->operations($prior, $target, $definition, $dependencyBlueprints);
        if (
            $prior !== null && $this->planner->containsPinnedRowBreakingChange($operations)
            && !$this->planner->hasRecordRepin($operations, $definition->definitionVersion)
            && $this->physicalSchema->hasRowsPinnedBefore($prior, $definition->definitionVersion)
        ) {
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
     * Compile the physical schema of every definition this one references.
     *
     * A handle the definition re-pins is resolved at exactly the version that re-pin names, so the
     * dependency is read as the plan intends to leave it; every other handle is taken at whatever version
     * the site publishes now. A referenced handle that resolves to nothing stops planning rather than
     * yielding a plan derived from a partial graph.
     *
     * @param   EntityTypeDefinition  $definition  Definition version whose references are being resolved.
     * @param   SiteContext           $site        Site the referenced definitions must be published on.
     *
     * @return  array<string, PhysicalSchemaBlueprint>  One blueprint per referenced handle, keyed by handle
     *          in handle order; empty when the definition references none.
     *
     * @throws  BusinessSchemaNotFound  When a referenced handle has no published version on this site, or
     *          none at the version a re-pin names.
     * @throws  InvalidBusinessSchema  When the definition's compatibility metadata is malformed, a
     *          referenced handle is not a namespaced definition handle, or a dependency fails to compile.
     *
     * @since   2.0.0
     */
    private function dependencyBlueprints(EntityTypeDefinition $definition, SiteContext $site): array
    {
        $blueprints = [];
        $hints = SchemaEvolutionHints::fromDefinition($definition);
        foreach ($this->planner->dependencyHandles($definition) as $handle) {
            $record = $this->definitions->published($site, $handle, $hints->repin($handle))
                ?? throw new BusinessSchemaNotFound($handle);
            $blueprints[$handle] = $this->compiler->compile($record->definition, $site);
        }

        return $blueprints;
    }
}
