<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

/**
 * Exact active installation generation observed by the mutation's current locking read.
 *
 * `BusinessRecordMutationFence` returns one of these from the locking read that pins a definition's
 * installation row, so it records what the lock is actually holding: whose definition it is, which
 * version is installed, the two checksums that bind that version to the physical tables, and the
 * lifecycle status. Definitions are resolved through a separate path that reads its own rows, and this
 * value is what proves the two agree — a caller hands its resolved pair to `assertMatches()` and only
 * then touches a table. The comparison is deliberately exact rather than tolerant, because any
 * disagreement means the schema moved underneath the operation.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordMutationGeneration
{
    /**
     * Capture the installation state a locking read observed.
     *
     * @param  string                    $definitionId        UUID of the fenced business definition.
     * @param  string                    $siteIdentifier      Site whose installation row was locked.
     * @param  string                    $ownerIdentifier     Owner recorded on the definition, which the
     *         installation must agree with.
     * @param  int                       $definitionVersion   Published version the installation applied.
     * @param  string                    $definitionChecksum  SHA-256 of that published definition.
     * @param  string                    $schemaChecksum      SHA-256 of the physical blueprint installed.
     * @param  SchemaInstallationStatus  $status              Lifecycle status seen under the lock.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $definitionId,
        public string $siteIdentifier,
        public string $ownerIdentifier,
        public int $definitionVersion,
        public string $definitionChecksum,
        public string $schemaChecksum,
        public SchemaInstallationStatus $status,
    ) {
    }

    /**
     * Prove that a separately resolved definition describes the generation this lock is holding.
     *
     * Identity, version, both checksums and the status must all match what the locking read saw, and
     * the status must additionally be one this call admits: `Active` alone for a mutation, or `Active`,
     * `Disabled` and `Preserved` when reading preserved history. A mismatch is reported as a transient
     * condition rather than a validation error because the expected cause is an installer that changed
     * the schema between the caller's resolve and its lock, which the caller retries.
     *
     * @param   ResolvedBusinessDefinition  $resolved     Definition and installation pair resolved
     *          outside this lock.
     * @param   bool                        $historyOnly  True when the caller is reading history, which
     *          also admits a withdrawn installation.
     *
     * @return  void
     *
     * @throws  BusinessRecordTemporarilyUnavailable  When the resolved installation differs from the
     *          locked generation in any field, or its status is not admitted here.
     *
     * @since   2.0.0
     */
    public function assertMatches(ResolvedBusinessDefinition $resolved, bool $historyOnly = false): void
    {
        $installation = $resolved->installation;
        $allowed = $historyOnly
            ? [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ]
            : [SchemaInstallationStatus::Active];
        if (
            $installation->definitionId !== $this->definitionId
            || $installation->siteIdentifier !== $this->siteIdentifier
            || $installation->ownerIdentifier !== $this->ownerIdentifier
            || $installation->definitionVersion !== $this->definitionVersion
            || !hash_equals($installation->definitionChecksum, $this->definitionChecksum)
            || !hash_equals($installation->schemaChecksum, $this->schemaChecksum)
            || $installation->status !== $this->status
            || !in_array($this->status, $allowed, true)
        ) {
            throw new BusinessRecordTemporarilyUnavailable();
        }
    }
}
