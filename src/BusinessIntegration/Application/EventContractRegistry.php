<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\CanonicalJson;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventEnvelope;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;

/**
 * Immutable runtime catalog for exactly the event schemas and consumers in one trusted generation.
 *
 * @since  2.0.0
 */
final class EventContractRegistry
{
    /**
     * Versioned event schemas keyed by their canonical identifiers.
     *
     * @var    array<string, EventSchemaDefinition>  Schemas keyed by type and version.
     * @since  2.0.0
     */
    private array $schemas;

    /**
     * Durable consumers keyed by their stable identifiers.
     *
     * @var    array<string, EventConsumerDefinition>  Consumers keyed by identity.
     * @since  2.0.0
     */
    private array $consumers;

    /**
     * Compile data-only declarations into a collision-free runtime catalog.
     *
     * @param   iterable<EventSchemaDefinition>    $schemas    Active event schemas.
     * @param   iterable<EventConsumerDefinition>  $consumers  Active durable consumers.
     * @param   PayloadSchemaValidator             $validator  Validates schemas and event payloads.
     *
     * @throws  InvalidArgumentException  When identifiers collide or a consumer names an absent contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        iterable $schemas,
        iterable $consumers,
        private readonly PayloadSchemaValidator $validator = new PayloadSchemaValidator(),
    ) {
        $this->replace($schemas, $consumers);
    }

    /**
     * Atomically replace the catalog after the trusted contribution phase has completed.
     *
     * The container creates this shared instance before providers so extension handlers can depend on
     * services which themselves publish events. Once every signed provider has reconciled, the active set
     * replaces the provisional core-only catalog. Existing publishers and stores retain this object and
     * therefore observe the exact completed runtime generation without service-location or a second graph.
     *
     * @param   iterable<EventSchemaDefinition>    $schemas    Complete active event schema set.
     * @param   iterable<EventConsumerDefinition>  $consumers  Complete active durable consumer set.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When identifiers collide or a consumer names an absent contract.
     *
     * @since   2.0.0
     */
    public function replace(iterable $schemas, iterable $consumers): void
    {
        $schemaMap = [];
        foreach ($schemas as $schema) {
            $this->validator->assertSchema($schema->payloadSchema());
            if (isset($schemaMap[$schema->identifier()])) {
                throw new InvalidArgumentException('Two event schemas claim the same type and version.');
            }
            $schemaMap[$schema->identifier()] = $schema;
        }
        ksort($schemaMap, SORT_STRING);

        $consumerMap = [];
        foreach ($consumers as $consumer) {
            if (isset($consumerMap[$consumer->identifier()])) {
                throw new InvalidArgumentException('Two event consumers claim the same identity.');
            }
            foreach ($consumer->schemaVersions() as $version) {
                $key = $consumer->eventType() . '@' . $version;
                $schema = $schemaMap[$key] ?? null;
                if (!$schema instanceof EventSchemaDefinition) {
                    throw new InvalidArgumentException('An event consumer names an unavailable schema revision.');
                }
                if (!$schema->sensitivity()->allowedBy($consumer->sensitivityCeiling())) {
                    throw new InvalidArgumentException('An event consumer sensitivity ceiling is too low.');
                }
            }
            $consumerMap[$consumer->identifier()] = $consumer;
        }
        ksort($consumerMap, SORT_STRING);
        $this->schemas = $schemaMap;
        $this->consumers = $consumerMap;
    }

    /**
     * Resolve one exact event contract.
     *
     * @param   string  $eventType      Stable event type.
     * @param   int     $schemaVersion  Exact payload revision.
     *
     * @return  EventSchemaDefinition  Registered definition.
     *
     * @throws  InvalidArgumentException  When the exact revision is unavailable.
     *
     * @since   2.0.0
     */
    public function schema(string $eventType, int $schemaVersion): EventSchemaDefinition
    {
        return $this->schemas[$eventType . '@' . $schemaVersion]
            ?? throw new InvalidArgumentException('The event schema revision is not registered.');
    }

    /**
     * Validate metadata, disclosure and payload against the exact registered schema.
     *
     * @param   EventEnvelope  $event  Event entering dispatch or durable storage.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the event violates its declared contract.
     *
     * @since   2.0.0
     */
    public function assertEvent(EventEnvelope $event): void
    {
        $schema = $this->schema($event->eventType(), $event->schemaVersion());
        if (!$schema->sensitivity()->allowedBy($event->sensitivity())) {
            throw new InvalidArgumentException('The event is classified below its schema minimum.');
        }
        if (strlen(CanonicalJson::encode($event->payload())) > $schema->maximumBytes()) {
            throw new InvalidArgumentException('The event payload exceeds its contract size ceiling.');
        }
        $this->validator->assertPayload($schema->payloadSchema(), $event->payload());
    }

    /**
     * Return the consumer carried by this event contract registry.
     *
     * @param   string  $consumerId  Stable consumer identifier used to scope receipt history.
     *
     * @return  EventConsumerDefinition  Registered consumer.
     *
     * @since   2.0.0
     */
    public function consumer(string $consumerId): EventConsumerDefinition
    {
        return $this->consumers[$consumerId]
            ?? throw new InvalidArgumentException('The event consumer is not registered.');
    }

    /**
     * Return the consumers for carried by this event contract registry.
     *
     * @param   EventEnvelope  $event  Versioned event being validated or processed.
     *
     * @return  list<EventConsumerDefinition>  Consumers accepting this exact event revision.
     *
     * @since   2.0.0
     */
    public function consumersFor(EventEnvelope $event): array
    {
        return array_values(array_filter(
            $this->consumers,
            static fn (EventConsumerDefinition $consumer): bool => $consumer->eventType() === $event->eventType()
                && $consumer->acceptsVersion($event->schemaVersion())
                && $event->sensitivity()->allowedBy($consumer->sensitivityCeiling()),
        ));
    }
}
