<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class CompatibilityPlan
{
    /** @var list<CompatibilityChange> */
    private array $changes;

    /** @param list<CompatibilityChange> $changes */
    public function __construct(
        public ?int $fromVersion,
        public int $toVersion,
        public ?string $fromChecksum,
        public string $toChecksum,
        array $changes,
    ) {
        if ($toVersion < 1 || ($fromVersion !== null && $toVersion !== $fromVersion + 1)) {
            throw new InvalidBusinessDefinition('A compatibility plan has invalid version bounds.');
        }
        foreach (array_filter([$fromChecksum, $toChecksum], 'is_string') as $checksum) {
            if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                throw new InvalidBusinessDefinition('A compatibility plan checksum is invalid.');
            }
        }
        usort($changes, static fn (CompatibilityChange $left, CompatibilityChange $right): int => [
            $left->path,
            $left->classification->value,
            $left->message,
        ] <=> [
            $right->path,
            $right->classification->value,
            $right->message,
        ]);
        $this->changes = $changes;
    }

    /** @return list<CompatibilityChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function requiresConfirmation(): bool
    {
        return array_filter(
            $this->changes,
            static fn (CompatibilityChange $change): bool => $change->classification->requiresConfirmation(),
        ) !== [];
    }

    public function destructive(): bool
    {
        return array_filter(
            $this->changes,
            static fn (CompatibilityChange $change): bool =>
                $change->classification === CompatibilityClassification::Destructive,
        ) !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'from_checksum' => $this->fromChecksum,
            'to_checksum' => $this->toChecksum,
            'requires_confirmation' => $this->requiresConfirmation(),
            'destructive' => $this->destructive(),
            'changes' => array_map(static fn (CompatibilityChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
