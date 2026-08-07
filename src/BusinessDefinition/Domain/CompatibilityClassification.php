<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum CompatibilityClassification: string
{
    case Additive = 'additive';
    case CompatibleConstraintTightening = 'compatible_constraint_tightening';
    case BehaviorChanging = 'behavior_changing';
    case DataMigrationRequired = 'data_migration_required';
    case Destructive = 'destructive';

    public function requiresConfirmation(): bool
    {
        return match ($this) {
            self::Additive => false,
            self::CompatibleConstraintTightening,
            self::BehaviorChanging,
            self::DataMigrationRequired,
            self::Destructive => true,
        };
    }
}
