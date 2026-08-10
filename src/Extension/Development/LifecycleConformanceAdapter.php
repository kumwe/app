<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

/**
 * Adapter a platform test suite implements to exercise a real extension lifecycle environment.
 *
 * Every assertion method must throw when its gate fails. Implementations may use browser, API, console,
 * MCP, worker, backup, and database harnesses, but must never silently skip a declared platform surface.
 *
 * @since  2.0.0
 */
interface LifecycleConformanceAdapter
{
    /**
     * Prove both packages pass the production safety boundary and carry signatures accepted by trust policy.
     *
     * This gate must verify the exact package bytes supplied to the lifecycle run. Merely checking that a
     * detached-signature document is well formed is insufficient: the adapter must exercise the deployment's
     * real key lookup, namespace admission, revocation, expiry, checksum, and signature-verification path.
     *
     * @param   string  $basePackage     Canonical absolute initial package path.
     * @param   string  $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertPackageSafetyAndSigning(string $basePackage, string $upgradePackage): void;

    /**
     * Prove install and upgrade schema plans are additive, bounded, and reversible where required.
     *
     * @param   string  $basePackage     Canonical absolute initial package path.
     * @param   string  $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertSchemaPlan(string $basePackage, string $upgradePackage): void;

    /**
     * Install and activate the initial package in a clean environment.
     *
     * @param   string  $basePackage  Canonical absolute initial package path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function install(string $basePackage): void;

    /**
     * Prove installed definitions and executable providers exactly reconcile with the signed declarations.
     *
     * The active trusted generation must contain the expected entity definitions, field types, migrations,
     * routes, jobs, events, projections, reports, and policy declarations, and schema planning must have
     * materialized the same versioned definition graph rather than a partial or reinterpreted substitute.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertDefinitions(): void;

    /**
     * Prove deny-by-default authorization and field disclosure policies on every delivery surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertAuthorizationAndFieldPolicies(): void;

    /**
     * Prove every declared route is mounted, guarded, and withdrawn with runtime trust.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertRoutes(): void;

    /**
     * Prove REST behavior and generated OpenAPI contracts agree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertRestAndOpenApi(): void;

    /**
     * Prove CLI and MCP adapters preserve the same capability and schema contracts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertCliAndMcp(): void;

    /**
     * Prove event delivery, durable jobs, projections, and reports execute and retry safely.
     *
     * Worker and scheduler processes must use the exact trusted contribution generation, detect a stale
     * generation after activation changes, and resume the same durable work after a controlled restart.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertJobsEventsAndReports(): void;

    /**
     * Prove administrator and portal contributions render only under their declared authority.
     *
     * The gate must exercise real browser navigation, accessibility assertions, and contribution withdrawal;
     * a raw successful HTTP response alone does not prove this surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertPortalAndAdministrator(): void;

    /**
     * Prove extension data and runtime state survive a documented backup and restore cycle.
     *
     * Restored package checksums, authoritative and derived data, durable work, and audit evidence must be
     * compared with their pre-backup values in a clean installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertBackupAndRestore(): void;

    /**
     * Upgrade the active installation to the supplied package and verify compensation on failure.
     *
     * @param   string  $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function upgrade(string $upgradePackage): void;

    /**
     * Disable the installed extension and prove every executable contribution is withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function disable(): void;

    /**
     * Reactivate the disabled extension and prove its generation becomes usable again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function reactivate(): void;

    /**
     * Run the same lifecycle assertions against every database configured by the consuming CI matrix.
     *
     * @param   string  $basePackage     Canonical absolute initial package path.
     * @param   string  $upgradePackage  Canonical absolute upgrade package path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertDatabaseMatrix(string $basePackage, string $upgradePackage): void;

    /**
     * Uninstall the extension and prove policy-controlled data removal and contribution withdrawal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function uninstall(): void;

    /**
     * Restore a clean test environment after success or failure; repeated calls must be safe.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recover(): void;
}
