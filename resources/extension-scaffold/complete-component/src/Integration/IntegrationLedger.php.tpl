<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Integration;

use Kumwe\App\BusinessIntegration\Domain\DomainEvent;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;

/**
 * Keeps bounded process-local evidence that each generated integration handler executed.
 *
 * Durable delivery and projection state remain core-owned. This ledger is intentionally diagnostic:
 * event identities are deduplicated and the overview exposes only counts and the latest job digest.
 *
 * @since  2.0.0
 */
final class IntegrationLedger
{
    /**
     * Domain event identities observed in this runtime process.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $domainEvents = [];

    /**
     * Durable event identities observed in this runtime process.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $integrationEvents = [];

    /**
     * Digest of the most recently validated job message.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $latestJobDigest = null;

    /**
     * Record one transaction-local event once by immutable identity.
     *
     * @param   DomainEvent  $event  Validated owned event delivered by the domain dispatcher.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordDomain(DomainEvent $event): void
    {
        $this->domainEvents[$event->eventId()] = true;
    }

    /**
     * Record one durable event once by immutable identity.
     *
     * @param   IntegrationEvent  $event  Inbox-deduplicated owned integration event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordIntegration(IntegrationEvent $event): void
    {
        $this->integrationEvents[$event->eventId()] = true;
    }

    /**
     * Record a non-reversible digest rather than retaining job payload text.
     *
     * @param   string  $message  Validated bounded job message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordJob(string $message): void
    {
        $this->latestJobDigest = hash('sha256', $message);
    }

    /**
     * Return safe diagnostics for the contributed overview pages.
     *
     * @return  array{domain_events: int, integration_events: int, latest_job_digest: ?string}
     *          Bounded process-local integration evidence.
     *
     * @since   2.0.0
     */
    public function snapshot(): array
    {
        return [
            'domain_events' => count($this->domainEvents),
            'integration_events' => count($this->integrationEvents),
            'latest_job_digest' => $this->latestJobDigest,
        ];
    }
}
