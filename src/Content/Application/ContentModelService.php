<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\CMS\Content\Domain\VersionConflict;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowTransitionDefinition;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class ContentModelService
{
    public function __construct(
        private ContentModelRepository $repository,
        private JsonSchemaValidator $schemas,
        private SchemaCompatibilityChecker $compatibility,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<ContentTypeDefinition> */
    public function contentTypes(ExecutionContext $context): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::collection('content_type'),
        );
        return $this->repository->contentTypes($context->site());
    }

    public function contentType(
        ExecutionContext $context,
        string $identifier,
        ?int $version = null,
    ): ContentTypeDefinition {
        $definition = $this->repository->contentType($context->site(), $identifier, $version)
            ?? throw new ContentModelNotFound('content type', $identifier, $version);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::item('content_type', $definition->id),
        );

        return $definition;
    }

    /** @param array<string, mixed> $schema */
    public function createContentType(
        ExecutionContext $context,
        string $handle,
        string $name,
        string $workflowIdentifier,
        array $schema,
    ): ContentTypeDefinition {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::collection('content_type'),
        );
        $this->schemas->assertSupported($schema);
        $workflow = $this->repository->workflow($context->site(), $workflowIdentifier)
            ?? throw new ContentModelNotFound('workflow', $workflowIdentifier);
        $now = $this->clock->now();
        $definition = new ContentTypeDefinition(
            Uuid::uuid7()->toString(),
            $context->site(),
            $handle,
            $name,
            $workflow->id,
            $workflow->version,
            $schema,
            1,
            $now,
            $now,
        );
        return $this->transactions->transactional(function () use ($definition, $context, $now): ContentTypeDefinition {
            $this->repository->insertContentType($definition);
            $this->ownership->record(AuthorizationResource::item('content_type', $definition->id), $context->site());
            $this->audit($context, 'content_type.create', 'content_type', $definition->id, $definition->version, $now);
            return $definition;
        });
    }

    /** @param array<string, mixed> $schema */
    public function updateContentType(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $name,
        string $workflowIdentifier,
        array $schema,
        bool $allowBreaking = false,
    ): ContentTypeDefinition {
        $current = $this->repository->contentType($context->site(), $id)
            ?? throw new ContentModelNotFound('content type', $id);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content_type', $current->id),
        );
        if ($current->version !== $expectedVersion) {
            throw new VersionConflict($expectedVersion, $current->version);
        }
        $this->schemas->assertSupported($schema);
        $breaking = $this->compatibility->breakingChanges($current->schema(), $schema);
        if ($breaking !== [] && !$allowBreaking) {
            throw new IncompatibleDefinition($breaking);
        }
        $workflow = $this->repository->workflow($context->site(), $workflowIdentifier)
            ?? throw new ContentModelNotFound('workflow', $workflowIdentifier);
        $now = $this->clock->now();
        $definition = new ContentTypeDefinition(
            $current->id,
            $context->site(),
            $current->handle,
            $name,
            $workflow->id,
            $workflow->version,
            $schema,
            $expectedVersion + 1,
            $current->createdAt,
            $now,
        );
        return $this->transactions->transactional(function () use (
            $definition,
            $expectedVersion,
            $context,
            $now,
            $breaking,
        ): ContentTypeDefinition {
            $this->repository->publishContentType($definition, $expectedVersion);
            $this->audit(
                $context,
                'content_type.publish',
                'content_type',
                $definition->id,
                $definition->version,
                $now,
                ['breaking_changes' => $breaking],
            );
            return $definition;
        });
    }

    /** @return list<WorkflowDefinition> */
    public function workflows(ExecutionContext $context): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::collection('workflow'),
        );
        return $this->repository->workflows($context->site());
    }

    public function workflow(ExecutionContext $context, string $identifier, ?int $version = null): WorkflowDefinition
    {
        $definition = $this->repository->workflow($context->site(), $identifier, $version)
            ?? throw new ContentModelNotFound('workflow', $identifier, $version);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::item('workflow', $definition->id),
        );

        return $definition;
    }

    /** @param list<array<string, mixed>> $states @param list<array<string, mixed>> $transitions */
    public function createWorkflow(
        ExecutionContext $context,
        string $handle,
        string $name,
        array $states,
        array $transitions,
    ): WorkflowDefinition {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::collection('workflow'),
        );
        $now = $this->clock->now();
        $definition = $this->buildWorkflow(
            Uuid::uuid7()->toString(),
            $context,
            $handle,
            $name,
            $states,
            $transitions,
            1,
            $now,
            $now,
        );
        return $this->transactions->transactional(function () use ($definition, $context, $now): WorkflowDefinition {
            $this->repository->insertWorkflow($definition);
            $this->ownership->record(AuthorizationResource::item('workflow', $definition->id), $context->site());
            $this->audit($context, 'workflow.create', 'workflow', $definition->id, $definition->version, $now);
            return $definition;
        });
    }

    /** @param list<array<string, mixed>> $states @param list<array<string, mixed>> $transitions */
    public function updateWorkflow(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $name,
        array $states,
        array $transitions,
        bool $allowBreaking = false,
    ): WorkflowDefinition {
        $current = $this->repository->workflow($context->site(), $id)
            ?? throw new ContentModelNotFound('workflow', $id);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('workflow', $current->id),
        );
        if ($current->version !== $expectedVersion) {
            throw new VersionConflict($expectedVersion, $current->version);
        }
        $now = $this->clock->now();
        $definition = $this->buildWorkflow(
            $id,
            $context,
            $current->handle,
            $name,
            $states,
            $transitions,
            $expectedVersion + 1,
            $current->createdAt,
            $now,
        );
        $breaking = $this->workflowBreakingChanges($current, $definition);
        if ($breaking !== [] && !$allowBreaking) {
            throw new IncompatibleDefinition($breaking);
        }
        return $this->transactions->transactional(function () use (
            $definition,
            $expectedVersion,
            $context,
            $now,
            $breaking,
        ): WorkflowDefinition {
            $this->repository->publishWorkflow($definition, $expectedVersion);
            $this->audit(
                $context,
                'workflow.publish',
                'workflow',
                $definition->id,
                $definition->version,
                $now,
                ['breaking_changes' => $breaking],
            );
            return $definition;
        });
    }

    /** @param list<array<string, mixed>> $states @param list<array<string, mixed>> $transitions */
    private function buildWorkflow(
        string $id,
        ExecutionContext $context,
        string $handle,
        string $name,
        array $states,
        array $transitions,
        int $version,
        DateTimeImmutable $created,
        DateTimeImmutable $published,
    ): WorkflowDefinition {
        $mappedStates = array_map(static fn (array $state): WorkflowStateDefinition => new WorkflowStateDefinition(
            (string) ($state['key'] ?? ''),
            (string) ($state['name'] ?? ''),
            (bool) ($state['initial'] ?? false),
            (bool) ($state['public'] ?? false),
        ), $states);
        $mappedTransitions = array_map(
            static fn (array $transition): WorkflowTransitionDefinition => new WorkflowTransitionDefinition(
                (string) ($transition['from'] ?? ''),
                (string) ($transition['to'] ?? ''),
                Capability::fromString((string) ($transition['required_capability'] ?? 'content.update')),
            ),
            $transitions,
        );
        return new WorkflowDefinition(
            $id,
            $context->site(),
            $handle,
            $name,
            $mappedStates,
            $mappedTransitions,
            $version,
            $created,
            $published,
        );
    }

    /** @return list<string> */
    private function workflowBreakingChanges(WorkflowDefinition $before, WorkflowDefinition $after): array
    {
        $beforeStates = [];
        foreach ($before->states() as $state) {
            $beforeStates[$state->key] = $state;
        }
        $afterStates = [];
        foreach ($after->states() as $state) {
            $afterStates[$state->key] = $state;
        }
        $changes = [];
        foreach ($beforeStates as $key => $state) {
            if (!isset($afterStates[$key])) {
                $changes[] = 'removed state ' . $key;
            } elseif ($state->public !== $afterStates[$key]->public) {
                $changes[] = 'changed public visibility of state ' . $key;
            } elseif ($state->initial !== $afterStates[$key]->initial) {
                $changes[] = 'changed initial-state assignment of ' . $key;
            }
        }
        $afterTransitions = [];
        foreach ($after->transitions() as $transition) {
            $afterTransitions[$transition->from . '>' . $transition->to] = $transition;
        }
        foreach ($before->transitions() as $transition) {
            $edge = $transition->from . '>' . $transition->to;
            if (!isset($afterTransitions[$edge])) {
                $changes[] = 'removed transition ' . $edge;
            } elseif (
                $transition->requiredCapability->value()
                !== $afterTransitions[$edge]->requiredCapability->value()
            ) {
                $changes[] = 'changed capability of transition ' . $edge;
            }
        }
        sort($changes, SORT_STRING);
        return $changes;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        ExecutionContext $context,
        string $action,
        string $type,
        string $id,
        int $version,
        DateTimeImmutable $now,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            $action,
            $type,
            $id,
            'success',
            ['version' => $version, ...$metadata],
        ));
    }
}
