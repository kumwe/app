<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateInterval;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRisk;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Authorized facade used by every delivery surface; planning, approval, and execution remain separate actions. */
final readonly class BusinessSchemaService
{
    private const REQUIRED_DRILL_FLAGS = [
        'clean_target_restore',
        'blueprint_checksum_verified',
        'typed_command_verified',
        'record_revision_audit_checksums_verified',
    ];

    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaPlanner $planner,
        private BusinessSchemaExecutor $executor,
        private BusinessSchemaPlanRepository $plans,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaRecoveryEvidenceRepository $evidence,
        private BusinessSchemaEnvironment $environment,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<SchemaPlan> */
    public function plans(ExecutionContext $context): array
    {
        $this->authorize($context, 'business.schema.read');

        return $this->plans->all($context->site());
    }

    public function plan(ExecutionContext $context, string $planId): SchemaPlan
    {
        $this->authorize($context, 'business.schema.read');

        return $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
    }

    /** @return list<SchemaPlanStep> */
    public function steps(ExecutionContext $context, string $planId): array
    {
        $plan = $this->plan($context, $planId);

        return $this->plans->steps($plan->id);
    }

    public function installation(ExecutionContext $context, string $definitionId): ?SchemaInstallation
    {
        $this->authorize($context, 'business.schema.read');
        $installation = $this->installations->find($definitionId);

        return $installation !== null && $installation->siteIdentifier === $context->site()->identifier()
            ? $installation
            : null;
    }

    public function recoveryEvidence(
        ExecutionContext $context,
        string $evidenceId,
    ): ?SchemaRecoveryEvidence {
        $this->authorize($context, 'business.schema.read');

        return $this->evidence->find($context->site(), $evidenceId);
    }

    /**
     * Schema-specific definition selector; does not require the separate content.read capability.
     * @return list<array{id: string, handle: string, version: int, owner: string}>
     */
    public function definitions(ExecutionContext $context): array
    {
        $this->authorize($context, 'business.schema.read');
        $result = [];
        foreach ($this->definitions->catalog($context->site()) as $entry) {
            if ($entry->publishedVersion === null || !$entry->ownerActive) {
                continue;
            }
            $result[] = [
                'id' => $entry->id,
                'handle' => $entry->handle,
                'version' => $entry->publishedVersion,
                'owner' => $entry->owner->type->value . ':' . $entry->owner->identifier,
            ];
        }
        usort($result, static fn (array $left, array $right): int => strcmp($left['handle'], $right['handle']));

        return $result;
    }

    public function createPlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        return $this->planner->plan($context, $definitionId);
    }

    public function createPurgePlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        return $this->planner->purgePlan($context, $definitionId);
    }

    public function approve(
        ExecutionContext $context,
        string $planId,
        string $expectedChecksum,
        ?string $confirmation,
        ?string $evidenceId,
    ): SchemaPlan {
        $this->authorize($context, 'business.schema.approve');
        $plan = $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
        if (!hash_equals($plan->checksum(), $expectedChecksum)) {
            throw new BusinessSchemaConflict('The schema plan changed after it was inspected.');
        }
        $confirmationDigest = null;
        if ($plan->risk->requiresHighImpactAuthorization()) {
            if ($confirmation === null || !hash_equals($plan->checksum(), $confirmation)) {
                throw new BusinessSchemaConflict('High-impact approval requires the exact current plan checksum.');
            }
            $confirmationDigest = hash('sha256', implode("\0", [
                'kumwe:business-schema-confirmation:v1',
                $confirmation,
                $context->actorId(),
                $context->authorizationFingerprint(),
            ]));
        } elseif ($confirmation !== null) {
            throw new BusinessSchemaConflict('Low-risk approval must not carry high-impact confirmation state.');
        }
        if ($plan->risk === SchemaRisk::Destructive) {
            $this->authorize($context, 'business.schema.destructive');
        }
        if ($plan->risk->requiresRecoveryEvidence()) {
            if ($evidenceId === null) {
                throw new BusinessSchemaConflict('This plan requires tested source-bound recovery evidence.');
            }
            $evidence = $this->evidence->find($context->site(), $evidenceId)
                ?? throw new BusinessSchemaNotFound($evidenceId);
            if (
                $plan->fromSchemaChecksum === null
                || !hash_equals($evidence->sourceSchemaChecksum, $plan->fromSchemaChecksum)
            ) {
                throw new BusinessSchemaConflict('Recovery evidence is bound to another source schema.');
            }
            $this->assertTrustedEvidence($context, $evidence, false);
            $freshnessFloor = $this->clock->now()->sub(new DateInterval('P7D'));
            if ($plan->createdAt > $freshnessFloor) {
                $freshnessFloor = $plan->createdAt;
            }
            if (
                !$evidence->qualifies(
                    $context->site()->identifier(),
                    $this->environment->databaseDriver(),
                    $this->environment->databaseServerVersion(),
                    $this->environment->applicationRelease(),
                    $plan->fromSchemaChecksum,
                    $freshnessFloor,
                )
            ) {
                throw new BusinessSchemaConflict(
                    'Recovery evidence must be a fresh clean-target drill created for this persisted plan.',
                );
            }
        } elseif ($evidenceId !== null) {
            throw new BusinessSchemaConflict('This schema plan does not require recovery evidence.');
        }
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $plan,
            $expectedChecksum,
            $confirmationDigest,
            $evidenceId,
            $now,
        ): SchemaPlan {
            $approved = $plan->approve(
                $context->actorId(),
                $now,
                $expectedChecksum,
                $confirmationDigest,
                $evidenceId,
            );
            $this->plans->replace($approved, $plan->revision);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.approve',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'plan_checksum' => $plan->checksum(),
                    'risk' => $plan->risk->value,
                    'recovery_evidence_id' => $evidenceId,
                ],
            ));

            return $approved;
        });
    }

    public function execute(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        try {
            return $this->executor->execute($context, $planId);
        } catch (Throwable $failure) {
            $plan = $this->plans->find($context->site(), $planId);
            if ($plan === null || !$this->isGraphBootstrapPause($context, $plan)) {
                throw $failure;
            }
            $peers = $this->approvedGraphPeers($context, $plan);
            foreach ($peers as $peer) {
                $current = $this->plans->find($context->site(), $peer->id)
                    ?? throw new BusinessSchemaNotFound($peer->id);
                if ($current->status === SchemaPlanStatus::Completed) {
                    continue;
                }
                try {
                    if ($current->status === SchemaPlanStatus::Approved) {
                        $this->executor->execute($context, $peer->id);
                    } elseif ($this->isGraphBootstrapPause($context, $current)) {
                        $this->executor->resumeGraphBootstrap($context, $peer->id);
                    } else {
                        throw new BusinessSchemaConflict(
                            'A connected initial schema plan is not in an executable graph state.',
                        );
                    }
                } catch (Throwable $peerFailure) {
                    $current = $this->plans->find($context->site(), $peer->id);
                    if ($current === null || !$this->isGraphBootstrapPause($context, $current)) {
                        throw $peerFailure;
                    }
                }
            }
            foreach (array_reverse($peers) as $peer) {
                $current = $this->plans->find($context->site(), $peer->id)
                    ?? throw new BusinessSchemaNotFound($peer->id);
                if ($current->status === SchemaPlanStatus::Completed) {
                    continue;
                }
                if (!$this->isGraphBootstrapPause($context, $current)) {
                    throw new BusinessSchemaConflict('A connected initial schema plan is not safely resumable.');
                }
                $this->executor->resumeGraphBootstrap($context, $current->id);
            }

            return $this->executor->resumeGraphBootstrap($context, $planId);
        }
    }

    public function recover(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        return $this->executor->recover($context, $planId);
    }

    public function recordRecoveryEvidence(
        ExecutionContext $context,
        SchemaRecoveryEvidence $evidence,
    ): SchemaRecoveryEvidence {
        $this->authorize($context, 'business.schema.recover');
        $this->assertTrustedEvidence($context, $evidence, true);
        $now = $this->clock->now();
        if ($evidence->backupCreatedAt > $now || $evidence->verifiedAt > $now) {
            throw new BusinessSchemaConflict('Recovery evidence timestamps cannot be in the future.');
        }

        return $this->transactions->transactional(function () use ($context, $evidence, $now): SchemaRecoveryEvidence {
            $this->evidence->save($evidence);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.recovery_evidence.record',
                'business_schema_recovery_evidence',
                $evidence->id,
                'success',
                [
                    'evidence_checksum' => $evidence->checksum(),
                    'source_schema_checksum' => $evidence->sourceSchemaChecksum,
                    'drill_reference' => $evidence->drillReference,
                ],
            ));

            return $evidence;
        });
    }

    private function assertTrustedEvidence(
        ExecutionContext $context,
        SchemaRecoveryEvidence $evidence,
        bool $requireCurrentVerifier,
    ): void {
        if (
            !$evidence->restoreTested
            || $evidence->siteIdentifier !== $context->site()->identifier()
            || ($requireCurrentVerifier && $evidence->verifiedBy !== $context->actorId())
            || $evidence->databaseDriver !== $this->environment->databaseDriver()
            || !hash_equals($evidence->databaseServerVersion, $this->environment->databaseServerVersion())
            || !hash_equals($evidence->applicationRelease, $this->environment->applicationRelease())
        ) {
            throw new BusinessSchemaConflict('Recovery evidence does not match the authenticated environment.');
        }
        foreach (self::REQUIRED_DRILL_FLAGS as $flag) {
            if (($evidence->details[$flag] ?? null) !== true) {
                throw new BusinessSchemaConflict('Recovery evidence is missing a required clean-drill proof.');
            }
        }
        foreach (['client_version', 'restore_target_reference'] as $key) {
            $value = $evidence->details[$key] ?? null;
            if (
                !is_string($value) || trim($value) === '' || strlen($value) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new BusinessSchemaConflict('Recovery evidence is missing bounded drill identity data.');
            }
        }
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_schema'),
        );
    }

    private function isGraphBootstrapPause(ExecutionContext $context, SchemaPlan $plan): bool
    {
        return $this->executor->isGraphBootstrapPause($context, $plan);
    }

    /** @return list<SchemaPlan> */
    private function approvedGraphPeers(ExecutionContext $context, SchemaPlan $root): array
    {
        $all = $this->plans->all($context->site());
        $providers = [];
        foreach ($all as $candidate) {
            if ($candidate->fromSchemaChecksum !== null) {
                continue;
            }
            foreach ($candidate->operations() as $operation) {
                $name = $operation->kind === SchemaOperationKind::CreateTable
                    ? ($operation->after['physical_name'] ?? null)
                    : null;
                if (is_string($name)) {
                    $providers[$name] ??= $candidate;
                }
            }
        }
        $selected = [];
        $pending = [$root];
        while ($pending !== []) {
            $plan = array_pop($pending);
            if (!$plan instanceof SchemaPlan || isset($selected[$plan->id])) {
                continue;
            }
            $selected[$plan->id] = $plan;
            foreach ($plan->operations() as $operation) {
                if ($operation->kind !== SchemaOperationKind::AddForeignKey) {
                    continue;
                }
                $foreignTable = $operation->after['foreign_table'] ?? null;
                if (!is_string($foreignTable) || !isset($providers[$foreignTable])) {
                    continue;
                }
                $pending[] = $providers[$foreignTable];
            }
        }
        unset($selected[$root->id]);
        foreach ($selected as $candidate) {
            if (
                !in_array(
                    $candidate->status,
                    [SchemaPlanStatus::Approved, SchemaPlanStatus::RecoveryRequired, SchemaPlanStatus::Completed],
                    true,
                )
            ) {
                throw new BusinessSchemaConflict(
                    'Every connected initial schema plan must be independently approved before graph execution.',
                );
            }
        }
        $result = array_values($selected);
        usort($result, static fn (SchemaPlan $left, SchemaPlan $right): int => strcmp(
            $left->definitionId,
            $right->definitionId,
        ));

        return $result;
    }
}
