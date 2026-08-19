<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Synchronous domain-listener declaration compiled into the trusted runtime generation.
 *
 * A listener executes inside the authoritative transaction and therefore has no retry contract of
 * its own: throwing aborts the mutation. Remote calls and other fallible side effects belong in a
 * durable consumer or job instead.
 *
 * @since  2.0.0
 */
final readonly class DomainListenerDefinition implements IntegrationContract
{
    /**
     * Exact event schema versions accepted by the synchronous listener.
     *
     * @var    list<int>  Exact schema revisions accepted by this listener.
     * @since  2.0.0
     */
    private array $schemaVersions;

    /**
     * Declare one synchronous listener.
     *
     * @param   string            $listenerId          Globally namespaced listener identity.
     * @param   string            $eventType           Event contract listened to.
     * @param   list<int>         $schemaVersions      Explicitly accepted schema revisions.
     * @param   string            $handlerVersion      Immutable executable revision.
     * @param   int               $priority            Deterministic order, from -1000 through 1000.
     * @param   EventSensitivity  $sensitivityCeiling  Most sensitive event this listener accepts.
     *
     * @throws  InvalidArgumentException  When a declaration field is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $listenerId,
        private string $eventType,
        array $schemaVersions,
        private string $handlerVersion,
        private int $priority = 0,
        private EventSensitivity $sensitivityCeiling = EventSensitivity::INTERNAL,
    ) {
        IntegrationContractValidator::identifier($listenerId, 'Domain listener');
        IntegrationContractValidator::identifier($eventType, 'Domain listener event type');
        IntegrationContractValidator::token($handlerVersion, 'Domain listener handler version', 64);
        foreach ($schemaVersions as $version) {
            if (!is_int($version) || $version < 1 || $version > 65_535) {
                throw new InvalidArgumentException('A domain listener schema version is invalid.');
            }
        }
        $schemaVersions = array_values(array_unique($schemaVersions));
        sort($schemaVersions, SORT_NUMERIC);
        if ($schemaVersions === [] || $priority < -1000 || $priority > 1000) {
            throw new InvalidArgumentException('A domain listener needs versions and a bounded priority.');
        }
        $this->schemaVersions = $schemaVersions;
    }

    /**
     * Return the stable identifier for the domain listener definition.
     *
     * @return  string  Namespaced listener identity.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->listenerId;
    }

    /**
     * Return the versioned event type accepted by this contract.
     *
     * @return  string  Event type.
     *
     * @since   2.0.0
     */
    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * Return the exact event schema versions accepted by this contribution.
     *
     * @return  list<int>  Accepted schema revisions.
     *
     * @since   2.0.0
     */
    public function schemaVersions(): array
    {
        return $this->schemaVersions;
    }

    /**
     * Return the handler implementation version used for compatibility checks.
     *
     * @return  string  Executable revision.
     *
     * @since   2.0.0
     */
    public function handlerVersion(): string
    {
        return $this->handlerVersion;
    }

    /**
     * Return the priority carried by this domain listener definition.
     *
     * @return  int  Deterministic dispatch priority.
     *
     * @since   2.0.0
     */
    public function priority(): int
    {
        return $this->priority;
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
     * Determine whether this contribution accepts the supplied event contract.
     *
     * @param   string            $eventType      Stable namespaced type of the event.
     * @param   int               $schemaVersion  Exact payload schema version to test.
     * @param   EventSensitivity  $sensitivity    Event sensitivity level to compare with the ceiling.
     *
     * @return  bool  Whether this listener accepts the exact event contract revision.
     *
     * @since   2.0.0
     */
    public function accepts(string $eventType, int $schemaVersion, EventSensitivity $sensitivity): bool
    {
        return $this->eventType === $eventType
            && in_array($schemaVersion, $this->schemaVersions, true)
            && $sensitivity->allowedBy($this->sensitivityCeiling);
    }

    /**
     * Serialize the domain listener definition for durable storage or inspection.
     *
     * @return  array<string, mixed>  Canonical publication representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'listener_id' => $this->listenerId,
            'event_type' => $this->eventType,
            'schema_versions' => $this->schemaVersions,
            'handler_version' => $this->handlerVersion,
            'priority' => $this->priority,
            'sensitivity_ceiling' => $this->sensitivityCeiling->value,
        ];
    }

    /**
     * Rehydrate one strict manifest declaration.
     *
     * @param   array<string, mixed>  $data  Exact canonical declaration.
     *
     * @return  self  Validated listener definition.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'listener_id',
            'event_type',
            'schema_versions',
            'handler_version',
            'priority',
            'sensitivity_ceiling',
        ], 'Domain listener definition');
        $versions = IntegrationContractValidator::listField($data, 'schema_versions');
        foreach ($versions as $version) {
            if (!is_int($version)) {
                throw new InvalidArgumentException('A domain listener schema version must be an integer.');
            }
        }
        /** @var list<int> $versions */

        return new self(
            IntegrationContractValidator::string($data, 'listener_id'),
            IntegrationContractValidator::string($data, 'event_type'),
            $versions,
            IntegrationContractValidator::string($data, 'handler_version'),
            IntegrationContractValidator::integer($data, 'priority'),
            EventSensitivity::tryFrom(IntegrationContractValidator::string($data, 'sensitivity_ceiling'))
                ?? throw new InvalidArgumentException('A domain listener sensitivity ceiling is invalid.'),
        );
    }
}
