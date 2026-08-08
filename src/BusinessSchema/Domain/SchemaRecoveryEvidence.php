<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class SchemaRecoveryEvidence
{
    /** @var array<string, mixed> */
    public array $details;

    /** @param array<string, mixed> $details */
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

    /** @param array<string, mixed> $document */
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

    public function qualifies(
        string $siteIdentifier,
        string $driver,
        string $serverVersion,
        string $applicationRelease,
        string $schemaChecksum,
        DateTimeImmutable $notBefore,
    ): bool
    {
        return $this->restoreTested
            && $this->siteIdentifier === $siteIdentifier
            && $this->databaseDriver === $driver
            && hash_equals($this->databaseServerVersion, $serverVersion)
            && hash_equals($this->applicationRelease, $applicationRelease)
            && hash_equals($this->sourceSchemaChecksum, $schemaChecksum)
            && $this->backupCreatedAt >= $notBefore
            && $this->verifiedAt >= $notBefore;
    }

    /** @return array<string, mixed> */
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

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
