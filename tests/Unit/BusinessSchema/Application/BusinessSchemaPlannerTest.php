<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanner;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\PortableDefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\PublishedDefinitionSchemaLookup;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Audit\Domain\AuditEvent;
use Kumwe\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Compiler\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\BusinessSchema\Domain\SchemaOperation;
use Kumwe\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\BusinessSchema\Domain\SchemaPlan;
use Kumwe\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\BusinessSchema\Domain\SchemaRisk;
use Kumwe\BusinessSchema\Planner\SchemaChangePlanner;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves the App planner composes the package compiler and planner around authority, catalog and storage.
 *
 * The diff between two blueprints belongs to `Kumwe\BusinessSchema\Planner\SchemaChangePlanner` and is not
 * re-proven here. What the host owes around it is exercised through fakes of the planner's own ports: a purge
 * proposes one drop per installed table with `record` retired last; a plan resolves every handle the package
 * planner names as a dependency through the catalog and compiles it, or stops on the first one the site does
 * not publish; a published graph reuses the blueprints of its siblings and reads only outside handles from the
 * catalog; and an upgrade over an installed blueprint is refused while rows pinned to the older version would
 * be broken by a narrowing operation.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSchemaPlanner::class)]
final class BusinessSchemaPlannerTest extends TestCase
{
    /**
     * Definition identity of the purged fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PURGE_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245c01';

    /**
     * Definition identity of the reference target fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TARGET_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245c02';

    /**
     * Definition identity of the reference owner fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const OWNER_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245c03';

    /**
     * Definition identity of the fixture planned against an installed prior blueprint.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PRIOR_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245c04';

    /**
     * Instant every plan and journal row is stamped with.
     *
     * @var    string
     * @since  2.0.0
     */
    private const NOW = '2026-03-01T08:00:00+00:00';

