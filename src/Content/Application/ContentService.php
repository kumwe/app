<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentRevision;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\ExpectedVersion;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class ContentService
{
    public const CORE_WORKFLOW_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
    public const CORE_PAGE_TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    public function __construct(
        private ContentRepository $repository,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private Workflow $workflow,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private ?ContentModelRepository $models = null,
        private ?JsonSchemaValidator $schemas = null,
    ) {
    }

    /** @return list<ContentRecord> */
    public function list(ExecutionContext $context, int $limit = 100, bool $includeDeleted = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('The content result limit must be between 1 and 500.');
        }
        $result = [];
        $offset = 0;
        $pageSize = min(500, max(50, $limit));

        do {
            $page = $this->repository instanceof SiteScopedContentRepository
                ? $this->repository->allForSite($context->site(), $pageSize, $includeDeleted, $offset)
                : $this->repository->all($pageSize, $includeDeleted, $offset);
            foreach ($page as $record) {
                if (
                    $this->authorization->decide(
                        $context,
                        Capability::fromString('content.read'),
                        AuthorizationResource::item('content', $record->entry->id()),
                    )->allowed
                ) {
                    $result[] = $record;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($page);
        } while (count($page) === $pageSize);

        return $result;
    }

    public function get(ExecutionContext $context, string $id, bool $includeDeleted = false): ContentRecord
    {
        $this->authorize($context, 'content.read', $id);
        $record = $this->repository instanceof SiteScopedContentRepository
            ? $this->repository->findForSite($context->site(), $id, $includeDeleted)
            : $this->repository->find($id, $includeDeleted);

        return $record ?? throw new ContentNotFound($id);
    }

    public function publishedBySlug(string $slug, ?SiteContext $site = null): ?ContentRecord
    {
        return $this->repository instanceof SiteScopedContentRepository
            ? $this->repository->findPublishedBySlugForSite(
                $site ?? SiteContext::default(),
                $slug,
                $this->clock->now(),
            )
            : $this->repository->findPublishedBySlug($slug, $this->clock->now());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function create(
        ExecutionContext $context,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
        string $contentTypeIdentifier = self::CORE_PAGE_TYPE_ID,
    ): ContentRecord {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.create'),
            AuthorizationResource::collection('content'),
        );
        $type = $this->models?->contentType($context->site(), $contentTypeIdentifier);
        if ($this->models !== null && $type === null) {
            throw new ContentModelNotFound('content type', $contentTypeIdentifier);
        }
        $workflowDefinition = $type === null
            ? null
            : $this->models?->workflow($context->site(), $type->workflowId, $type->workflowVersion);
        if ($type !== null && $workflowDefinition === null) {
            throw new ContentModelNotFound('workflow', $type->workflowId, $type->workflowVersion);
        }
        if ($type !== null) {
            ($this->schemas ?? new JsonSchemaValidator())->assertValid($type->schema(), $data);
        }
        $now = $this->clock->now();
        $entry = ContentEntry::create(
            Uuid::uuid7()->toString(),
            $title,
            $slug,
            $data,
            $workflowDefinition?->initialState() ?? ContentStatus::Draft,
            $window,
        );
        $record = new ContentRecord(
            $entry,
            $type?->id ?? self::CORE_PAGE_TYPE_ID,
            $workflowDefinition?->id ?? self::CORE_WORKFLOW_ID,
            $now,
            $now,
            null,
            $type?->version ?? 1,
            $workflowDefinition?->version ?? 1,
            $context->site()->identifier(),
        );

        return $this->transactions->transactional(function () use ($record, $context, $now): ContentRecord {
            $this->repository->insert($record);
            $this->ownership->record(
                AuthorizationResource::item('content', $record->entry->id()),
                $context->site(),
            );
            $this->captureRevision($record->entry, $now);
            $this->recordAudit($context->actorId(), 'content.create', $record->entry, $now);

            return $record;
        });
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function update(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
    ): ContentRecord {
        $this->authorize($context, 'content.update', $id);
        $stored = $this->get($context, $id);
        $type = $this->models?->contentType($context->site(), $stored->contentTypeId, $stored->contentTypeVersion);
        if ($this->models !== null && $type === null) {
            throw new ContentModelNotFound('content type', $stored->contentTypeId, $stored->contentTypeVersion);
        }
        if ($type !== null) {
            ($this->schemas ?? new JsonSchemaValidator())->assertValid($type->schema(), $data);
        }
        $expected = new ExpectedVersion($expectedVersion);
        $entry = $stored->entry->revise($expected, $title, $slug, $data, $window);

        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($context->actorId(), 'content.update', $updated->entry, $now);

            return $updated;
        });
    }

    public function transition(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        ContentStatus|string $target,
    ): ContentRecord {
        $this->authorize($context, 'content.read', $id);
        $stored = $this->get($context, $id);
        $required = $this->transitionCapabilityForRecord($context, $stored, $target);
        $this->authorize($context, $required->value(), $id);
        $definition = $this->models?->workflow($context->site(), $stored->workflowId, $stored->workflowVersion);
        $entry = $stored->entry->transition(
            new ExpectedVersion($expectedVersion),
            $definition === null ? $this->workflow : new Workflow($definition),
            $target,
        );
        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($context->actorId(), 'content.transition', $updated->entry, $now, [
                'status' => $updated->entry->statusKey(),
            ]);

            return $updated;
        });
    }

    public function transitionCapability(
        ExecutionContext $context,
        string $id,
        ContentStatus|string $target,
    ): Capability {
        $this->authorize($context, 'content.read', $id);

        return $this->transitionCapabilityForRecord($context, $this->get($context, $id), $target);
    }

    private function transitionCapabilityForRecord(
        ExecutionContext $context,
        ContentRecord $stored,
        ContentStatus|string $target,
    ): Capability {
        $definition = $this->models?->workflow($context->site(), $stored->workflowId, $stored->workflowVersion);
        if ($definition !== null) {
            return $definition->transition(
                $stored->entry->statusKey(),
                $target instanceof ContentStatus ? $target->value : $target,
            )->requiredCapability;
        }
        if ($this->models !== null) {
            throw new ContentModelNotFound('workflow', $stored->workflowId, $stored->workflowVersion);
        }

        $from = $stored->entry->status();
        if (!$from instanceof ContentStatus || !$target instanceof ContentStatus) {
            throw new \DomainException('A persisted workflow definition is required for custom states.');
        }

        return Capability::fromString(match (true) {
            $from === ContentStatus::Draft && $target === ContentStatus::Review => 'content.submit',
            $from === ContentStatus::Review && $target === ContentStatus::Draft => 'content.review',
            $target === ContentStatus::Published => 'content.publish',
            $from === ContentStatus::Published && $target === ContentStatus::Draft => 'content.unpublish',
            $target === ContentStatus::Archived => 'content.archive',
            $from === ContentStatus::Archived && $target === ContentStatus::Draft => 'content.restore',
            default => 'content.update',
        });
    }

    public function trash(ExecutionContext $context, string $id, int $expectedVersion): ContentRecord
    {
        $this->authorize($context, 'content.delete', $id);
        $stored = $this->get($context, $id);
        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->setDeletedAt($id, $expectedVersion, $now, $now);
            $record = $this->get($context, $id, true);
            $this->recordAudit($context->actorId(), 'content.trash', $record->entry, $now);

            return $record;
        });
    }

    public function restore(ExecutionContext $context, string $id, int $expectedVersion): ContentRecord
    {
        $this->authorize($context, 'content.restore', $id);
        $stored = $this->get($context, $id, true);

        if ($stored->deletedAt === null) {
            return $stored;
        }

        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->setDeletedAt($id, $expectedVersion, null, $now);
            $record = $this->get($context, $id);
            $this->recordAudit($context->actorId(), 'content.restore', $record->entry, $now);

            return $record;
        });
    }

    private function captureRevision(ContentEntry $entry, DateTimeImmutable $time): void
    {
        $this->repository->appendRevision(ContentRevision::capture(
            Uuid::uuid7()->toString(),
            $entry,
            $this->repository->nextRevisionNumber($entry->id()),
            $time,
        ));
    }

    private function authorize(ExecutionContext $context, string $action, string $id): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($action),
            AuthorizationResource::item('content', $id),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function recordAudit(
        string $actorId,
        string $action,
        ContentEntry $entry,
        DateTimeImmutable $time,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $time,
            $actorId,
            $action,
            'content',
            $entry->id(),
            'success',
            ['version' => $entry->version(), ...$metadata],
        ));
    }
}
