<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/**
 * Declaration by a migration that its whole `up()` may be run again after an interrupted attempt.
 *
 * On a platform whose DDL commits implicitly there is no rollback, so
 * `NonTransactionalMigrationRecovery` has to decide whether a half-finished migration may simply be
 * replayed; implementing this interface is how a migration answers, and the only route open to one
 * written from now on. The migrations already distributed cannot adopt it — editing their source would
 * change the checksums installed sites recorded — so the recovery service classifies those by ID
 * instead. A migration that declares this owes idempotence over its entire body, guarding every
 * create, alter and backfill, because recovery replays `up()` in full against a database left part way
 * through the interrupted attempt.
 *
 * @since  2.0.0
 */
interface RepeatableMigration extends Migration
{
}
