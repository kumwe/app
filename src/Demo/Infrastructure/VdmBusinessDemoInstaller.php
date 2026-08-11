<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\Demo\Application\DemoProfileLedger;
use Kumwe\CMS\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Installs the VDM delivery example through definition, schema, record, relation, and workflow services.
 *
 * Definitions and generated records use the same application paths as administrator, REST, CLI, and MCP
 * requests, producing ordinary schema plans, revisions, audit entries, outbox events, and idempotency rows.
 * The only specialized persistence is the initial row/field policy bootstrap: Business Security correctly
 * requires a human step-up proof for its public administration service, so this purpose-bound installer
 * writes a closed constant-true policy document derived from immutable definition field ceilings and audits
 * it in the same transaction. No password, token, MFA material, or encryption key is shipped.
 *
 * @since  2.0.0
 */
final readonly class VdmBusinessDemoInstaller
{
    /**
     * Independent business-demo dataset key.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DATASET = 'business-demo';

    /**
     * Business-record operations made available to actors who separately hold the matching capability.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array RECORD_OPERATIONS = [
        'business.record.action',
        'business.record.archive',
        'business.record.browse',
        'business.record.create',
        'business.record.delete',
        'business.record.export',
        'business.record.history',
        'business.record.read',
        'business.record.relate',
        'business.record.report',
        'business.record.restore',
        'business.record.transition',
        'business.record.update',
    ];

    /**
     * Bind every canonical runtime and the narrow policy-bootstrap dependencies.
     *
     * @param  BusinessDefinitionService       $definitions   Definition draft and publication service.
     * @param  BusinessSchemaService           $schemas       Persisted plan, approval, and execution service.
     * @param  BusinessRecordService           $records       Transactional record application service.
     * @param  VdmBusinessManifestProjector     $projector     Pure default-template site projection.
     * @param  DemoProfileLedger               $ledger        Stable profile provenance and restart state.
     * @param  Connection                      $database      Policy catalog connection.
     * @param  TableNames                      $tables        Validated physical table compiler.
     * @param  TransactionManager              $transactions Policy, ownership, and audit transaction boundary.
     * @param  AuditRecorder                   $audit         Durable policy-bootstrap audit sink.
     * @param  ClockInterface                  $clock         Trusted timestamp source.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessSchemaService $schemas,
        private BusinessRecordService $records,
        private VdmBusinessManifestProjector $projector,
        private DemoProfileLedger $ledger,
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Apply the complete aggregate VDM manifest and return concise operator diagnostics.
     *
     * @param   ExecutionContext      $context   Purpose-bound profile-installer context.
     * @param   array<string, mixed>  $manifest  Profile plus embedded definition and record documents.
     *
     * @return  list<string>  Installed definition and record summaries.
     *
     * @since   2.0.0
     */
    public function install(ExecutionContext $context, array $manifest): array
    {
        $manifest = $this->projector->forSite($manifest, $context->site());
        $documents = $this->requiredMap($manifest, 'definition_documents');
        $order = $this->requiredList($manifest, 'installation_order', 64);
        $installed = [];
        $messages = [];
        foreach ($order as $entry) {
            $entry = $this->map($entry, 'definition installation entry');
            $fixtureKey = $this->requiredString($entry, 'fixture_key');
            $document = $this->map($documents[$fixtureKey] ?? null, 'definition document');
            $definition = $this->installDefinition($context, $fixtureKey, $document);
            $this->installSchema($context, $definition);
            $installed[$definition->handle] = $definition;
            $messages[] = sprintf('Prepared VDM business definition %s.', $definition->handle);
        }
        $this->transactions->transactional(function () use ($context, $installed): void {
            $createdPolicies = false;
            foreach ($installed as $definition) {
                $createdPolicies = $this->installRecordPolicies($context, $definition) || $createdPolicies;
            }
            if ($createdPolicies) {
                $this->database->executeStatement(sprintf(
                    'UPDATE %s SET policy_generation = policy_generation + 1 WHERE identifier = ?',
                    $this->tables->quoted('sites'),
                ), [$context->site()->identifier()]);
            }
        });

        $records = $this->requiredMap($manifest, 'records_document');
        $versions = $this->createRecords($context, $this->requiredList($records, 'records', 512));
        $this->relateRecords($context, $this->requiredList($records, 'relations', 1_024), $versions);
        $this->executeActions($context, $this->requiredList($records, 'actions', 1_024), $versions);
        $this->archiveRecords($context, $this->requiredList($records, 'archives', 512), $versions);
        $messages[] = sprintf('Reconciled %d VDM business records and their example workflows.', count($versions));

        return $messages;
    }

    /**
     * Import and publish one definition, updating it only while the prior demo version remains untouched.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   string                $fixtureKey  Stable definition fixture key.
     * @param   array<string, mixed>  $document    Version-zero site-owned definition document.
     *
     * @return  EntityTypeDefinition  Published definition that now governs generated storage.
     *
     * @since   2.0.0
     */
    private function installDefinition(
        ExecutionContext $context,
        string $fixtureKey,
        array $document,
    ): EntityTypeDefinition {
        $draftDefinition = EntityTypeDefinition::fromArray($document);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $published = null;
        try {
            $published = $this->definitions->published($context, $draftDefinition->handle)->definition;
        } catch (BusinessDefinitionNotFound) {
        }
        if ($published !== null) {
            $desired = $draftDefinition->published($published->definitionVersion);
            if ($published->checksum() === $desired->checksum()) {
                $this->recordDefinitionAsset($context, $fixtureKey, $published);

                return $published;
            }
            if (($asset['last_applied_checksum'] ?? null) !== $published->checksum()) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s was customized; refusing to overwrite it during demo reconciliation.',
                    $draftDefinition->handle,
                ));
            }
        }

        $expectedRevision = null;
        try {
            $draft = $this->definitions->draft($context, $draftDefinition->handle);
            $expectedRevision = $draft->revision;
        } catch (BusinessDefinitionNotFound) {
        }
        $draft = $this->definitions->importDraft($context, $document, $expectedRevision);
        $published = $this->definitions->publish($context, $draft->definition->id, $draft->revision, true)->definition;
        $this->recordDefinitionAsset($context, $fixtureKey, $published);

        return $published;
    }

    /**
     * Persist the current published definition as the profile's immutable divergence baseline.
     *
     * @param   ExecutionContext       $context     Profile installer context.
     * @param   string                 $fixtureKey  Stable definition fixture key.
     * @param   EntityTypeDefinition   $definition  Published definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordDefinitionAsset(
        ExecutionContext $context,
        string $fixtureKey,
        EntityTypeDefinition $definition,
    ): void {
        $state = $definition->toArray();
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            'business_definition',
            $definition->id,
            $definition->checksum(),
            $definition->definitionVersion,
            $state,
        );
    }

    /**
     * Drive one published definition to an active generated schema, resuming any interrupted plan.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   EntityTypeDefinition  $definition  Published definition requiring active storage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function installSchema(ExecutionContext $context, EntityTypeDefinition $definition): void
    {
        $installation = $this->schemas->installation($context, $definition->id);
        if (
            $installation?->status === SchemaInstallationStatus::Active
            && $installation->definitionVersion === $definition->definitionVersion
        ) {
            return;
        }
        $plan = $this->schemas->createPlan($context, $definition->id);
        if ($plan->status === SchemaPlanStatus::PendingApproval) {
            $confirmation = $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null;
            $plan = $this->schemas->approve($context, $plan->id, $plan->checksum(), $confirmation, null);
        }
        if ($plan->status === SchemaPlanStatus::Approved) {
            $this->schemas->execute($context, $plan->id);
        } elseif (
            in_array($plan->status, [
            SchemaPlanStatus::Executing,
            SchemaPlanStatus::Failed,
            SchemaPlanStatus::RecoveryRequired,
            ], true)
        ) {
            $this->schemas->recover($context, $plan->id);
        }
        $installation = $this->schemas->installation($context, $definition->id);
        if ($installation?->status !== SchemaInstallationStatus::Active) {
            throw new RuntimeException(sprintf('The VDM schema for %s did not become active.', $definition->handle));
        }
    }

    /**
     * Install explicit row/field policies derived from immutable definition exposure ceilings.
     *
     * @param   ExecutionContext      $context     Profile installer context.
     * @param   EntityTypeDefinition  $definition  Published definition receiving policies.
     *
     * @return  bool  Whether at least one new policy row was installed.
     *
     * @since   2.0.0
     */
    private function installRecordPolicies(ExecutionContext $context, EntityTypeDefinition $definition): bool
    {
        $created = false;
        $predicate = ['type' => 'constant', 'value' => true];
        $fields = $this->recordFieldRules($definition);
        $checksum = CanonicalDefinitionJson::checksum(['ast' => $predicate, 'fields' => $fields]);
        foreach (self::RECORD_OPERATIONS as $operation) {
            $policyCode = $this->policyCode($definition, $operation);
            $existing = $this->database->fetchAssociative(sprintf(
                'SELECT id, entity_definition_id, action, ast_checksum FROM %s WHERE policy_code = ?',
                $this->tables->quoted('resource_policies'),
            ), [$policyCode]);
            if ($existing !== false) {
                if (
                    ($existing['entity_definition_id'] ?? null) !== $definition->id
                    || ($existing['action'] ?? null) !== $operation
                    || ($existing['ast_checksum'] ?? null) !== $checksum
                ) {
                    throw new RuntimeException(sprintf('VDM policy %s has diverged.', $policyCode));
                }
                continue;
            }
            $created = true;
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://kumwe.dev/demo/vdm/policy/' . $policyCode)->toString();
            $now = $this->clock->now();
            $this->transactions->transactional(function () use (
                $context,
                $definition,
                $operation,
                $policyCode,
                $predicate,
                $fields,
                $checksum,
                $id,
                $now,
            ): void {
                $this->database->insert($this->tables->raw('resource_policies'), [
                    'id' => $id,
                    'policy_code' => $policyCode,
                    'owner_kind' => 'core',
                    'owner_identifier' => 'core',
                    'capability_code' => $operation,
                    'resource_type' => 'business_record',
                    'action' => $operation,
                    'effect' => 'allow',
                    'scope_type' => 'site',
                    'organization_id' => null,
                    'entity_definition_id' => $definition->id,
                    'canonical_ast' => $predicate,
                    'field_rules' => $fields,
                    'ast_checksum' => $checksum,
                    'policy_version' => 1,
                    'priority' => -1_000,
                    'status' => 'active',
                    'created_by' => $context->actorId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], [
                    'canonical_ast' => Types::JSON,
                    'field_rules' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
                $this->recordOwnership($context, 'resource_policy', $id);
                $this->audit->record(new AuditEvent(
                    Uuid::uuid7()->toString(),
                    $now,
                    $context->actorId(),
                    'demo.business.policy.install',
                    'resource_policy',
                    $id,
                    'success',
                    [
                        'site' => $context->site()->identifier(),
                        'policy_code' => $policyCode,
                        'definition_id' => $definition->id,
                    ],
                ));
            });
            $state = ['policy_code' => $policyCode, 'definition_id' => $definition->id, 'operation' => $operation];
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                'policy.' . substr(hash('sha256', $policyCode), 0, 32),
                'resource_policy',
                $id,
                CanonicalDefinitionJson::checksum($state),
                1,
                $state,
            );
        }

        return $created;
    }

    /**
     * Create every stable record once and rebuild source-version tracking when resuming.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   list<mixed>       $declarations  Record declarations in dependency order.
     *
     * @return  array<string, int>  Latest known record version by public record ID.
     *
     * @since   2.0.0
     */
    private function createRecords(ExecutionContext $context, array $declarations): array
    {
        $versions = [];
        foreach ($declarations as $candidate) {
            $record = $this->map($candidate, 'record declaration');
            $fixtureKey = $this->requiredString($record, 'fixture_key');
            $recordId = $this->requiredString($record, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'record checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $record,
                $fixtureKey,
                $recordId,
            ): int {
                $result = $this->records->create(new CreateRecordCommand(
                    $context,
                    $this->requiredString($record, 'definition'),
                    $this->requiredMap($record, 'values'),
                    IdempotencyKey::fromString($this->requiredString($record, 'idempotency_key')),
                    recordId: $recordId,
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_record',
                    $recordId,
                    $record,
                    $result->toArray(),
                );

                return $result->version;
            });
        }

        return $versions;
    }

    /**
     * Link all declared related records while advancing each source record's optimistic version.
     *
     * @param   ExecutionContext     $context       Profile installer context.
     * @param   list<mixed>          $declarations  Relationship declarations.
     * @param   array<string, int>   &$versions     Latest source versions by record ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function relateRecords(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $relation = $this->map($candidate, 'relationship declaration');
            $fixtureKey = $this->requiredString($relation, 'fixture_key');
            $source = $this->requiredString($relation, 'source_record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'relationship checkpoint');
                $versions[$source] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$source] ?? throw new RuntimeException(sprintf(
                'VDM relationship %s has no source version.',
                $fixtureKey,
            ));
            $versions[$source] = $this->transactions->transactional(function () use (
                $context,
                $relation,
                $source,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->relate(new RelateRecordsCommand(
                    $context,
                    $this->requiredString($relation, 'definition'),
                    $source,
                    $expectedVersion,
                    $this->requiredString($relation, 'relationship'),
                    $this->requiredString($relation, 'target_record_id'),
                    IdempotencyKey::fromString($this->requiredString($relation, 'idempotency_key')),
                    $this->optionalInteger($relation, 'position'),
                    targetValues: $this->optionalMap($relation, 'target_values'),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_relation',
                    $source,
                    $relation,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Execute the manifest's workflow actions in sequence, reconstructing versions on replay.
     *
     * @param   ExecutionContext    $context       Profile installer context.
     * @param   list<mixed>         $declarations  Action declarations.
     * @param   array<string, int>  &$versions     Latest record versions by ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function executeActions(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $action = $this->map($candidate, 'action declaration');
            $fixtureKey = $this->requiredString($action, 'fixture_key');
            $recordId = $this->requiredString($action, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'action checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$recordId]
                ?? throw new RuntimeException('A VDM action has no record version.');
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $action,
                $recordId,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->action(new ExecuteRecordActionCommand(
                    $context,
                    $this->requiredString($action, 'definition'),
                    $recordId,
                    $expectedVersion,
                    $this->requiredString($action, 'action'),
                    IdempotencyKey::fromString($this->requiredString($action, 'idempotency_key')),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_action',
                    $recordId,
                    $action,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Archive the one historical sample after every workflow action has settled.
     *
     * @param   ExecutionContext    $context       Profile installer context.
     * @param   list<mixed>         $declarations  Archive declarations.
     * @param   array<string, int>  &$versions     Latest record versions by ID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function archiveRecords(ExecutionContext $context, array $declarations, array &$versions): void
    {
        foreach ($declarations as $candidate) {
            $archive = $this->map($candidate, 'archive declaration');
            $fixtureKey = $this->requiredString($archive, 'fixture_key');
            $recordId = $this->requiredString($archive, 'record_id');
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            if ($asset !== null) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'archive checkpoint');
                $versions[$recordId] = $this->requiredInteger($state, 'version');
                continue;
            }
            $expectedVersion = $versions[$recordId]
                ?? throw new RuntimeException('A VDM archive has no record version.');
            $versions[$recordId] = $this->transactions->transactional(function () use (
                $context,
                $archive,
                $recordId,
                $expectedVersion,
                $fixtureKey,
            ): int {
                $result = $this->records->archive(new ArchiveRecordCommand(
                    $context,
                    $this->requiredString($archive, 'definition'),
                    $recordId,
                    $expectedVersion,
                    IdempotencyKey::fromString($this->requiredString($archive, 'idempotency_key')),
                ));
                $this->recordOperationAsset(
                    $context,
                    $fixtureKey,
                    'business_archive',
                    $recordId,
                    $archive,
                    $result->toArray(),
                );

                return $result->version;
            });
        }
    }

    /**
     * Store one replayable operation checkpoint with its resulting source version.
     *
     * @param   ExecutionContext              $context       Profile installer context.
     * @param   string                        $fixtureKey    Stable operation fixture key.
     * @param   string                        $resourceType  Diagnostic resource noun.
     * @param   string                        $resourceId    Public record identity.
     * @param   array<string, mixed>          $request       Canonical manifest request.
     * @param   array<string, int|string|bool|null>  $result Mutation result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordOperationAsset(
        ExecutionContext $context,
        string $fixtureKey,
        string $resourceType,
        string $resourceId,
        array $request,
        array $result,
    ): void {
        $state = ['request' => $request, ...$result];
        $this->ledger->recordAsset(
            $context->site()->identifier(),
            self::DATASET,
            $fixtureKey,
            $resourceType,
            $resourceId,
            CanonicalDefinitionJson::checksum($request),
            $this->requiredInteger($state, 'version'),
            $state,
        );
    }

    /**
     * Derive every field disclosure usage from immutable definition flags and sensitivity ceilings.
     *
     * @param   EntityTypeDefinition  $definition  Published definition supplying field metadata.
     *
     * @return  array<string, list<string>>  Explicit allowed fields per usage plus declared actions.
     *
     * @since   2.0.0
     */
    private function recordFieldRules(EntityTypeDefinition $definition): array
    {
        $allowed = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $allowed[$usage->value] = [];
        }
        foreach ($definition->fields() as $field) {
            $readable = $field->readVisible;
            $queryable = $readable && !in_array($field->sensitivity->value, ['restricted', 'secret'], true);
            $this->addField(
                $allowed,
                FieldAccessUsage::Create,
                $field,
                $field->createVisible && !$field->serverOnly && !$field->computed && $field->formula === null,
            );
            $this->addField(
                $allowed,
                FieldAccessUsage::Update,
                $field,
                $field->updateVisible && !$field->serverOnly && !$field->readOnly
                    && !$field->computed && $field->formula === null,
            );
            $this->addField($allowed, FieldAccessUsage::Detail, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::List, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Mcp, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Include, $field, $readable);
            $this->addField($allowed, FieldAccessUsage::Filter, $field, $queryable && $field->filterable);
            $this->addField($allowed, FieldAccessUsage::Relation, $field, $queryable && $field->filterable);
            $this->addField($allowed, FieldAccessUsage::Search, $field, $queryable && $field->searchable);
            $this->addField($allowed, FieldAccessUsage::Sort, $field, $queryable && $field->sortable);
            $this->addField($allowed, FieldAccessUsage::Aggregate, $field, $queryable && $field->reportable);
            $this->addField($allowed, FieldAccessUsage::Report, $field, $queryable && $field->reportable);
            $this->addField($allowed, FieldAccessUsage::Export, $field, $queryable && $field->exportable);
            $this->addField(
                $allowed,
                FieldAccessUsage::PublicReference,
                $field,
                $queryable && $field->type === ($definition->identityStrategy === IdentityStrategy::Uuid
                    ? 'core.uuid'
                    : 'core.reference_identity'),
            );
            $this->addField($allowed, FieldAccessUsage::Audit, $field, $readable);
        }
        $allowed['actions'] = array_map(static fn ($action): string => $action->handle, $definition->actions());

        return $allowed;
    }

    /**
     * Add one field handle to an explicit usage only when its immutable metadata admits it.
     *
     * @param   array<string, list<string>>  &$allowed   Field rules under construction.
     * @param   FieldAccessUsage             $usage      Exact disclosure context.
     * @param   FieldDefinition              $field      Published field metadata.
     * @param   bool                         $condition  Whether this usage is permitted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addField(
        array &$allowed,
        FieldAccessUsage $usage,
        FieldDefinition $field,
        bool $condition,
    ): void {
        if ($condition) {
            $allowed[$usage->value][] = $field->handle;
        }
    }

    /**
     * Record site ownership for the specialized policy bootstrap without duplicating an existing row.
     *
     * @param   ExecutionContext  $context       Profile installer context.
     * @param   string            $resourceType  Resource noun.
     * @param   string            $resourceId    Stable resource UUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordOwnership(ExecutionContext $context, string $resourceType, string $resourceId): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resourceType, $resourceId]);
        if ($exists === false) {
            $this->database->insert($this->tables->raw('resource_site_ownership'), [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'site_identifier' => $context->site()->identifier(),
            ]);
        }
    }

    /**
     * Derive the stable bounded policy code for one definition operation.
     *
     * @param   EntityTypeDefinition  $definition  Published definition that owns the policy.
     * @param   string                $operation   Business-record capability represented by the policy.
     *
     * @return  string  Stable policy code unique to the definition and operation.
     *
     * @since   2.0.0
     */
    private function policyCode(EntityTypeDefinition $definition, string $operation): string
    {
        return 'core.demo.vdm.'
            . str_replace('-', '', $definition->id)
            . '.'
            . substr($operation, strlen('business.record.'));
    }

    /**
     * Read one required object-shaped value from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the nested object.
     * @param   string                $key       Required field name.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function requiredMap(array $document, string $key): array
    {
        return $this->map($document[$key] ?? null, sprintf('field %s', $key));
    }

    /**
     * Require a decoded manifest value to be an object-shaped array.
     *
     * @param   mixed   $value  Candidate decoded value.
     * @param   string  $name   Diagnostic noun identifying the value on failure.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The VDM demo %s is invalid.', $name));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('The VDM demo %s has a non-string object key.', $name));
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Read one optional object-shaped value from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the optional nested object.
     * @param   string                $key       Optional field name.
     *
     * @return  array<string, mixed>  Validated object, or an empty object when the field is absent.
     *
     * @since   2.0.0
     */
    private function optionalMap(array $document, string $key): array
    {
        return array_key_exists($key, $document) ? $this->map($document[$key], sprintf('field %s', $key)) : [];
    }

    /**
     * Read one required list while enforcing its declared fixture bound.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the list.
     * @param   string                $key       Required field name.
     * @param   int                   $maximum   Largest accepted item count.
     *
     * @return  list<mixed>  Validated bounded manifest list.
     *
     * @since   2.0.0
     */
    private function requiredList(array $document, string $key, int $maximum): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new RuntimeException(sprintf('The VDM demo list %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required non-empty string from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  string  Validated non-empty field value.
     *
     * @since   2.0.0
     */
    private function requiredString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required positive integer from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  int  Validated positive field value.
     *
     * @since   2.0.0
     */
    private function requiredInteger(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one optional non-negative integer from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Optional field name.
     *
     * @return  ?int  Validated non-negative value, or null when absent.
     *
     * @since   2.0.0
     */
    private function optionalInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new RuntimeException(sprintf('The VDM demo field %s is invalid.', $key));
        }

        return $value;
    }
}
