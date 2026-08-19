<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use Kumwe\App\BusinessIntegration\Domain\EventEnvelope;

/**
 * Keeps bounded, non-authoritative diagnostics for the example's executable integration handlers.
 *
 * Core owns durable outbox, inbox, job, and projection state. This process-local ledger exists only so
 * the graphical proof page can show that handlers ran; it stores event identities and hashes, never record
 * payload values, and evicts the oldest observation at a fixed bound.
 *
 * @since  2.0.0
 */
final class IntegrationLedger
{
    /**
     * Maximum observations retained by each process-local diagnostic set.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_OBSERVATIONS = 128;

    /**
     * Transaction-local event sites keyed by immutable event identity.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $domainEvents = [];

    /**
     * Inbox-processed event sites keyed by immutable event identity.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $integrationEvents = [];

    /**
     * Latest non-reversible job input digest by site.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $jobDigests = [];

    /**
     * Record one inspection mutation observed inside the authoritative transaction.
     *
     * @param   EventEnvelope  $event  Already contract-validated core mutation event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordDomain(EventEnvelope $event): void
    {
        $this->remember($this->domainEvents, $event->eventId(), $event->siteIdentifier());
    }

    /**
     * Record one inspection mutation after durable inbox deduplication.
     *
     * @param   EventEnvelope  $event  Already contract-validated integration event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordIntegration(EventEnvelope $event): void
    {
        $this->remember($this->integrationEvents, $event->eventId(), $event->siteIdentifier());
    }

    /**
     * Record a stable digest of a validated scheduled review window.
     *
     * @param   string  $siteIdentifier  Site whose example review window was checked.
     * @param   int     $minimumAgeDays  Minimum outstanding age selected by the job.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordJob(string $siteIdentifier, int $minimumAgeDays): void
    {
        $this->jobDigests[$siteIdentifier] = hash('sha256', $siteIdentifier . ':' . $minimumAgeDays);
        ksort($this->jobDigests, SORT_STRING);
        while (count($this->jobDigests) > self::MAXIMUM_OBSERVATIONS) {
            array_shift($this->jobDigests);
        }
    }

    /**
     * Return site-filtered, non-sensitive diagnostic evidence for a contributed page.
     *
     * @param   string  $siteIdentifier  Exact site selected by the execution context.
     *
     * @return  array{domain_events: int, integration_events: int, latest_job_digest: ?string}
     *          Bounded counts and an optional non-reversible job digest.
     *
     * @since   2.0.0
     */
    public function snapshot(string $siteIdentifier): array
    {
        return [
            'domain_events' => count(array_filter(
                $this->domainEvents,
                static fn (string $site): bool => $site === $siteIdentifier,
            )),
            'integration_events' => count(array_filter(
                $this->integrationEvents,
                static fn (string $site): bool => $site === $siteIdentifier,
            )),
            'latest_job_digest' => $this->jobDigests[$siteIdentifier] ?? null,
        ];
    }

    /**
     * Insert an idempotent observation and enforce the fixed retention bound.
     *
     * @param   array<string, string>  $observations  Diagnostic map mutated in place.
     * @param   string                 $eventId       Immutable event identity used as the duplicate key.
     * @param   string                 $site          Site retained for row-level filtering.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function remember(array &$observations, string $eventId, string $site): void
    {
        $observations[$eventId] = $site;
        while (count($observations) > self::MAXIMUM_OBSERVATIONS) {
            array_shift($observations);
        }
    }
}
