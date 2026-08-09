<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;

/**
 * Store of the physical schema each business definition currently has installed.
 *
 * There is one row per definition and the definition ID is the whole key, so a lookup names no site and
 * every reader has to compare the record's own `siteIdentifier` and `ownerIdentifier` against its
 * context before acting on it. `BusinessSchemaExecutor` is what moves a row through its life — it
 * records an installation as a plan starts and rewrites or deletes it as the plan finalizes — while
 * `BusinessSchemaLifecycleManager` only ever changes the status, and the record layer reads the row to
 * decide whether a definition's tables may be used at all.
 *
 * @since  2.0.0
 */
interface BusinessSchemaInstallationRepository
{
    /**
     * Look up the installation recorded for one business definition.
     *
     * Not site-scoped: the caller compares the returned `siteIdentifier` against its own context rather
     * than assuming the row belongs to it.
     *
     * @param   string  $definitionId  UUID of the business definition whose installed schema is wanted.
     *
     * @return  ?SchemaInstallation  The installation, or null when nothing is installed for that definition.
     *
     * @since   2.0.0
     */
    public function find(string $definitionId): ?SchemaInstallation;

    /**
     * Make an installation the current record for its definition, inserting it when none exists yet.
     *
     * Identity is the definition ID alone, so this overwrites the shape, status, and timestamps
     * previously recorded rather than appending to a history.
     *
     * @param   SchemaInstallation  $installation  Installation state to store, already self-validated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(SchemaInstallation $installation): void;

    /**
     * Read every installation an owner holds and lock the rows for the rest of the caller's transaction.
     *
     * The lock is the point of the method: the lifecycle sweep decides each row's next status from what
     * it reads here, so an implementation must be called inside an open transaction and must stop a
     * concurrent execution from moving a row between that read and the save that follows.
     *
     * @param   string  $ownerIdentifier  `core`, an extension handle, or `vendor/package`.
     *
     * @return  list<SchemaInstallation>  Current rows locked until the caller's transaction completes.
     *
     * @throws  BusinessSchemaConflict  When no transaction is open for the lock to be held in.
     *
     * @since   2.0.0
     */
    public function ownedByForUpdate(string $ownerIdentifier): array;

    /**
     * Delete the installation row whose tables a completed purge has dropped.
     *
     * The site is part of the criteria, so a purge planned on one site cannot delete another site's
     * record of the same definition.
     *
     * @param   string  $definitionId    UUID of the definition whose installation is being purged.
     * @param   string  $siteIdentifier  Site the stored row must belong to for the delete to count.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When no matching row is there to delete, meaning the installation
     *          disappeared while the purge was finalizing.
     *
     * @since   2.0.0
     */
    public function remove(string $definitionId, string $siteIdentifier): void;
}
