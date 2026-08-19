<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use DateTimeImmutable;

/**
 * The physical schema one business definition currently has installed on one site.
 *
 * This is the record the runtime trusts: the record repositories resolve a definition to its installation
 * before touching a table, and refuse to run unless the status admits it. Construction re-proves that the
 * stored blueprint really is the one the recorded definition version and checksums describe, so an
 * installation row cannot drift away from the schema it claims to represent without being rejected on load.
 * Status changes are made by returning a new installation, never by mutating this one.
 *
 * @since  2.0.0
 */
final readonly class SchemaInstallation
{
    /**
     * Record an installed schema together with the evidence that binds it to its definition.
     *
     * @param   string                    $definitionId        UUID of the installed business definition.
     * @param   string                    $siteIdentifier      Site whose tables this installation owns.
     * @param   string                    $ownerIdentifier     `core`, an extension handle, or `vendor/package`.
     * @param   int                       $definitionVersion   Published version whose shape is installed.
     * @param   string                    $definitionChecksum  SHA-256 of that published definition.
     * @param   string                    $schemaChecksum      SHA-256 of the blueprint actually installed.
     * @param   PhysicalSchemaBlueprint   $blueprint           Tables as they are expected to exist right now.
     * @param   SchemaInstallationStatus  $status              Whether record traffic may use these tables.
     * @param   DateTimeImmutable         $installedAt         Instant the schema was first installed.
     * @param   DateTimeImmutable         $updatedAt           Instant of the latest status or schema change.
     *
     * @throws  InvalidBusinessSchema  When an identifier, owner identity, or checksum is malformed, the
     *          version is below one, the blueprint disagrees with the recorded
     *          definition or schema checksum, or the update precedes the install.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $definitionId,
        public string $siteIdentifier,
        public string $ownerIdentifier,
        public int $definitionVersion,
        public string $definitionChecksum,
        public string $schemaChecksum,
        public PhysicalSchemaBlueprint $blueprint,
        public SchemaInstallationStatus $status,
        public DateTimeImmutable $installedAt,
        public DateTimeImmutable $updatedAt,
    ) {
        SchemaDocument::assertUuid($definitionId, 'The schema installation definition ID');
        SchemaDocument::assertIdentifier($siteIdentifier, 'The schema installation site');
        SchemaDocument::assertBoundedText($ownerIdentifier, 'The schema installation owner');
        $validOwner = preg_match(
            '#^(?:core|[a-z0-9][a-z0-9._-]{0,190}|[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*)$#D',
            $ownerIdentifier,
        );
        if ($validOwner !== 1) {
            throw new InvalidBusinessSchema('The schema installation owner has an invalid identity contract.');
        }
        if ($definitionVersion < 1) {
            throw new InvalidBusinessSchema('A schema installation requires a published definition version.');
        }
        SchemaDocument::assertChecksum($definitionChecksum, 'The installed definition checksum');
        SchemaDocument::assertChecksum($schemaChecksum, 'The installed physical schema checksum');
        if (
            $blueprint->definitionId !== $definitionId
            || $blueprint->definitionVersion !== $definitionVersion
            || !hash_equals($blueprint->definitionChecksum, $definitionChecksum)
            || !hash_equals($blueprint->checksum(), $schemaChecksum)
        ) {
            throw new InvalidBusinessSchema('A schema installation does not match its physical blueprint.');
        }
        if ($updatedAt < $installedAt) {
            throw new InvalidBusinessSchema('A schema installation cannot be updated before it is installed.');
        }
    }

    /**
     * Rebuild an installation from its persisted row, re-proving the blueprint binding.
     *
     * @param   array<string, mixed>  $document  Stored installation object, as written by `toArray()`.
     *
     * @return  self  The revalidated installation, with both timestamps normalized to UTC.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, the stored status is not a known one, the blueprint is
     *          absent or invalid, or any installation invariant fails.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored table's options
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'definition_id', 'site_identifier', 'owner_identifier', 'definition_version',
                'definition_checksum', 'schema_checksum', 'blueprint', 'status', 'installed_at', 'updated_at',
            ],
            'A schema installation',
        );
        $status = SchemaInstallationStatus::tryFrom(SchemaDocument::string($document, 'status'))
            ?? throw new InvalidBusinessSchema('A schema installation status is invalid.');
        $blueprint = SchemaDocument::object($document, 'blueprint')
            ?? throw new InvalidBusinessSchema('A schema installation requires its physical blueprint.');

        return new self(
            SchemaDocument::string($document, 'definition_id'),
            SchemaDocument::string($document, 'site_identifier'),
            SchemaDocument::string($document, 'owner_identifier'),
            SchemaDocument::integer($document, 'definition_version'),
            SchemaDocument::string($document, 'definition_checksum'),
            SchemaDocument::string($document, 'schema_checksum'),
            PhysicalSchemaBlueprint::fromArray($blueprint),
            $status,
            SchemaDocument::date(
                SchemaDocument::string($document, 'installed_at'),
                'The schema installation time',
            ),
            SchemaDocument::date(SchemaDocument::string($document, 'updated_at'), 'The schema update time'),
        );
    }

    /**
     * Withdraw a live installation from record traffic while leaving its tables and rows untouched.
     *
     * This is the transition the extension lifecycle applies when an owner is deactivated.
     *
     * @param   DateTimeImmutable  $at  Instant to record as the update time.
     *
     * @return  self  A disabled copy of this installation.
     *
     * @throws  InvalidBusinessSchema  When the installation is not currently active, or $at precedes the
     *          install time.
     *
     * @since   2.0.0
     */
    public function disable(DateTimeImmutable $at): self
    {
        if ($this->status !== SchemaInstallationStatus::Active) {
            throw new InvalidBusinessSchema('Only an active schema installation can be disabled.');
        }

        return $this->withStatus(SchemaInstallationStatus::Disabled, $at);
    }

    /**
     * Return a withheld installation to record traffic.
     *
     * The caller is responsible for having proved the tables still match this blueprint; this method only
     * enforces that the installation was withheld rather than mid-installation or failed.
     *
     * @param   DateTimeImmutable  $at  Instant to record as the update time.
     *
     * @return  self  An active copy of this installation.
     *
     * @throws  InvalidBusinessSchema  When the installation is neither disabled nor preserved, or $at
     *          precedes the install time.
     *
     * @since   2.0.0
     */
    public function reactivate(DateTimeImmutable $at): self
    {
        if (!in_array($this->status, [SchemaInstallationStatus::Disabled, SchemaInstallationStatus::Preserved], true)) {
            throw new InvalidBusinessSchema('Only a disabled or preserved schema installation can be reactivated.');
        }

        return $this->withStatus(SchemaInstallationStatus::Active, $at);
    }

    /**
     * Hold an installation aside as intact but unusable, pending a deliberate reactivation.
     *
     * Unlike `disable()` this accepts an in-flight installation, which is what makes it the right
     * transition when an owner is deactivated mid-execution or a plan finalizes under an inactive owner.
     *
     * @param   DateTimeImmutable  $at  Instant to record as the update time.
     *
     * @return  self  A preserved copy of this installation.
     *
     * @throws  InvalidBusinessSchema  When the installation has failed, or $at precedes the install time.
     *
     * @since   2.0.0
     */
    public function preserve(DateTimeImmutable $at): self
    {
        if ($this->status === SchemaInstallationStatus::Failed) {
            throw new InvalidBusinessSchema('A failed schema installation cannot be marked as preserved.');
        }

        return $this->withStatus(SchemaInstallationStatus::Preserved, $at);
    }

    /**
     * Export the installation in the shape persisted in the installation table.
     *
     * @return  array<string, mixed>  Identity, checksums, status, and the nested blueprint document, with
     *          both timestamps rendered as canonical UTC strings.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'owner_identifier' => $this->ownerIdentifier,
            'definition_version' => $this->definitionVersion,
            'definition_checksum' => $this->definitionChecksum,
            'schema_checksum' => $this->schemaChecksum,
            'blueprint' => $this->blueprint->toArray(),
            'status' => $this->status->value,
            'installed_at' => SchemaDocument::formatDate($this->installedAt),
            'updated_at' => SchemaDocument::formatDate($this->updatedAt),
        ];
    }

    /**
     * Copy the installation with a new status and update time, leaving the schema binding alone.
     *
     * @param   SchemaInstallationStatus  $status  Status the copy carries.
     * @param   DateTimeImmutable         $at      Instant to record as the update time.
     *
     * @return  self  The copied installation.
     *
     * @throws  InvalidBusinessSchema  When $at precedes the recorded install time.
     *
     * @since   2.0.0
     */
    private function withStatus(SchemaInstallationStatus $status, DateTimeImmutable $at): self
    {
        return new self(
            $this->definitionId,
            $this->siteIdentifier,
            $this->ownerIdentifier,
            $this->definitionVersion,
            $this->definitionChecksum,
            $this->schemaChecksum,
            $this->blueprint,
            $status,
            $this->installedAt,
            $at,
        );
    }
}