    /**
     * A purge proposes one destructive drop per installed table, non-record tables descending, `record` last.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPurgePlanDropsEveryInstalledTableWithRecordLast(): void
    {
        $definition = EntityTypeDefinition::fromArray(
            NeutralBusinessFixture::document('purge', self::PURGE_ID),
        )->published(1);
        $installedTables = [
            self::table('record', 'kb_purge_record'),
            self::table('alpha', 'kb_purge_alpha'),
            self::table('beta', 'kb_purge_beta'),
        ];
        $installed = $this->installation(
            $definition,
            new PhysicalSchemaBlueprint(self::PURGE_ID, 1, $definition->checksum(), $installedTables),
        );
        $catalog = $this->catalog([$this->record($definition)]);
        $saved = ['plans' => [], 'steps' => []];
        $audited = [];
        $planner = $this->planner($catalog, $this->compiler($catalog), $installed, false, $saved, $audited);

        $plan = $planner->purgePlan(AuthorizationContext::human(['business.schema.destructive']), self::PURGE_ID);

        $operations = $plan->operations();
        self::assertSame(
            [[1, 'beta'], [2, 'alpha'], [3, 'record']],
            array_map(
                static fn (SchemaOperation $operation): array => [$operation->ordinal, $operation->table],
                $operations,
            ),
        );
        foreach ($operations as $operation) {
            self::assertSame(SchemaOperationKind::DropTable, $operation->kind);
            self::assertSame(SchemaRisk::Destructive, $operation->risk);
            self::assertSame($operation->table, $operation->subject);
            self::assertSame(
                self::table($operation->table, 'kb_purge_' . $operation->table)->toArray(),
                $operation->before,
            );
            self::assertNull($operation->after);
            self::assertFalse($operation->requiresBackfill);
            self::assertSame('restore_required', $operation->recoveryImplication);
        }
        self::assertSame(self::PURGE_ID, $plan->definitionId);
        self::assertNull($plan->fromDefinitionVersion);
        self::assertSame(1, $plan->toDefinitionVersion);
        self::assertSame($installed->schemaChecksum, $plan->fromSchemaChecksum);
        self::assertSame(BusinessSchemaPlanner::PURGED_SCHEMA_CHECKSUM, $plan->targetSchemaChecksum);
        self::assertSame(SchemaRisk::Destructive, $plan->risk);
        self::assertSame(SchemaPlanStatus::PendingApproval, $plan->status);
        self::assertSame([$plan], $saved['plans']);
        self::assertSame(
            [1, 2, 3],
            array_map(static fn (SchemaPlanStep $step): int => $step->ordinal, $saved['steps']),
        );
        self::assertCount(1, $audited);
        self::assertSame('business.schema.destructive.plan', $audited[0]->action());
        self::assertSame($plan->id, $audited[0]->subjectId());
        self::assertSame(3, $audited[0]->metadata()['operation_count']);
        self::assertSame($plan->checksum(), $audited[0]->metadata()['plan_checksum']);
    }

    /**
     * A plan compiles every handle the definition references, and stops on one the site does not publish.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanCompilesEachReferencedDefinitionAndRefusesAMissingOne(): void
    {
        [$target, $owner] = $this->referenceGraph();
        $complete = $this->catalog([$this->record($target), $this->record($owner)]);
        $compiled = [];
        $saved = ['plans' => [], 'steps' => []];
        $audited = [];
        $planner = $this->planner(
            $complete,
            $this->compiler($complete, $compiled),
            null,
            false,
            $saved,
            $audited,
        );

        $plan = $planner->plan(AuthorizationContext::human(['business.schema.plan']), self::OWNER_ID);

        self::assertSame([$target->handle, $owner->handle], $compiled);
        self::assertSame(self::OWNER_ID, $plan->definitionId);
        self::assertNull($plan->fromDefinitionVersion);
        self::assertSame(1, $plan->toDefinitionVersion);
        self::assertSame($owner->checksum(), $plan->toDefinitionChecksum);
        self::assertSame(SchemaOperationKind::CreateTable, $plan->operations()[0]->kind);
        self::assertSame('record', $plan->operations()[0]->table);
        self::assertSame([$plan], $saved['plans']);
        self::assertCount(count($plan->operations()), $saved['steps']);
        self::assertCount(1, $audited);
        self::assertSame('business.schema.plan', $audited[0]->action());
        self::assertSame(1, $audited[0]->metadata()['target_version']);

        $compiled = [];
        $saved = ['plans' => [], 'steps' => []];
        $audited = [];
        $partial = $this->catalog([$this->record($owner)]);
        $planner = $this->planner(
            $partial,
            $this->compiler($complete, $compiled),
            null,
            false,
            $saved,
            $audited,
        );

        try {
            $planner->plan(AuthorizationContext::human(['business.schema.plan']), self::OWNER_ID);
            self::fail('A referenced handle the site does not publish must stop planning.');
        } catch (BusinessSchemaNotFound $exception) {
            self::assertStringContainsString($target->handle, $exception->getMessage());
        }
        self::assertSame([], $compiled, 'Nothing is compiled once a dependency is missing.');
        self::assertSame([], $saved['plans']);
        self::assertSame([], $audited);
    }

    /**
     * A published graph reuses sibling blueprints and reads only handles outside the graph from the catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testObservePublishedGraphReusesSiblingsAndReadsOutsideDependenciesFromTheCatalog(): void
    {
        [$target, $owner] = $this->referenceGraph();
        $complete = $this->catalog([$this->record($target), $this->record($owner)]);
        $site = SiteContext::default();
        $now = new DateTimeImmutable(self::NOW);
        $lookups = [];
        $compiled = [];
        $saved = ['plans' => [], 'steps' => []];
        $audited = [];
        $planner = $this->planner(
            $this->catalog([$this->record($target), $this->record($owner)], $lookups),
            $this->compiler($complete, $compiled),
            null,
            false,
            $saved,
            $audited,
        );

        $plans = $planner->observePublishedGraph(
            $site,
            [$this->record($target), $this->record($owner)],
            AuthorizationContext::SUBJECT,
            $now,
        );

        self::assertSame(
            [self::OWNER_ID, self::TARGET_ID],
            array_map(static fn (SchemaPlan $plan): string => $plan->definitionId, $plans),
            'Plans come back in handle order.',
        );
        self::assertSame([], $lookups, 'A sibling published in the same graph is never read back from the catalog.');
        self::assertSame(
            [$owner->handle, $target->handle, $owner->handle, $target->handle],
            $compiled,
            'Each graph member is compiled up front for its siblings, then once more as its own plan target.',
        );
        self::assertSame($plans, $saved['plans']);
        self::assertSame([], $audited, 'The publication that carries the graph already audits it.');

        $lookups = [];
        $compiled = [];
        $saved = ['plans' => [], 'steps' => []];
        $planner = $this->planner(
            $this->catalog([$this->record($target), $this->record($owner)], $lookups),
            $this->compiler($complete, $compiled),
            null,
            false,
            $saved,
            $audited,
        );

        $plans = $planner->observePublishedGraph($site, [$this->record($owner)], AuthorizationContext::SUBJECT, $now);

        self::assertCount(1, $plans);
        self::assertSame(self::OWNER_ID, $plans[0]->definitionId);
        self::assertSame([['default', $target->handle, null]], $lookups);
        self::assertSame(
            [$owner->handle, $target->handle, $owner->handle],
            $compiled,
            'A handle outside the graph is read from the catalog and compiled as a dependency.',
        );

        $planner = $this->planner(
            $this->catalog([$this->record($owner)]),
            $this->compiler($complete),
            null,
            false,
            $saved,
            $audited,
        );

        $this->expectException(BusinessSchemaNotFound::class);
        $this->expectExceptionMessage($target->handle);
        $planner->observePublishedGraph($site, [$this->record($owner)], AuthorizationContext::SUBJECT, $now);
    }

    /**
     * An upgrade over an installed blueprint is refused while a narrowing change would reach pinned rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanAgainstAnInstalledBlueprintRefusesANarrowingChangeWhileRowsRemainPinned(): void
    {
        $priorDocument = NeutralBusinessFixture::document('prior', self::PRIOR_ID);
        $priorDocument['fields'][] = [
            'handle' => 'legacy_code',
            'label' => 'Legacy code',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 40,
        ];
        $prior = EntityTypeDefinition::fromArray($priorDocument)->published(1);
        $published = EntityTypeDefinition::fromArray(
            NeutralBusinessFixture::document('prior', self::PRIOR_ID),
        )->published(2);
        $catalog = $this->catalog([$this->record($published, $prior)]);
        $compiler = $this->compiler($catalog);
        $installed = $this->installation($prior, $compiler->compile($prior, SiteContext::default()));
        $context = AuthorizationContext::human(['business.schema.plan']);
        $saved = ['plans' => [], 'steps' => []];
        $audited = [];
        $pinned = $this->planner($catalog, $compiler, $installed, true, $saved, $audited);

        try {
            $pinned->plan($context, self::PRIOR_ID);
            self::fail('A drop that reaches rows pinned to the older version must be refused.');
        } catch (InvalidBusinessSchema $exception) {
            self::assertSame(
                'Older definition-version rows remain pinned; drop/type replacement requires a bounded re-pin plan.',
                $exception->getMessage(),
            );
        }
        self::assertSame([], $saved['plans']);
        self::assertSame([], $audited);

        $released = $this->planner($catalog, $compiler, $installed, false, $saved, $audited);

        $plan = $released->plan($context, self::PRIOR_ID);

        self::assertSame(1, $plan->fromDefinitionVersion);
        self::assertSame(2, $plan->toDefinitionVersion);
        self::assertSame($prior->checksum(), $plan->fromDefinitionChecksum);
        self::assertSame($installed->schemaChecksum, $plan->fromSchemaChecksum);
        self::assertSame(
            [[SchemaOperationKind::DropColumn, 'record', 'legacy_code']],
            array_map(
                static fn (SchemaOperation $operation): array => [
                    $operation->kind,
                    $operation->table,
                    $operation->subject,
                ],
                $plan->operations(),
            ),
        );
        self::assertSame(SchemaRisk::Destructive, $plan->risk);
        self::assertSame([$plan], $saved['plans']);
        self::assertCount(1, $audited);
        self::assertSame(2, $audited[0]->metadata()['target_version']);
    }

    /**
     * Publish the reference target and the owner whose `core.entity_reference` field points at it.
     *
     * @return  array{0: EntityTypeDefinition, 1: EntityTypeDefinition}  Target first, then owner.
     *
     * @since   2.0.0
     */
    private function referenceGraph(): array
    {
        $target = EntityTypeDefinition::fromArray(
            NeutralBusinessFixture::referenceTargetDocument('cov', self::TARGET_ID),
        )->published(1);
        $owner = EntityTypeDefinition::fromArray(
            NeutralBusinessFixture::entityReferenceOwnerDocument('cov', self::OWNER_ID, $target->handle),
        )->published(1);

        return [$target, $owner];
    }

