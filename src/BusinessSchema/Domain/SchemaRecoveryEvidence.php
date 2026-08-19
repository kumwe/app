<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Signed-off proof that a restore drill succeeded for one site, engine, release, and source schema.
 *
 * A rebuild-or-locking or destructive plan may be neither approved nor executed unless a record like
 * this exists, is fresh, and matches the environment the plan will run in. Every field is therefore a
 * binding rather than a note: the source schema checksum ties the drill to the exact schema about to
 * change, the driver, server version, and release tie it to the binary that will do the changing, and
 * the two timestamps let the approval path reject a drill that predates the plan or the freshness
 * floor. Storage treats the record as immutable and compares its checksum before refusing to replace
 * it, so a drill cannot be quietly re-scoped after the fact.
 *
 * @since  2.0.0
 */
final readonly class SchemaRecoveryEvidence
{
    /**
     * Extra proofs the operator recorded, key sorted so the record's checksum ignores input order.
     *
     * The approval path reads named clean-drill proofs out of this map rather than from typed fields,
     * so it is where a drill procedure records the evidence beyond the fixed bindings above.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $details;

    /**
     * Record one verified restore drill, refusing anything internally inconsistent.
     *
     * @param   string                $id                      Canonical UUID naming this drill record.
     * @param   string                $siteIdentifier          Site the drill was performed for.
     * @param   string                $databaseDriver          Engine drilled: mariadb, mysql, or pgsql.
     * @param   string                $databaseServerVersion   Server version the drill ran against.
     * @param   string                $applicationRelease      Kumwe release that performed the drill.
     * @param   string                $sourceSchemaChecksum    Checksum of the schema the backup covers.
     * @param   string                $backupManifestChecksum  Digest of the manifest that was restored.
     * @param   bool                  $restoreTested           Whether the backup was restored, not just taken.
     * @param   DateTimeImmutable     $backupCreatedAt         When the backup being vouched for was made.
     * @param   DateTimeImmutable     $verifiedAt              When the restore was verified.
     * @param   string                $verifiedBy              Actor who signed the drill result off.
     * @param   string                $drillReference          Operator-facing reference for the drill run.
     * @param   array<string, mixed>  $details                 Further proofs; sorted before it is stored.
     *
     * @throws  InvalidBusinessSchema  When the ID is not a UUID, the site is not a metadata identifier,
     *          the driver is outside the three supported engines, the version, release, verifier, or
     *          drill reference is empty, over 191 bytes, or holds control characters, either checksum is
     *          not a lowercase SHA-256 digest, the verification predates the backup, or the details are
     *          not a string-keyed object.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the details hold a
     *          value that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $siteIdentifier,
        public string $databaseDriver,
        public string $databaseServerVersion,
        public string $applicationRelease,
        public string $sourceSchemaChecksum,
        public string $backupManifestChecksum,
        public bool $restoreTested,
        public DateTimeImmutable $backupCreatedAt,
        public DateTimeImmutable $verifiedAt,
        public string $verifiedBy,
        public string $drillReference,
        array $details = [],
    ) {
        SchemaDocument::assertUuid($id, 'The recovery-evidence ID');
        SchemaDocument::assertIdentifier($siteIdentifier, 'The recovery-evidence site');
        if (!in_array($databaseDriver, ['mariadb', 'mysql', 'pgsql'], true)) {
            throw new InvalidBusinessSchema('Recovery evidence uses an unsupported database driver.');
        }
        SchemaDocument::assertBoundedText($databaseServerVersion, 'The recovery database server version');
        SchemaDocument::assertBoundedText($applicationRelease, 'The recovery application release');
        SchemaDocument::assertChecksum($sourceSchemaChecksum, 'The recovery source schema checksum');
        SchemaDocument::assertChecksum($backupManifestChecksum, 'The backup manifest checksum');
        if ($verifiedAt < $backupCreatedAt) {
            throw new InvalidBusinessSchema('Recovery evidence cannot be verified before its backup exists.');
        }
        SchemaDocument::assertBoundedText($verifiedBy, 'The recovery-evidence verifier');
        SchemaDocument::assertBoundedText($drillReference, 'The recovery drill reference');
        SchemaDocument::assertObjectValue($details, 'Recovery evidence details');
        CanonicalDefinitionJson::encode($details);
        ksort($details, SORT_STRING);
        $this->details = $details;
    }

    /**
     * Rebuild a drill record from the row the evidence repository read.
     *
     * @param   array<string, mixed>  $document  Stored evidence object, as written by `toArray()`.
     *
     * @return  self  The revalidated record, subject to every construction rule again.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is absent
     *          or misshapen, a timestamp is unreadable, or a construction rule fails.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored details
     *          hold a value that cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'id', 'site_identifier', 'database_driver', 'database_server_version', 'application_release',
                'source_schema_checksum', 'backup_manifest_checksum',
                'restore_tested', 'backup_created_at', 'verified_at', 'verified_by', 'drill_reference', 'details',
            ],
            'Schema recovery evidence',
        );

        return new self(
            SchemaDocument::string($document, 'id'),
            SchemaDocument::string($document, 'site_identifier'),
            SchemaDocument::string($document, 'database_driver'),
            SchemaDocument::string($document, 'database_server_version'),
            SchemaDocument::string($document, 'application_release'),
            SchemaDocument::string($document, 'source_schema_checksum'),
            SchemaDocument::string($document, 'backup_manifest_checksum'),
            SchemaDocument::boolean($document, 'restore_tested'),
            SchemaDocument::date(
                SchemaDocument::string($document, 'backup_created_at'),
                'The recovery-evidence backup time',
            ),
            SchemaDocument::date(SchemaDocument::string($document, 'verified_at'), 'The recovery verification time'),
            SchemaDocument::string($document, 'verified_by'),
            SchemaDocument::string($document, 'drill_reference'),
            SchemaDocument::object($document, 'details') ?? [],
        );
    }

    /**
     * Decide whether this drill may back a plan about to run in the given environment.
     *
     * Every binding has to match and both timestamps have to clear the floor, so a drill from another
     * site, an upgraded engine, a different release, or an older source schema never qualifies. The
     * caller chooses the floor, which is how the same record can be fresh enough to approve a plan and
     * too old to execute it later.
     *
     * @param   string             $siteIdentifier      Site the plan will execute against.
     * @param   string             $driver              Engine the executor is bound to.
     * @param   string             $serverVersion       Server version the executor is configured for.
     * @param   string             $applicationRelease  Release that will perform the execution.
     * @param   string             $schemaChecksum      Source schema checksum the plan starts from.
     * @param   DateTimeImmutable  $notBefore           Floor both drill timestamps must be at or after.
     *
     * @return  bool  True only when the backup was actually restored and every binding matches.
     *
     * @since   2.0.0
     */
    public function qualifies(
        string $siteIdentifier,
        string $driver,
        string $serverVersion,
        string $applicationRelease,
        string $schemaChecksum,
        DateTimeImmutable $notBefore,
    ): bool {
        return $this->restoreTested
            && $this->siteIdentifier === $siteIdentifier
            && $this->databaseDriver === $driver
            && hash_equals($this->databaseServerVersion, $serverVersion)
            && hash_equals($this->applicationRelease, $applicationRelease)
            && hash_equals($this->sourceSchemaChecksum, $schemaChecksum)
            && $this->backupCreatedAt >= $notBefore
            && $this->verifiedAt >= $notBefore;
    }

    /**
     * Export the drill in the document shape it is persisted, compared, and hashed as.
     *
     * @return  array<string, mixed>  Every binding plus the sorted details, with both timestamps
     *          rendered as the fixed-width UTC text schema documents persist.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site_identifier' => $this->siteIdentifier,
            'database_driver' => $this->databaseDriver,
            'database_server_version' => $this->databaseServerVersion,
            'application_release' => $this->applicationRelease,
            'source_schema_checksum' => $this->sourceSchemaChecksum,
            'backup_manifest_checksum' => $this->backupManifestChecksum,
            'restore_tested' => $this->restoreTested,
            'backup_created_at' => SchemaDocument::formatDate($this->backupCreatedAt),
            'verified_at' => SchemaDocument::formatDate($this->verifiedAt),
            'verified_by' => $this->verifiedBy,
            'drill_reference' => $this->drillReference,
            'details' => $this->details,
        ];
    }

    /**
     * Compute the content address that makes this record immutable in storage.
     *
     * The repository recomputes it before it will accept a write against an identifier it already
     * holds, so a second save is either byte-identical or refused.
     *
     * @return  string  Lowercase SHA-256 over the canonical JSON encoding of `toArray()`.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
