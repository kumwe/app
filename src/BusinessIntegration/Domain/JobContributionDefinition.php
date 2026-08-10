<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Declarative job handler and payload contract for trusted runtime compilation.
 *
 * @since  2.0.0
 */
final readonly class JobContributionDefinition implements IntegrationContract
{
    /** @var array<string, mixed> Declarative payload schema. @since 2.0.0 */
    private array $payloadSchema;

    /**
     * Define one contributed job type.
     *
     * @param   string                $jobType           Namespaced job type.
     * @param   int                   $schemaVersion     Payload schema revision.
     * @param   string                $handlerVersion    Executable handler revision.
     * @param   array<string, mixed>  $payloadSchema     Declarative JSON Schema subset.
     * @param   string                $queue             Default queue.
     * @param   int                   $maximumAttempts   Default retry budget.
     * @param   bool                  $installationWide  Whether work is installation rather than site scoped.
     *
     * @throws  InvalidArgumentException  When a declaration value is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $jobType,
        private int $schemaVersion,
        private string $handlerVersion,
        array $payloadSchema,
        private string $queue = 'default',
        private int $maximumAttempts = 5,
        private bool $installationWide = false,
    ) {
        IntegrationContractValidator::identifier($jobType, 'Job type');
        IntegrationContractValidator::token($handlerVersion, 'Job handler version', 64);
        IntegrationContractValidator::token($queue, 'Job queue', 64);
        IntegrationContractValidator::object($payloadSchema, 'Job payload schema');
        if ($schemaVersion < 1 || $schemaVersion > 65_535 || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('The job schema version or attempt budget is invalid.');
        }
        $this->payloadSchema = $payloadSchema;
    }

    /** @return string Job type. @since 2.0.0 */
    public function identifier(): string
    {
        return $this->jobType;
    }

    /** @return int Payload schema revision. @since 2.0.0 */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /** @return string Executable handler revision. @since 2.0.0 */
    public function handlerVersion(): string
    {
        return $this->handlerVersion;
    }

    /** @return array<string, mixed> Declarative payload schema. @since 2.0.0 */
    public function payloadSchema(): array
    {
        return $this->payloadSchema;
    }

    /** @return string Default logical queue. @since 2.0.0 */
    public function queue(): string
    {
        return $this->queue;
    }

    /** @return int Retry attempt budget. @since 2.0.0 */
    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    /** @return bool Whether the handler executes outside a site scope. @since 2.0.0 */
    public function installationWide(): bool
    {
        return $this->installationWide;
    }

    /** @return array<string, mixed> Canonical publication representation. @since 2.0.0 */
    public function toArray(): array
    {
        return [
            'job_type' => $this->jobType,
            'schema_version' => $this->schemaVersion,
            'handler_version' => $this->handlerVersion,
            'payload_schema' => $this->payloadSchema,
            'queue' => $this->queue,
            'maximum_attempts' => $this->maximumAttempts,
            'installation_wide' => $this->installationWide,
        ];
    }

    /**
     * Parse the closed manifest representation of a job contribution.
     *
     * @param   array<string, mixed>  $data  Manifest contribution object.
     *
     * @return  self  Validated job definition.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'job_type', 'schema_version', 'handler_version', 'payload_schema', 'queue',
            'maximum_attempts', 'installation_wide',
        ], 'Job contribution definition');
        return new self(
            IntegrationContractValidator::string($data, 'job_type'),
            IntegrationContractValidator::integer($data, 'schema_version'),
            IntegrationContractValidator::string($data, 'handler_version'),
            IntegrationContractValidator::objectField($data, 'payload_schema'),
            IntegrationContractValidator::string($data, 'queue'),
            IntegrationContractValidator::integer($data, 'maximum_attempts'),
            IntegrationContractValidator::boolean($data, 'installation_wide'),
        );
    }
}
