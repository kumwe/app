<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Audit\Domain;

use Kumwe\CMS\Audit\Domain\AuditEventDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEventDigest::class)]
final class AuditEventDigestTest extends TestCase
{
    private const string ID = '8bd4ec65-92f2-4934-afb8-b22a3cf956cd';

    public function testDigestIsAStableSha256OverTheCanonicalDocument(): void
    {
        $digest = $this->digest();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $digest);
        self::assertSame($digest, $this->digest());
    }

    public function testDigestDoesNotDependOnMetadataKeyOrder(): void
    {
        self::assertSame(
            $this->digest(metadata: ['alpha' => 1, 'beta' => 2]),
            $this->digest(metadata: ['beta' => 2, 'alpha' => 1]),
        );
    }

    public function testEveryEvidentiaryFieldChangesTheDigest(): void
    {
        $baseline = $this->digest();

        self::assertNotSame($baseline, $this->digest(id: '8bd4ec65-92f2-4934-afb8-b22a3cf956ce'));
        self::assertNotSame($baseline, $this->digest(occurredAt: '2026-08-13 09:00:01'));
        self::assertNotSame($baseline, $this->digest(actorId: 'other-actor'));
        self::assertNotSame($baseline, $this->digest(action: 'identity.user.deactivated'));
        self::assertNotSame($baseline, $this->digest(subjectType: 'content'));
        self::assertNotSame($baseline, $this->digest(subjectId: 'other-subject'));
        self::assertNotSame($baseline, $this->digest(outcome: 'denied'));
        self::assertNotSame($baseline, $this->digest(metadata: ['request_id' => 'request-2']));
    }

    public function testNullActorAndSubjectAreDistinctFromEmptyText(): void
    {
        self::assertNotSame($this->digest(actorId: null), $this->digest(actorId: ''));
        self::assertNotSame($this->digest(subjectId: null), $this->digest(subjectId: ''));
    }

    public function testTheVersionedContextIsPartOfTheDigest(): void
    {
        self::assertSame('kumwe-audit-event-v1', AuditEventDigest::CHAIN_CONTEXT);
        self::assertNotSame(
            hash('sha256', $this->canonicalWithoutContext()),
            $this->digest(),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function digest(
        string $id = self::ID,
        string $occurredAt = '2026-08-13 09:00:00',
        ?string $actorId = 'actor-1',
        string $action = 'identity.user.activated',
        string $subjectType = 'identity.user',
        ?string $subjectId = 'subject-1',
        string $outcome = 'success',
        array $metadata = ['request_id' => 'request-1'],
    ): string {
        return AuditEventDigest::compute(
            $id,
            $occurredAt,
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            $outcome,
            $metadata,
        );
    }

    private function canonicalWithoutContext(): string
    {
        return json_encode([
            'action' => 'identity.user.activated',
            'actor_id' => 'actor-1',
            'id' => self::ID,
            'metadata' => ['request_id' => 'request-1'],
            'occurred_at' => '2026-08-13 09:00:00',
            'outcome' => 'success',
            'subject_id' => 'subject-1',
            'subject_type' => 'identity.user',
        ], JSON_THROW_ON_ERROR);
    }
}
