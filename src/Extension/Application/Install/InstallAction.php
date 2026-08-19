<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Install;

/**
 * The steps one extension installation is decomposed into, in the order they must complete.
 *
 * `AtomicInstallPlan` declares the cases as a fixed sequence and rejects any step reported out of turn,
 * so the order of this enum is itself the safety property: nothing is unpacked before the package has
 * been proven safe and trusted, and derived runtime state is republished only once the registry records
 * the release. Naming the steps separately is also what makes a partial install recoverable — the plan
 * reports the actions already done, which is exactly the list a compensating rollback has to unwind.
 *
 * @since  2.0.0
 */
enum InstallAction: string
{
    /**
     * Confirm the package on disk still hashes to the digest the install was requested for.
     *
     * @since  2.0.0
     */
    case VerifyChecksum = 'verify_checksum';
    /**
     * Read the archive's entries and manifest, rejecting malformed or unsafe packages.
     *
     * @since  2.0.0
     */
    case InspectArchive = 'inspect_archive';
    /**
     * Establish that a trusted signing key vouches for this package.
     *
     * @since  2.0.0
     */
    case VerifyTrust = 'verify_trust';
    /**
     * Check that every extension the manifest requires is installed at an accepted version.
     *
     * @since  2.0.0
     */
    case ResolveDependencies = 'resolve_dependencies';
    /**
     * Unpack the package into a private staging directory, leaving the live runtime path untouched.
     *
     * @since  2.0.0
     */
    case StageFiles = 'stage_files';
    /**
     * Apply the schema changes the extension ships.
     *
     * @since  2.0.0
     */
    case ApplyMigrations = 'apply_migrations';
    /**
     * Record the release and its manifest in the extension registry.
     *
     * @since  2.0.0
     */
    case RegisterExtension = 'register_extension';
    /**
     * Publish the staged directory at the runtime path, which is the point the files become live.
     *
     * @since  2.0.0
     */
    case ActivateFiles = 'activate_files';
    /**
     * Rebuild the derived runtime state so request handling sees the new release.
     *
     * @since  2.0.0
     */
    case RebuildCaches = 'rebuild_caches';
}
