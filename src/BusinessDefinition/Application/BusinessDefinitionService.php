<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class BusinessDefinitionService
{
    public function __construct(
        private BusinessDefinitionRepository $repository,
        private BusinessDefinitionValidator $validator,
        private BusinessDefinitionCompatibilityAnalyzer $compatibility,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<DefinitionCatalogEntry> */
    public function catalog(ExecutionContext $context): array
    {
        $this->authorize($context, 'content.read', AuthorizationResource::collection('business_definition'));

        return $this->repository->catalog($context->site());
    }

    public function draft(ExecutionContext $context, string $identifier): DefinitionDraft
    {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->draft($context->site(), $entry->id)
            ?? throw new BusinessDefinitionNotFound($identifier);
    }

    public function published(
        ExecutionContext $context,
        string $identifier,
        ?int $version = null,
    ): DefinitionVersionRecord {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->published($context->site(), $entry->id, $version)
            ?? throw new BusinessDefinitionNotFound($identifier, $version);
    }

    /** @return list<DefinitionVersionRecord> */
    public function history(ExecutionContext $context, string $identifier): array
    {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->history($context->site(), $entry->id);
    }

    /** @param array<string, mixed> $document */
    public function importDraft(
        ExecutionContext $context,
        array $document,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        try {
            $definition = EntityTypeDefinition::fromArray($document);
            if ($definition->status !== DefinitionStatus::Draft || $definition->definitionVersion !== 0) {
                $document['status'] = DefinitionStatus::Draft->value;
                $document['definition_version'] = 0;
                $definition = EntityTypeDefinition::fromArray($document);
            }
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.import.reject', $document['id'] ?? null, $failure);
            throw $failure;
        }

        return $this->persistDraft(
            $context,
            $definition,
            $expectedRevision,
            'business_definition.import',
        );
    }

    public function importJson(
        ExecutionContext $context,
        string $json,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        $this->authorize($context, 'content.update', AuthorizationResource::collection('business_definition'));
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $failure = new InvalidBusinessDefinition(
                'The imported business definition is invalid JSON.',
                0,
                $exception,
            );
            $this->auditFailure($context, 'business_definition.import.reject', null, $failure);
            throw $failure;
        }
        if (!is_array($document) || array_is_list($document)) {
            $failure = new InvalidBusinessDefinition('The imported business definition must be a JSON object.');
            $this->auditFailure($context, 'business_definition.import.reject', null, $failure);
            throw $failure;
        }

        /** @var array<string, mixed> $document */
        return $this->importDraft($context, $document, $expectedRevision);
    }

    public function saveDraft(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        return $this->persistDraft(
            $context,
            $definition,
            $expectedRevision,
            'business_definition.draft',
        );
    }

    private function persistDraft(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        ?int $expectedRevision,
        string $auditAction,
    ): DefinitionDraft {
        try {
            if ($definition->status !== DefinitionStatus::Draft || $definition->definitionVersion !== 0) {
                throw new InvalidBusinessDefinition(
                    'Administrator-authored definitions must be saved as version-zero drafts.',
                );
            }
            if ($definition->siteIdentifier !== $context->site()->identifier()
                || $definition->owner->type !== DefinitionOwnerType::Site
                || $definition->owner->identifier !== $context->site()->identifier()) {
                throw new InvalidBusinessDefinition(
                    'An administrator may edit only definitions owned by the current site.',
                );
            }
            $existing = $this->repository->entry($context->site(), $definition->handle);
            $resource = $existing === null
                ? AuthorizationResource::collection('business_definition')
                : AuthorizationResource::item('business_definition', $existing->id);
            $this->authorize($context, 'content.update', $resource);
            $this->validate($definition);
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $definition,
                $context,
                $now,
                $expectedRevision,
                $existing,
                $auditAction,
            ): DefinitionDraft {
                $draft = $this->repository->saveDraft(
                    $definition,
                    $context->actorId(),
                    $now,
                    $expectedRevision,
                );
                if ($existing === null) {
                    $this->ownership->record(
                        AuthorizationResource::item('business_definition', $definition->id),
                        $context->site(),
                    );
                }
                $this->record(
                    $context,
                    $auditAction,
                    $definition->id,
                    $now,
                    ['revision' => $draft->revision, 'checksum' => $draft->checksum],
                );

                return $draft;
            });
        } catch (Throwable $failure) {
            $this->auditFailure($context, $auditAction . '.reject', $definition->id, $failure);
            throw $failure;
        }
    }

    public function validateDraft(ExecutionContext $context, string $identifier): DefinitionDraft
    {
        $draft = $this->draft($context, $identifier);
        $this->authorize(
            $context,
            'content.update',
            AuthorizationResource::item('business_definition', $draft->definition->id),
        );
        try {
            $this->validate($draft->definition);
            $this->record(
                $context,
                'business_definition.validate',
                $draft->definition->id,
                $this->clock->now(),
                ['revision' => $draft->revision, 'checksum' => $draft->checksum],
            );
            return $draft;
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.validate.reject', $draft->definition->id, $failure);
            throw $failure;
        }
    }

    public function compareDraft(ExecutionContext $context, string $identifier): CompatibilityPlan
    {
        $draft = $this->validateDraft($context, $identifier);
        $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
        $plan = $this->compatibility->analyze($before, $draft->definition);
        $this->record(
            $context,
            'business_definition.compare',
            $draft->definition->id,
            $this->clock->now(),
            ['revision' => $draft->revision, 'plan' => $plan->toArray()],
        );

        return $plan;
    }

    /** Read-only compatibility preview; the explicit compare operation remains separately audited. */
    public function previewDraft(ExecutionContext $context, string $identifier): CompatibilityPlan
    {
        $draft = $this->draft($context, $identifier);
        $this->validate($draft->definition);
        $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
        return $this->compatibility->analyze($before, $draft->definition);
    }

    public function publish(
        ExecutionContext $context,
        string $identifier,
        int $expectedDraftRevision,
        bool $confirmed = false,
    ): DefinitionVersionRecord {
        $draft = $this->draft($context, $identifier);
        $this->authorize(
            $context,
            'content.update',
            AuthorizationResource::item('business_definition', $draft->definition->id),
        );
        try {
            $this->validate($draft->definition);
            $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
            $plan = $this->compatibility->analyze($before, $draft->definition);
            if ($plan->requiresConfirmation() && !$confirmed) {
                throw new InvalidBusinessDefinition(
                    'This compatibility plan changes behavior or data and requires explicit confirmation.',
                );
            }
            $published = $draft->definition->published($plan->toVersion);
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $published,
                $plan,
                $context,
                $now,
                $expectedDraftRevision,
            ): DefinitionVersionRecord {
                $record = $this->repository->publish(
                    $published,
                    $plan,
                    $context->actorId(),
                    $now,
                    $expectedDraftRevision,
                );
                $this->record(
                    $context,
                    'business_definition.publish',
                    $published->id,
                    $now,
                    [
                        'version' => $published->definitionVersion,
                        'checksum' => $published->checksum(),
                        'plan' => $plan->toArray(),
                    ],
                );
                return $record;
            });
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.publish.reject', $draft->definition->id, $failure);
            throw $failure;
        }
    }

    public function supersede(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Superseded);
    }

    public function deprecate(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Deprecated);
    }

    public function reject(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Rejected);
    }

    private function validate(EntityTypeDefinition $definition): void
    {
        $graph = [$definition->handle => $definition];
        $queue = [$definition];
        $site = SiteContext::fromString($definition->siteIdentifier);
        for ($index = 0; $index < count($queue); ++$index) {
            foreach ($queue[$index]->relationships() as $relationship) {
                if (isset($graph[$relationship->target])) {
                    continue;
                }
                $related = $this->repository->draft($site, $relationship->target)?->definition
                    ?? $this->repository->published($site, $relationship->target)?->definition;
                if ($related === null) {
                    continue;
                }
                $graph[$related->handle] = $related;
                $queue[] = $related;
                if (count($queue) > 128) {
                    throw new InvalidBusinessDefinition('A business definition graph exceeds 128 entities.');
                }
            }
        }
        $this->validator->validateGraph(array_values($graph));
    }

    private function changeStatus(
        ExecutionContext $context,
        string $identifier,
        int $version,
        DefinitionStatus $status,
    ): DefinitionVersionRecord {
        try {
            $entry = $this->entry($context, $identifier, 'content.update');
            if ($entry->owner->type !== DefinitionOwnerType::Site) {
                throw new InvalidBusinessDefinition('Package-owned definition status follows the extension lifecycle.');
            }
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $context,
                $entry,
                $version,
                $status,
                $now,
            ): DefinitionVersionRecord {
                $record = $this->repository->changeStatus(
                    $context->site(),
                    $entry->id,
                    $version,
                    $status,
                    $now,
                );
                $this->record(
                    $context,
                    'business_definition.' . $status->value,
                    $entry->id,
                    $now,
                    ['version' => $version],
                );
                return $record;
            });
        } catch (Throwable $failure) {
            $this->auditFailure(
                $context,
                'business_definition.' . $status->value . '.reject',
                $identifier,
                $failure,
            );
            throw $failure;
        }
    }

    private function entry(ExecutionContext $context, string $identifier, string $capability): DefinitionCatalogEntry
    {
        $entry = $this->repository->entry($context->site(), $identifier)
            ?? throw new BusinessDefinitionNotFound($identifier);
        $this->authorize(
            $context,
            $capability,
            AuthorizationResource::item('business_definition', $entry->id),
        );

        return $entry;
    }

    private function authorize(ExecutionContext $context, string $capability, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed($context, Capability::fromString($capability), $resource);
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        ExecutionContext $context,
        string $action,
        string $id,
        DateTimeImmutable $now,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            $action,
            'business_definition',
            $id,
            'success',
            $metadata,
        ));
    }

    private function auditFailure(
        ExecutionContext $context,
        string $action,
        mixed $id,
        Throwable $failure,
    ): void {
        try {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                $action,
                'business_definition',
                is_string($id) && $id !== '' ? $id : null,
                'rejected',
                ['reason' => substr($failure->getMessage(), 0, 500)],
            ));
        } catch (Throwable) {
            // The original failure remains authoritative. Successful mutations fail closed if auditing fails.
        }
    }
}
