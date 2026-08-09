<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

/**
 * Registry entry for one installed extension, holding its lifecycle state and its version counter.
 *
 * This is the domain model behind a row of the extension registry: it decides which transitions are
 * legal and when the registry version advances, leaving persistence with nothing to decide. The
 * counter is the concurrency handle, and it only moves when something really changed — re-applying
 * the status a record already has is a no-op, so a repeated activation cannot invalidate another
 * writer's read. Identity and installed kind are fixed for the life of the record; only the status
 * and the installed version move, and an upgrade always drops the record back to `Disabled`.
 *
 * @since  2.0.0
 */
final class ExtensionRecord
{
    /**
     * Bind a record to its identity and starting state.
     *
     * @param   ExtensionIdentifier  $identifier        Extension this record tracks.
     * @param   ExtensionType        $type              Kind the extension was installed as.
     * @param   SemanticVersion      $installedVersion  Version currently installed on disk.
     * @param   ExtensionStatus      $status            Lifecycle state the record starts in.
     * @param   int                  $registryVersion   Counter of effective changes so far.
     *
     * @throws  InvalidArgumentException  When the registry version is negative.
     *
     * @since   2.0.0
     */
    private function __construct(
        private readonly ExtensionIdentifier $identifier,
        private ExtensionType $type,
        private SemanticVersion $installedVersion,
        private ExtensionStatus $status,
        private int $registryVersion,
    ) {
        if ($registryVersion < 0) {
            throw new InvalidArgumentException('An extension registry version cannot be negative.');
        }
    }

    /**
     * Open a registry entry for a package that has just been installed.
     *
     * A newly installed extension is always disabled at registry version zero, so making it live is
     * a deliberate second step rather than a side effect of installing it.
     *
     * @param   ExtensionManifest  $manifest  Manifest of the package that was installed.
     *
     * @return  self  A disabled record at version zero, taking identity, kind, and version from the manifest.
     *
     * @since   2.0.0
     */
    public static function install(ExtensionManifest $manifest): self
    {
        return new self(
            $manifest->identifier(),
            $manifest->type(),
            $manifest->version(),
            ExtensionStatus::Disabled,
            0,
        );
    }

    /**
     * Rebuild a record from the values a registry row already holds.
     *
     * Restoring state is not a transition: status and counter are taken as given and nothing
     * advances until a later call actually changes something, so a load-modify-store round trip
     * bumps the version exactly once.
     *
     * @param   ExtensionIdentifier  $identifier        Extension the row is keyed by.
     * @param   ExtensionType        $type              Kind recorded when the extension was installed.
     * @param   SemanticVersion      $installedVersion  Version the row says is on disk.
     * @param   ExtensionStatus      $status            Lifecycle state as stored.
     * @param   int                  $registryVersion   Stored counter, carried forward for concurrency checks.
     *
     * @return  self  The record as stored, ready for further transitions.
     *
     * @throws  InvalidArgumentException  When the stored registry version is negative.
     *
     * @since   2.0.0
     */
    public static function reconstitute(
        ExtensionIdentifier $identifier,
        ExtensionType $type,
        SemanticVersion $installedVersion,
        ExtensionStatus $status,
        int $registryVersion,
    ): self {
        return new self($identifier, $type, $installedVersion, $status, $registryVersion);
    }

    /**
     * Name the extension this record tracks.
     *
     * @return  ExtensionIdentifier  Identity fixed at install time and unchanged by any transition.
     *
     * @since   2.0.0
     */
    public function identifier(): ExtensionIdentifier
    {
        return $this->identifier;
    }

    /**
     * Report the kind the extension was installed as.
     *
     * @return  ExtensionType  Kind an upgrade must agree with.
     *
     * @since   2.0.0
     */
    public function type(): ExtensionType
    {
        return $this->type;
    }

    /**
     * Report the version currently installed on disk.
     *
     * @return  SemanticVersion  Version of the code the record points at, not of the record itself.
     *
     * @since   2.0.0
     */
    public function installedVersion(): SemanticVersion
    {
        return $this->installedVersion;
    }

    /**
     * Report the lifecycle state that decides whether the extension may load.
     *
     * @return  ExtensionStatus  Current state; only `Active` belongs in the compiled runtime map.
     *
     * @since   2.0.0
     */
    public function status(): ExtensionStatus
    {
        return $this->status;
    }

    /**
     * Report how many effective changes this record has been through.
     *
     * @return  int  Counter to compare against the stored row when writing back.
     *
     * @since   2.0.0
     */
    public function registryVersion(): int
    {
        return $this->registryVersion;
    }

    /**
     * Mark the extension as eligible for the compiled runtime map.
     *
     * Activating an already active record changes nothing and leaves the counter alone, so the call
     * is safe to repeat.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function activate(): void
    {
        $this->changeStatus(ExtensionStatus::Active);
    }

    /**
     * Withdraw the extension from the compiled runtime map while leaving it installed.
     *
     * Disabling an already disabled record changes nothing and leaves the counter alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function disable(): void
    {
        $this->changeStatus(ExtensionStatus::Disabled);
    }

    /**
     * Move the record to a newer version of the same extension.
     *
     * The record is left disabled even when it was active, so an operator re-activates against the
     * new code deliberately. The counter always advances, because the installed version moved even
     * if the status did not. Re-applying the version already installed is refused rather than
     * treated as a harmless repeat, which keeps an upgrade from hiding a stale package.
     *
     * @param   ExtensionManifest  $manifest  Manifest of the replacement package.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the manifest names another extension or kind, or is not newer.
     *
     * @since   2.0.0
     */
    public function upgrade(ExtensionManifest $manifest): void
    {
        if (!$manifest->identifier()->equals($this->identifier) || $manifest->type() !== $this->type) {
            throw new InvalidArgumentException('An extension can only be upgraded by a matching manifest.');
        }

        if ($manifest->version()->compare($this->installedVersion) <= 0) {
            throw new InvalidArgumentException('An extension upgrade must increase the installed version.');
        }

        $this->installedVersion = $manifest->version();
        $this->status = ExtensionStatus::Disabled;
        ++$this->registryVersion;
    }

    /**
     * Apply a lifecycle state, advancing the registry version only when the state actually moves.
     *
     * @param   ExtensionStatus  $status  State to move to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function changeStatus(ExtensionStatus $status): void
    {
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        ++$this->registryVersion;
    }
}
