<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class AuditEvent
{
    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param array<mixed> $metadata Values must be JSON-serializable.
     */
    public function __construct(
        private string $id,
        private DateTimeImmutable $occurredAt,
        private ?string $actorId,
        private string $action,
        private string $subjectType,
        private ?string $subjectId,
        private string $outcome,
        array $metadata = [],
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('The audit event ID must be a canonical UUID.');
        }

        if ($actorId !== null) {
            self::assertOpaqueId($actorId, 'actor');
        }

        if ($subjectId !== null) {
            self::assertOpaqueId($subjectId, 'subject');
        }

        self::assertIdentifier($action, 'action', 127);
        self::assertIdentifier($subjectType, 'subject type', 63);
        self::assertIdentifier($outcome, 'outcome', 31);

        foreach (array_keys($metadata) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Audit metadata must be an object with string keys.');
            }
        }

        try {
            json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Audit metadata must be JSON-serializable.', 0, $exception);
        }

        /** @var array<string, mixed> $metadata */
        $this->metadata = $metadata;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function metadataAsJson(): string
    {
        return json_encode($this->metadata, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    private static function assertOpaqueId(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s ID is invalid.', $field));
        }
    }

    private static function assertIdentifier(string $value, string $field, int $maxLength): void
    {
        if (strlen($value) > $maxLength || preg_match('/^[a-z][a-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The audit %s is invalid.', $field));
        }
    }
}
