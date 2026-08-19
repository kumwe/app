<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Immutable optimistic state of one generic long-running process.
 *
 * @since  2.0.0
 */
final readonly class ProcessInstance
{
    /**
     * Validated process-manager state captured at the current version.
     *
     * @var    array<string, mixed>  Bounded process-defined state.
     * @since  2.0.0
     */
    private array $state;

    /**
     * Build a complete persisted process snapshot.
     *
     * @param   string                $id                Canonical process UUID.
     * @param   string                $processType       Namespaced process contract.
     * @param   string                $correlationId     Unique correlation within the process type.
     * @param   string                $siteIdentifier    Owning site.
     * @param   ?string               $organizationId    Owning organization when applicable.
     * @param   ?string               $actorId           Starting human actor.
     * @param   ?string               $systemIdentity    Starting system actor.
     * @param   int                   $version           Optimistic state version.
     * @param   ProcessStatus         $status            Current lifecycle state.
     * @param   array<string, mixed>  $state             Bounded process-defined JSON object.
     * @param   DateTimeImmutable     $createdAt         Start instant.
     * @param   DateTimeImmutable     $updatedAt         Last transition instant.
     * @param   ?string               $cancellationBy    Operator identity that cancelled it.
     * @param   ?string               $cancellationNote  Bounded cancellation rationale.
     *
     * @throws  InvalidArgumentException  When state or metadata violates an invariant.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        private string $processType,
        private string $correlationId,
        private string $siteIdentifier,
        private ?string $organizationId,
        private ?string $actorId,
        private ?string $systemIdentity,
        private int $version,
        private ProcessStatus $status,
        array $state,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?string $cancellationBy = null,
        private ?string $cancellationNote = null,
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidArgumentException('A process ID must be a UUID.');
        }
        IntegrationContractValidator::identifier($processType, 'Process type');
        IntegrationContractValidator::token($correlationId, 'Process correlation');
        IntegrationContractValidator::token($siteIdentifier, 'Process site');
        if ($organizationId !== null) {
            IntegrationContractValidator::token($organizationId, 'Process organization');
        }
        if (($actorId === null) === ($systemIdentity === null)) {
            throw new InvalidArgumentException('A process requires exactly one starting actor identity.');
        }
        IntegrationContractValidator::token($actorId ?? $systemIdentity ?? '', 'Process actor');
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new InvalidArgumentException('A process version or lifecycle instant is invalid.');
        }
        if (($status === ProcessStatus::CANCELLED) !== ($cancellationBy !== null)) {
            throw new InvalidArgumentException('Only a cancelled process carries cancellation attribution.');
        }
        if ($cancellationNote !== null && (trim($cancellationNote) === '' || mb_strlen($cancellationNote) > 1_000)) {
            throw new InvalidArgumentException('A process cancellation note must contain 1 to 1000 characters.');
        }
        IntegrationContractValidator::object($state, 'Process state', EventEnvelope::MAX_PAYLOAD_BYTES);
        $this->state = $state;
    }

    /**
     * Return the ID carried by this process instance.
     *
     * @return  string  Process UUID.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the stable process-manager type.
     *
     * @return  string  Process contract.
     *
     * @since   2.0.0
     */
    public function processType(): string
    {
        return $this->processType;
    }

    /**
     * Derive the process correlation identifier from the event.
     *
     * @return  string  Process correlation identity.
     *
     * @since   2.0.0
     */
    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Return the site identifier carried by this process instance.
     *
     * @return  string  Owning site.
     *
     * @since   2.0.0
     */
    public function siteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    /**
     * Return the organization ID carried by this process instance.
     *
     * @return  ?string  Owning organization.
     *
     * @since   2.0.0
     */
    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Return the actor ID carried by this process instance.
     *
     * @return  ?string  Starting human actor.
     *
     * @since   2.0.0
     */
    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Return the system identity carried by this process instance.
     *
     * @return  ?string  Starting system actor.
     *
     * @since   2.0.0
     */
    public function systemIdentity(): ?string
    {
        return $this->systemIdentity;
    }

    /**
     * Return the version carried by this process instance.
     *
     * @return  int  Optimistic state version.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Return the status carried by this process instance.
     *
     * @return  ProcessStatus  Current lifecycle state.
     *
     * @since   2.0.0
     */
    public function status(): ProcessStatus
    {
        return $this->status;
    }

    /**
     * Return the state carried by this process instance.
     *
     * @return  array<string, mixed>  Process-defined state.
     *
     * @since   2.0.0
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Return the created at carried by this process instance.
     *
     * @return  DateTimeImmutable  Start instant.
     *
     * @since   2.0.0
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Return the updated at carried by this process instance.
     *
     * @return  DateTimeImmutable  Last transition instant.
     *
     * @since   2.0.0
     */
    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Return the cancellation by carried by this process instance.
     *
     * @return  ?string  Cancelling operator identity.
     *
     * @since   2.0.0
     */
    public function cancellationBy(): ?string
    {
        return $this->cancellationBy;
    }

    /**
     * Return the cancellation note carried by this process instance.
     *
     * @return  ?string  Cancellation rationale.
     *
     * @since   2.0.0
     */
    public function cancellationNote(): ?string
    {
        return $this->cancellationNote;
    }

    /**
     * Advance the process by exactly one optimistic version.
     *
     * @param   array<string, mixed>  $state      Replacement process-defined state.
     * @param   ProcessStatus         $status     New lifecycle state.
     * @param   DateTimeImmutable     $updatedAt  Transition instant.
     *
     * @return  self  Next immutable snapshot.
     *
     * @since   2.0.0
     */
    public function transition(array $state, ProcessStatus $status, DateTimeImmutable $updatedAt): self
    {
        if ($this->status !== ProcessStatus::RUNNING) {
            throw new InvalidArgumentException('A terminal process cannot transition again.');
        }
        return new self(
            $this->id,
            $this->processType,
            $this->correlationId,
            $this->siteIdentifier,
            $this->organizationId,
            $this->actorId,
            $this->systemIdentity,
            $this->version + 1,
            $status,
            $state,
            $this->createdAt,
            $updatedAt,
        );
    }

    /**
     * Cancel a running process while retaining its last state for operator inspection.
     *
     * @param   string             $operatorId  Cancelling operator identity.
     * @param   string             $note        Bounded rationale.
     * @param   DateTimeImmutable  $updatedAt   Cancellation instant.
     *
     * @return  self  Cancelled next snapshot.
     *
     * @since   2.0.0
     */
    public function cancel(string $operatorId, string $note, DateTimeImmutable $updatedAt): self
    {
        if ($this->status !== ProcessStatus::RUNNING) {
            throw new InvalidArgumentException('Only a running process may be cancelled.');
        }
        return new self(
            $this->id,
            $this->processType,
            $this->correlationId,
            $this->siteIdentifier,
            $this->organizationId,
            $this->actorId,
            $this->systemIdentity,
            $this->version + 1,
            ProcessStatus::CANCELLED,
            $this->state,
            $this->createdAt,
            $updatedAt,
            $operatorId,
            $note,
        );
    }
}
