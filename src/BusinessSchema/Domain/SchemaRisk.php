<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum SchemaRisk: string
{
    case OnlineSafeAdditive = 'online_safe_additive';
    case BackfillRequired = 'backfill_required';
    case RebuildOrLocking = 'rebuild_or_locking';
    case BehaviorChanging = 'behavior_changing';
    case Destructive = 'destructive';

    public function severity(): int
    {
        return match ($this) {
            self::OnlineSafeAdditive => 0,
            self::BackfillRequired => 1,
            self::BehaviorChanging => 2,
            self::RebuildOrLocking => 3,
            self::Destructive => 4,
        };
    }

    public function requiresHighImpactAuthorization(): bool
    {
        return $this !== self::OnlineSafeAdditive;
    }

    public function requiresRecoveryEvidence(): bool
    {
        return in_array($this, [self::RebuildOrLocking, self::Destructive], true);
    }

    /** @param iterable<self> $risks */
    public static function highest(iterable $risks): self
    {
        $highest = self::OnlineSafeAdditive;
        foreach ($risks as $risk) {
            if ($risk->severity() > $highest->severity()) {
                $highest = $risk;
            }
        }

        return $highest;
    }
}