    /**
     * Wrap a published definition in the catalog record the planner reads.
     *
     * @param   EntityTypeDefinition   $definition  Published version being recorded.
     * @param   ?EntityTypeDefinition  $previous    Version it succeeds, when it is an upgrade.
     *
     * @return  DefinitionVersionRecord  Record whose compatibility plan agrees with the definition.
     *
     * @since   2.0.0
     */
    private function record(
        EntityTypeDefinition $definition,
        ?EntityTypeDefinition $previous = null,
    ): DefinitionVersionRecord {
        return new DefinitionVersionRecord(
            $definition,
            new CompatibilityPlan(
                $previous?->definitionVersion,
                $definition->definitionVersion,
                $previous?->checksum(),
                $definition->checksum(),
                [],
            ),
            DefinitionStatus::Published,
            AuthorizationContext::SUBJECT,
            new DateTimeImmutable(self::NOW),
        );
    }

    /**
     * Build a catalog that publishes exactly the given records under their identifiers and handles.
     *
     * @param   list<DefinitionVersionRecord>            $records  Published versions the catalog serves.
     * @param   list<array{0: string, 1: string, 2: ?int}>  $lookups  Receives every `published()` call.
     *
     * @return  BusinessDefinitionRepository  Catalog fake answering `published()` only.
     *
     * @since   2.0.0
     */
    private function catalog(array $records, array &$lookups = []): BusinessDefinitionRepository
    {
        $catalog = $this->createStub(BusinessDefinitionRepository::class);
        $catalog->method('published')->willReturnCallback(
            static function (
                SiteContext $site,
                string $identifier,
                ?int $version = null,
            ) use (
                $records,
                &$lookups,
            ): ?DefinitionVersionRecord {
                $lookups[] = [$site->identifier(), $identifier, $version];
                foreach ($records as $record) {
                    if (
                        in_array($identifier, [$record->definition->id, $record->definition->handle], true)
                        && ($version === null || $version === $record->definition->definitionVersion)
                    ) {
                        return $record;
                    }
                }

                return null;
            },
        );

        return $catalog;
    }

