<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Audit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvent::class)]
final class AuditEventTest extends TestCase
{
    public function testCarriesAnExplicitImmutableAuditRecord(): void
    {
        $occurredAt = new DateTimeImmutable('2026-08-04T14:00:00+00:00');
        $event = new AuditEvent(
            '8bd4ec65-92f2-4934-afb8-b22a3cf956cd',
            $occurredAt,
            '4f52fd0a-7296-4c4a-8e5d-85bc600f9718',
            'identity.user.activated',
            'identity.user',
            'e3df7938-d6a8-4c01-9baa-5d1a9b2c5b67',
            'allowed',
            ['request_id' => 'request-1'],
        );

        self::assertSame('identity.user.activated', $event->action());
        self::assertSame('identity.user', $event->subjectType());
        self::assertSame('allowed', $event->outcome());
        self::assertSame($occurredAt, $event->occurredAt());
        self::assertSame(['request_id' => 'request-1'], $event->metadata());
        self::assertSame('{"request_id":"request-1"}', $event->metadataAsJson());
    }

    public function testRejectsMetadataThatIsNotAJsonObject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEvent(
            '8bd4ec65-92f2-4934-afb8-b22a3cf956cd',
            new DateTimeImmutable(),
            null,
            'identity.user.read',
            'identity.user',
            null,
            'allowed',
            ['list-entry'],
        );
    }

    public function testRejectsInvalidOutcomeProse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEvent(
            '8bd4ec65-92f2-4934-afb8-b22a3cf956cd',
            new DateTimeImmutable(),
            null,
            'identity.user.read',
            'identity.user',
            null,
            'Access granted',
        );
    }
}
