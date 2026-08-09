<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/**
 * What `MigrationRunner` must do with a migration once its recovery attempt has been journaled.
 *
 * Only platforms whose DDL commits implicitly reach this decision: there, an interrupted migration
 * cannot be rolled back, so `NonTransactionalMigrationRecovery::prepare()` first works out whether the
 * migration still has to run at all. The runner writes the ledger row either way, so the two cases
 * differ only in whether `up()` is called again.
 *
 * @since  2.0.0
 */
enum NonTransactionalMigrationAction
{
    /**
     * Call `up()` now: the migration has not started, or its strategy proves a replay safe.
     *
     * @since  2.0.0
     */
    case Execute;
    /**
     * Skip `up()`: recovery has already restored the postcondition the interrupted attempt aimed at.
     *
     * @since  2.0.0
     */
    case RecordRecovered;
}