    /**
     * Build the compiler port over the package compiler, recording the handle of every compiled definition.
     *
     * @param   BusinessDefinitionRepository  $catalog   Catalog the package compiler resolves targets through.
     * @param   list<string>                  $compiled  Receives each compiled definition handle in order.
     *
     * @return  DefinitionPhysicalSchemaCompiler  Recording compiler port.
     *
     * @since   2.0.0
     */
    private function compiler(
        BusinessDefinitionRepository $catalog,
        array &$compiled = [],
    ): DefinitionPhysicalSchemaCompiler {
        $portable = new PortableDefinitionPhysicalSchemaCompiler(new CanonicalDefinitionPhysicalSchemaCompiler(
            new PublishedDefinitionSchemaLookup($catalog),
            new FieldTypeRegistry(),
            new PhysicalNameCompiler('kumwe_'),
        ));
        $compiler = $this->createStub(DefinitionPhysicalSchemaCompiler::class);
        $compiler->method('compile')->willReturnCallback(
            static function (
                EntityTypeDefinition $definition,
                SiteContext $site,
            ) use (
                $portable,
                &$compiled,
            ): PhysicalSchemaBlueprint {
                $compiled[] = $definition->handle;

                return $portable->compile($definition, $site);
            },
        );

        return $compiler;
    }

