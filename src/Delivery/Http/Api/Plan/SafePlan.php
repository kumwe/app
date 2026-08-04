<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SafePlan
{
    public function __construct(
        private string $id,
        private SafePlanOperation $operation,
        private string $target,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('A safe plan ID must be a canonical UUIDv7.');
        }

        if (trim($target) === '' || strlen($target) > 255 || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw new InvalidArgumentException('A safe plan target must contain 1 to 255 printable characters.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A safe plan must expire after it is created.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, bool|string|list<string>> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mode' => 'plan_only',
            'operation' => $this->operation->value,
            'target' => $this->target,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'steps' => [
                'read_current_state',
                'evaluate_requested_review',
                'describe_proposed_changes',
            ],
            'apply_supported' => false,
        ];
    }
}
