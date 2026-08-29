<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\RecordedDomainEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(RecordedEventEnvelope::class)]
#[CoversClass(RecordedDomainEvent::class)]
#[CoversClass(RecordedIntegrationEvent::class)]
#[CoversClass(EventContractRegistry::class)]
final class EventContractTest extends TestCase
{
    public function testEnvelopeRoundTripsWithoutChangingTheEventIdentity(): void
    {
        $domain = $this->event(['record_id' => 'record-7', 'changed_fields' => ['name']]);
        $integration = RecordedIntegrationEvent::fromDomain($domain);
        $restored = RecordedIntegrationEvent::fromArray($integration->toArray());

        self::assertSame($domain->eventId(), $integration->eventId());
        self::assertSame($integration->toArray(), $restored->toArray());
        self::assertSame('actor-1', $restored->actorId());
        self::assertNull($restored->systemIdentity());
        self::assertSame(EventSensitivity::INTERNAL, $restored->sensitivity());
    }

    public function testEnvelopeRejectsAmbiguousIdentityAndListPayloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordedDomainEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            'actor-1',
            'worker',
            'default',
            null,
            'business.record',
            'record-7',
            3,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['list-value'],
        );
    }

    public function testStoredEnvelopeRejectsUnknownFields(): void
    {
        $stored = $this->event(['record_id' => 'record-7'])->toArray();
        $stored['unexpected'] = true;

        $this->expectException(InvalidArgumentException::class);
        RecordedIntegrationEvent::fromArray($stored);
    }

    public function testStoredEnvelopeRejectsNonCanonicalTimestamps(): void
    {
        $stored = $this->event(['record_id' => 'record-7'])->toArray();
        $stored['occurred_at'] = '2026-08-10 10:00:00 UTC';

        $this->expectException(InvalidArgumentException::class);
        RecordedIntegrationEvent::fromArray($stored);
    }

    public function testRegistryEnforcesExactSchemaAndPayloadContract(): void
    {
        $schema = $this->schema();
        $consumer = new EventConsumerDefinition(
            'acme.search-index',
            'business.record.changed',
            [1],
            '1.0.0',
        );
        $registry = new EventContractRegistry([$schema], [$consumer]);
        $registry->assertEvent($this->event(['record_id' => 'record-7']));
        self::assertSame($consumer, $registry->consumer('acme.search-index'));

        $this->expectException(InvalidArgumentException::class);
        $registry->assertEvent($this->event(['unknown' => true]));
    }

    /** @return EventSchemaDefinition Test event contract. */
    private function schema(): EventSchemaDefinition
    {
        return new EventSchemaDefinition(
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );
    }

    /** @param array<string, mixed> $payload @return RecordedDomainEvent Test event. */
    private function event(array $payload): RecordedDomainEvent
    {
        return new RecordedDomainEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            'actor-1',
            null,
            'default',
            'organization-1',
            'business.record',
            'record-7',
            3,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            $payload,
        );
    }
}