    /**
     * Record one definition version as installed on the default site under the given blueprint.
     *
     * @param   EntityTypeDefinition    $definition  Installed version.
     * @param   PhysicalSchemaBlueprint  $blueprint   Blueprint recorded as installed for it.
     *
     * @return  SchemaInstallation  Active installation metadata.
     *
     * @since   2.0.0
     */
    private function installation(
        EntityTypeDefinition $definition,
        PhysicalSchemaBlueprint $blueprint,
    ): SchemaInstallation {
        $now = new DateTimeImmutable(self::NOW);

        return new SchemaInstallation(
            $definition->id,
            'default',
            'core',
            $definition->definitionVersion,
            $definition->checksum(),
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $now,
            $now,
        );
    }

    /**
     * Wire the planner over its ports, capturing what it stores and audits.
     *
     * @param   BusinessDefinitionRepository       $catalog     Published versions the planner reads.
     * @param   DefinitionPhysicalSchemaCompiler   $compiler    Compiler port for target and dependency blueprints.
     * @param   ?SchemaInstallation                $installed   Installation `find()` answers with, or null.
     * @param   bool                               $pinnedRows  Whether rows remain pinned before the version.
     * @param   array{plans: list<SchemaPlan>, steps: list<SchemaPlanStep>}  $saved  Receives saved plans and steps.
     * @param   list<AuditEvent>                   $audited     Receives every recorded audit event.
     *
     * @return  BusinessSchemaPlanner  Planner under test.
     *
     * @since   2.0.0
     */
    private function planner(
        BusinessDefinitionRepository $catalog,
        DefinitionPhysicalSchemaCompiler $compiler,
        ?SchemaInstallation $installed,
        bool $pinnedRows,
        array &$saved,
        array &$audited,
    ): BusinessSchemaPlanner {
        $installations = $this->createStub(BusinessSchemaInstallationRepository::class);
        $installations->method('find')->willReturnCallback(
            static fn (string $definitionId): ?SchemaInstallation =>
                $installed?->definitionId === $definitionId ? $installed : null,
        );
        $plans = $this->createStub(BusinessSchemaPlanRepository::class);
        $plans->method('latestForDefinition')->willReturn(null);
        $plans->method('save')->willReturnCallback(static function (SchemaPlan $plan) use (&$saved): void {
            $saved['plans'][] = $plan;
        });
        $plans->method('saveStep')->willReturnCallback(static function (SchemaPlanStep $step) use (&$saved): void {
            $saved['steps'][] = $step;
        });
        $physical = $this->createStub(PhysicalSchemaGateway::class);
        $physical->method('inspect')->willReturnCallback(
            static fn (PhysicalSchemaBlueprint $expected): PhysicalSchemaBlueprint => $expected,
        );
        $physical->method('hasRowsPinnedBefore')->willReturn($pinnedRows);
        $audit = $this->createStub(AuditRecorder::class);
        $audit->method('record')->willReturnCallback(static function (AuditEvent $event) use (&$audited): void {
            $audited[] = $event;
        });
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        return new BusinessSchemaPlanner(
            $catalog,
            $compiler,
            new SchemaChangePlanner(),
            $installations,
            $plans,
            $physical,
            $this->createStub(AuthorizationGateway::class),
            $audit,
            $transactions,
            $clock,
        );
    }

    /**
     * Build one minimal installed table carrying only its record key.
     *
     * @param   string  $logical   Logical table name.
     * @param   string  $physical  Physical table name.
     *
     * @return  PhysicalTableBlueprint  Entity table keyed by `c_record_key`.
     *
     * @since   2.0.0
     */
    private static function table(string $logical, string $physical): PhysicalTableBlueprint
    {
        return new PhysicalTableBlueprint(
            $logical,
            $physical,
            PhysicalTableKind::Entity,
            [new PhysicalColumnBlueprint('record_key', 'c_record_key', 'guid')],
            ['c_record_key'],
        );
    }
}
