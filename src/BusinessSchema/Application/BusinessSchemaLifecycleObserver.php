<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;

/**
 * Port that tells the schema layer an extension owner's activation state has changed.
 *
 * Activation is decided by the package-synchronization code, which reasons about definitions and knows
 * nothing about tables; this port is how that decision reaches the physical installations those
 * definitions own, without the definition layer depending on schema services. The call is made inside
 * the same transaction as the activation write, so the two records cannot end up disagreeing.
 * `BusinessSchemaLifecycleManager` is the implementation, and `DeferredBusinessSchemaObserver` stands in
 * for it while the container is still being composed.
 *
 * @since  2.0.0
 */
interface BusinessSchemaLifecycleObserver
{
    /**
     * Reconcile every installation an owner holds with that owner's new activation state.
     *
     * Retains all tables and data while reconciling installed runtime availability: deactivation only
     * withdraws installations from record traffic, and reactivation is refused rather than forced when an
     * installation can no longer be proved to match the schema it claims.
     *
     * @param   string             $ownerIdentifier  `core`, an extension handle, or `vendor/package`.
     * @param   bool               $active           True when the owner just became active, false when disabled.
     * @param   DateTimeImmutable  $at               Instant to record as the update time on every row touched.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When an installation cannot safely be returned to service, which
     *          fails the surrounding activation transaction as a whole.
     *
     * @since   2.0.0
     */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void;
}
