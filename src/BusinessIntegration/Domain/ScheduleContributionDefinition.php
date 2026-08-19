<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\CronExpression;

/**
 * Declarative recurring schedule compiled with its owning runtime generation.
 *
 * @since  2.0.0
 */
final readonly class ScheduleContributionDefinition implements IntegrationContract
{
    /**
     * Validated job payload submitted by each recurring execution.
     *
     * @var    array<string, mixed>  Validated job arguments.
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Define one contributed recurring job.
     *
     * @param   string                $scheduleId      Namespaced schedule identity.
     * @param   string                $jobType         Contributed job type.
     * @param   string                $cronExpression  Five-field cron expression.
     * @param   string                $timezone        IANA timezone.
     * @param   array<string, mixed>  $payload         Job arguments.
     * @param   string                $queue           Destination queue.
     * @param   ?string               $siteIdentifier  Owning site for site jobs; null for installation jobs.
     * @param   bool                  $enabled         Initial enabled state.
     *
     * @throws  InvalidArgumentException  When a declaration value is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $scheduleId,
        private string $jobType,
        private string $cronExpression,
        private string $timezone,
        array $payload,
        private string $queue = 'default',
        private ?string $siteIdentifier = null,
        private bool $enabled = true,
    ) {
        IntegrationContractValidator::identifier($scheduleId, 'Schedule');
        IntegrationContractValidator::identifier($jobType, 'Scheduled job type');
        IntegrationContractValidator::token($queue, 'Schedule queue', 64);
        if ($siteIdentifier !== null) {
            IntegrationContractValidator::token($siteIdentifier, 'Schedule site');
        }
        IntegrationContractValidator::object($payload, 'Schedule payload', EventEnvelope::MAX_PAYLOAD_BYTES);
        new CronExpression($cronExpression);
        try {
            new DateTimeZone($timezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('The schedule timezone is invalid.', 0, $exception);
        }
        $this->payload = $payload;
    }

    /**
     * Return the stable identifier for the schedule contribution definition.
     *
     * @return  string  Schedule identity.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->scheduleId;
    }

    /**
     * Return the job type carried by this schedule contribution definition.
     *
     * @return  string  Contributed job type.
     *
     * @since   2.0.0
     */
    public function jobType(): string
    {
        return $this->jobType;
    }

    /**
     * Return the cron expression carried by this schedule contribution definition.
     *
     * @return  string  Five-field recurrence.
     *
     * @since   2.0.0
     */
    public function cronExpression(): string
    {
        return $this->cronExpression;
    }

    /**
     * Return the timezone carried by this schedule contribution definition.
     *
     * @return  string  IANA timezone.
     *
     * @since   2.0.0
     */
    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Return the validated payload.
     *
     * @return  array<string, mixed>  Validated job arguments.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Return the declared durable queue identifier.
     *
     * @return  string  Logical queue.
     *
     * @since   2.0.0
     */
    public function queue(): string
    {
        return $this->queue;
    }

    /**
     * Return the site identifier carried by this schedule contribution definition.
     *
     * @return  ?string  Explicit site, null only for installation-wide jobs.
     *
     * @since   2.0.0
     */
    public function siteIdentifier(): ?string
    {
        return $this->siteIdentifier;
    }

    /**
     * Return the enabled carried by this schedule contribution definition.
     *
     * @return  bool  Whether occurrences are initially enabled.
     *
     * @since   2.0.0
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Serialize the schedule contribution definition for durable storage or inspection.
     *
     * @return  array<string, mixed>  Canonical publication representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'job_type' => $this->jobType,
            'cron_expression' => $this->cronExpression,
            'timezone' => $this->timezone,
            'payload' => $this->payload,
            'queue' => $this->queue,
            'site_identifier' => $this->siteIdentifier,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Parse the closed manifest representation of a schedule contribution.
     *
     * @param   array<string, mixed>  $data  Manifest contribution object.
     *
     * @return  self  Validated schedule definition.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'schedule_id', 'job_type', 'cron_expression', 'timezone', 'payload', 'queue',
            'site_identifier', 'enabled',
        ], 'Schedule contribution definition');
        return new self(
            IntegrationContractValidator::string($data, 'schedule_id'),
            IntegrationContractValidator::string($data, 'job_type'),
            IntegrationContractValidator::string($data, 'cron_expression'),
            IntegrationContractValidator::string($data, 'timezone'),
            IntegrationContractValidator::objectField($data, 'payload'),
            IntegrationContractValidator::string($data, 'queue'),
            isset($data['site_identifier'])
                ? IntegrationContractValidator::string($data, 'site_identifier')
                : null,
            IntegrationContractValidator::boolean($data, 'enabled'),
        );
    }
}
