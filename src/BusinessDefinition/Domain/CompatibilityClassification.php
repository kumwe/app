<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * How much damage one definition change can do, and therefore what publication is allowed to do with it.
 *
 * The compatibility analyzer assigns exactly one of these to every difference it finds between the
 * published head and the draft, and the publication gate reads nothing else when deciding whether to
 * stop. The cases run from harmless to irreversible: only `Additive` lets a publication through without
 * an explicit confirmation, and `Destructive` is surfaced separately again so an operator sees it before
 * confirming. The backing values are written into immutable published plans, so they are permanent.
 *
 * @since  2.0.0
 */
enum CompatibilityClassification: string
{
    /**
     * The contract only widens, so every stored record and existing caller keeps working untouched.
     *
     * @since  2.0.0
     */
    case Additive = 'additive';

    /**
     * The contract narrows, but stored records already satisfy it, so nothing has to be rewritten.
     *
     * @since  2.0.0
     */
    case CompatibleConstraintTightening = 'compatible_constraint_tightening';

    /**
     * Stored data stays valid while the behaviour around it moves — exposure, computation, views,
     * actions, workflow, or presentation.
     *
     * @since  2.0.0
     */
    case BehaviorChanging = 'behavior_changing';

    /**
     * Stored records will not satisfy the new contract, so publishing commits to migrating them.
     *
     * @since  2.0.0
     */
    case DataMigrationRequired = 'data_migration_required';

    /**
     * Part of the contract is withdrawn and the data behind it can no longer be reached through the
     * definition.
     *
     * @since  2.0.0
     */
    case Destructive = 'destructive';

    /**
     * Whether a change of this class may only be published once the publisher has confirmed it.
     *
     * @return  bool  False for `Additive` alone; every other classification demands confirmation.
     *
     * @since   2.0.0
     */
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
