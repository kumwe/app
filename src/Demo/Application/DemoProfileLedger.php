<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Application;

/**
 * Application port for immutable profile selection and restart-safe resource provenance.
 *
 * Installers depend on this contract rather than a database adapter, keeping reconciliation policy
 * testable and leaving advisory locking, JSON persistence, and upsert syntax at the infrastructure edge.
 *
 * @since  2.0.0
 */
interface DemoProfileLedger
{
    /**
     * Run one site reconciliation under exclusive deployment-scoped coordination.
     *
     * @template T
     *
     * @param   string         $site       Site whose profile datasets are reconciled.
     * @param   callable(): T  $operation  Complete reconciliation pass.
     *
     * @return  T  Value returned by the operation.
     *
     * @since   2.0.0
     */
    public function synchronized(string $site, callable $operation): mixed;

    /**
     * Start or resume one immutable selected manifest.
     *
     * @param   string  $site              Site owning the selection.
     * @param   string  $dataset           Stable dataset key.
     * @param   string  $selectedProfile   Selected profile name.
     * @param   int     $manifestVersion   Monotonic released manifest version.
     * @param   string  $manifestChecksum  Canonical complete-manifest checksum.
     *
     * @return  bool  Whether reconciliation work remains.
     *
     * @since   2.0.0
     */
    public function begin(
        string $site,
        string $dataset,
        string $selectedProfile,
        int $manifestVersion,
        string $manifestChecksum,
    ): bool;

    /**
     * Mark one dataset manifest completely reconciled.
     *
     * @param   string  $site     Site owning the selection.
     * @param   string  $dataset  Stable dataset key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(string $site, string $dataset): void;

    /**
     * Mark one dataset pass failed without discarding resource checkpoints.
     *
     * @param   string  $site     Site owning the selection.
     * @param   string  $dataset  Stable dataset key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failed(string $site, string $dataset): void;

    /**
     * Read one resource checkpoint.
     *
     * @param   string  $site        Site owning the resource.
     * @param   string  $dataset     Stable dataset key.
     * @param   string  $fixtureKey  Stable fixture identity.
     *
     * @return  ?array<string, mixed>  Stored checkpoint or null before first application.
     *
     * @since   2.0.0
     */
    public function asset(string $site, string $dataset, string $fixtureKey): ?array;

    /**
     * List every checkpoint belonging to one selected dataset.
     *
     * @param   string  $site     Site owning the resources.
     * @param   string  $dataset  Stable dataset key.
     *
     * @return  list<array<string, mixed>>  Checkpoints in stable fixture order.
     *
     * @since   2.0.0
     */
    public function assets(string $site, string $dataset): array;

    /**
     * Persist one fixture-to-resource mapping and exact last-applied canonical state.
     *
     * @param   string                $site          Site owning the resource.
     * @param   string                $dataset       Stable dataset key.
     * @param   string                $fixtureKey    Stable fixture identity.
     * @param   string                $resourceType  Diagnostic resource noun.
     * @param   string                $resourceId    Actual service-issued resource identity.
     * @param   string                $checksum      Canonical fixture baseline checksum; mutable resources
     *          fingerprint applied state, while immutable operations fingerprint their exact request.
     * @param   int                   $version       Resource version after application.
     * @param   array<string, mixed>  $state         Non-secret canonical applied state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordAsset(
        string $site,
        string $dataset,
        string $fixtureKey,
        string $resourceType,
        string $resourceId,
        string $checksum,
        int $version,
        array $state,
    ): void;
}
