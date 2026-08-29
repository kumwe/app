<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator;
use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Immutable timer, command or compensation request emitted by one process transition.
 *
 * @since  2.0.0
 */
final readonly class ProcessWorkItem
{
    /**
     * Validated work payload kept private until dispatch.
     *
     * @var    array<string, mixed>  Bounded handler payload.
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Define a durable process effect.
     *
     * @param   string                $id               Canonical work UUID and idempotency key.
     * @param   ProcessWorkKind       $kind             Timer, command or compensation.
     * @param   string                $name             Namespaced handler contract.
     * @param   array<string, mixed>  $payload          Bounded JSON object.
     * @param   DateTimeImmutable     $dueAt            Earliest execution instant.
     * @param   int                   $maximumAttempts  Attempt budget.
     *
     * @throws  InvalidArgumentException  When identity, payload or attempt budget is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        private ProcessWorkKind $kind,
        private string $name,
        array $payload,
        private DateTimeImmutable $dueAt,
        private int $maximumAttempts = 10,
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidArgumentException('A process work item ID must be a UUID.');
        }
        IntegrationContractValidator::identifier($name, 'Process work name');
        IntegrationContractValidator::object(
            $payload,
            'Process work payload',
            RecordedEventEnvelope::MAX_PAYLOAD_BYTES,
        );
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('A process work attempt budget must be between 1 and 100.');
        }
        $this->payload = $payload;
    }

    /**
     * Return the ID carried by this process work item.
     *
     * @return  string  Work UUID.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the kind carried by this process work item.
     *
     * @return  ProcessWorkKind  Work classification.
     *
     * @since   2.0.0
     */
    public function kind(): ProcessWorkKind
    {
        return $this->kind;
    }

    /**
     * Return the handler or job name carried by this work item.
     *
     * @return  string  Handler contract name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the validated payload.
     *
     * @return  array<string, mixed>  Handler payload.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Return the due at carried by this process work item.
     *
     * @return  DateTimeImmutable  Earliest execution instant.
     *
     * @since   2.0.0
     */
    public function dueAt(): DateTimeImmutable
    {
        return $this->dueAt;
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
}
