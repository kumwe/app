<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentRevision;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\ExpectedVersion;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
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
    ) {
    }

    /** @return list<ContentRecord> */
    public function list(int $limit = 100, bool $includeDeleted = false): array
    {
        return $this->repository->all($limit, $includeDeleted);
    }

    public function get(string $id, bool $includeDeleted = false): ContentRecord
    {
        return $this->repository->find($id, $includeDeleted) ?? throw new ContentNotFound($id);
    }

    public function publishedBySlug(string $slug): ?ContentRecord
    {
        return $this->repository->findPublishedBySlug($slug, $this->clock->now());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function create(
        string $actorId,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
    ): ContentRecord {
        $now = $this->clock->now();
        $entry = ContentEntry::create(
            Uuid::uuid7()->toString(),
            $title,
            $slug,
            $data,
            ContentStatus::Draft,
            $window,
        );
        $record = new ContentRecord(
            $entry,
            self::CORE_PAGE_TYPE_ID,
            self::CORE_WORKFLOW_ID,
            $now,
            $now,
        );

        return $this->transactions->transactional(function () use ($record, $actorId, $now): ContentRecord {
            $this->repository->insert($record);
            $this->captureRevision($record->entry, $now);
            $this->recordAudit($actorId, 'content.create', $record->entry, $now);

            return $record;
        });
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function update(
        string $actorId,
        string $id,
        int $expectedVersion,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
    ): ContentRecord {
        $stored = $this->get($id);
        $expected = new ExpectedVersion($expectedVersion);
        $entry = $stored->entry->revise($expected, $title, $slug, $data, $window);

        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $actorId,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($actorId, 'content.update', $updated->entry, $now);

            return $updated;
        });
    }

    public function transition(
        string $actorId,
        string $id,
        int $expectedVersion,
        ContentStatus $target,
    ): ContentRecord {
        $stored = $this->get($id);
        $entry = $stored->entry->transition(new ExpectedVersion($expectedVersion), $this->workflow, $target);
        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $actorId,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($actorId, 'content.transition', $updated->entry, $now, [
                'status' => $updated->entry->status()->value,
            ]);

            return $updated;
        });
    }

    public function trash(string $actorId, string $id, int $expectedVersion): ContentRecord
    {
        $stored = $this->get($id);
        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $actorId,
            $now,
        ): ContentRecord {
            $this->repository->setDeletedAt($id, $expectedVersion, $now, $now);
            $record = $this->get($id, true);
            $this->recordAudit($actorId, 'content.trash', $record->entry, $now);

            return $record;
        });
    }

    public function restore(string $actorId, string $id, int $expectedVersion): ContentRecord
    {
        $stored = $this->get($id, true);

        if ($stored->deletedAt === null) {
            return $stored;
        }

        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $actorId,
            $now,
        ): ContentRecord {
            $this->repository->setDeletedAt($id, $expectedVersion, null, $now);
            $record = $this->get($id);
            $this->recordAudit($actorId, 'content.restore', $record->entry, $now);

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
