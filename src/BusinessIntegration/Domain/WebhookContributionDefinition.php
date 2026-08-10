<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Outbound-adapter declaration without credentials, URLs, or executable request configuration.
 *
 * The adapter identity resolves trusted code and deployment-owned configuration at runtime. Keeping
 * destinations and secrets out of the signed manifest avoids turning declarative contributions into
 * an SSRF or credential-disclosure surface.
 *
 * @since  2.0.0
 */
final readonly class WebhookContributionDefinition implements IntegrationContract
{
    /**
     * Versioned event types routed to this outbound adapter.
     *
     * @var    list<string>  Event types routed to this adapter.
     * @since  2.0.0
     */
    private array $eventTypes;

    /**
     * Exact schema versions accepted by the outbound adapter.
     *
     * @var    list<int>  Exact schema revisions accepted for every routed event type.
     * @since  2.0.0
     */
    private array $schemaVersions;

    /**
     * Declare one durable outbound adapter.
     *
     * @param   string               $adapterId           Namespaced outbound adapter identity.
     * @param   list<string>         $eventTypes          Non-empty event type allowlist.
     * @param   list<int>            $schemaVersions      Exact accepted schema revisions.
     * @param   string               $handlerVersion      Immutable executable revision.
     * @param   string               $queue               Declared logical delivery queue.
     * @param   ConsumerIdempotency  $idempotency         Receipt strategy required before the outbound effect runs.
     * @param   int                  $maximumAttempts     Attempt budget before quarantine.
     * @param   EventSensitivity     $sensitivityCeiling  Most sensitive event accepted by the boundary.
     *
     * @throws  InvalidArgumentException  When a declaration value is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $adapterId,
        array $eventTypes,
        array $schemaVersions,
        private string $handlerVersion,
        private string $queue,
        private ConsumerIdempotency $idempotency = ConsumerIdempotency::EVENT_ID,
        private int $maximumAttempts = 10,
        private EventSensitivity $sensitivityCeiling = EventSensitivity::INTERNAL,
    ) {
        IntegrationContractValidator::identifier($adapterId, 'Outbound adapter');
        IntegrationContractValidator::token($handlerVersion, 'Outbound adapter handler version', 64);
        IntegrationContractValidator::identifier($queue, 'Outbound adapter queue');
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('An outbound adapter attempt budget is invalid.');
        }
        $this->eventTypes = IntegrationContractValidator::identifiers($eventTypes, 'Outbound adapter event types');
        if ($schemaVersions === [] || count($schemaVersions) > 32) {
            throw new InvalidArgumentException('An outbound adapter must accept a bounded schema-version set.');
        }
        foreach ($schemaVersions as $version) {
            if (!is_int($version) || $version < 1 || $version > 65_535) {
                throw new InvalidArgumentException('An outbound adapter schema version is invalid.');
            }
        }
        $schemaVersions = array_values(array_unique($schemaVersions));
        sort($schemaVersions, SORT_NUMERIC);
        $this->schemaVersions = $schemaVersions;
    }

    /**
     * Return the stable identifier for the webhook contribution definition.
     *
     * @return  string  Namespaced outbound adapter identity.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->adapterId;
    }

    /**
     * Return the event types carried by this webhook contribution definition.
     *
     * @return  list<string>  Routed event types.
     *
     * @since   2.0.0
     */
    public function eventTypes(): array
    {
        return $this->eventTypes;
    }

    /**
     * Return the exact event schema versions accepted by this contribution.
     *
     * @return  list<int>  Exact accepted schema revisions.
     *
     * @since   2.0.0
     */
    public function schemaVersions(): array
    {
        return $this->schemaVersions;
    }

    /**
     * Determine whether this contribution accepts the supplied event contract.
     *
     * @param   string  $eventType      Stable namespaced type of the event.
     * @param   int     $schemaVersion  Exact payload schema version to test.
     *
     * @return  bool  Whether this adapter accepts an exact event contract.
     *
     * @since   2.0.0
     */
    public function accepts(string $eventType, int $schemaVersion): bool
    {
        return in_array($eventType, $this->eventTypes, true)
            && in_array($schemaVersion, $this->schemaVersions, true);
    }

    /**
     * Return the handler implementation version used for compatibility checks.
     *
     * @return  string  Handler revision.
     *
     * @since   2.0.0
     */
    public function handlerVersion(): string
    {
        return $this->handlerVersion;
    }

    /**
     * Return the declared durable queue identifier.
     *
     * @return  string  Logical queue identity.
     *
     * @since   2.0.0
     */
    public function queue(): string
    {
        return $this->queue;
    }

    /**
     * Return the maximum number of delivery attempts.
     *
     * @return  int  Attempt budget.
     *
     * @since   2.0.0
     */
    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    /**
     * Return the idempotency strategy required by this consumer.
     *
     * @return  ConsumerIdempotency  Required durable duplicate behavior.
     *
     * @since   2.0.0
     */
    public function idempotency(): ConsumerIdempotency
    {
        return $this->idempotency;
    }

    /**
     * Return the highest event sensitivity this contribution may receive.
     *
     * @return  EventSensitivity  Disclosure ceiling.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity
    {
        return $this->sensitivityCeiling;
    }

    /**
     * Serialize the webhook contribution definition for durable storage or inspection.
     *
     * @return  array<string, mixed>  Canonical publication representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'adapter_id' => $this->adapterId,
            'event_types' => $this->eventTypes,
            'schema_versions' => $this->schemaVersions,
            'handler_version' => $this->handlerVersion,
            'queue' => $this->queue,
            'idempotency' => $this->idempotency->value,
            'maximum_attempts' => $this->maximumAttempts,
            'sensitivity_ceiling' => $this->sensitivityCeiling->value,
        ];
    }

    /**
     * Reconstitute the webhook contribution definition from validated array data.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     *
     * @return  self  Validated adapter declaration.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'adapter_id',
            'event_types',
            'schema_versions',
            'handler_version',
            'queue',
            'idempotency',
            'maximum_attempts',
            'sensitivity_ceiling',
        ], 'Webhook contribution definition');
        $eventTypes = IntegrationContractValidator::listField($data, 'event_types');
        foreach ($eventTypes as $eventType) {
            if (!is_string($eventType)) {
                throw new InvalidArgumentException('A webhook event type must be a string.');
            }
        }
        /** @var list<string> $eventTypes */
        $schemaVersions = IntegrationContractValidator::listField($data, 'schema_versions');
        foreach ($schemaVersions as $version) {
            if (!is_int($version)) {
                throw new InvalidArgumentException('A webhook schema version must be an integer.');
            }
        }
        /** @var list<int> $schemaVersions */

        return new self(
            IntegrationContractValidator::string($data, 'adapter_id'),
            $eventTypes,
            $schemaVersions,
            IntegrationContractValidator::string($data, 'handler_version'),
            IntegrationContractValidator::string($data, 'queue'),
            ConsumerIdempotency::tryFrom(IntegrationContractValidator::string($data, 'idempotency'))
                ?? throw new InvalidArgumentException('A webhook idempotency behavior is invalid.'),
            IntegrationContractValidator::integer($data, 'maximum_attempts'),
            EventSensitivity::tryFrom(IntegrationContractValidator::string($data, 'sensitivity_ceiling'))
                ?? throw new InvalidArgumentException('A webhook sensitivity ceiling is invalid.'),
        );
    }
}
