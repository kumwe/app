<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineBusinessSchemaRecoveryEvidenceRepository implements
    BusinessSchemaRecoveryEvidenceRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function find(SiteContext $site, string $evidenceId): ?SchemaRecoveryEvidence
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND id = ?',
            $this->tables->quoted('business_schema_recovery_evidence'),
        ), [$site->identifier(), $evidenceId]);
        if ($row === false) {
            return null;
        }

        return SchemaRecoveryEvidence::fromArray([
            'id' => $this->string($row, 'id'),
            'site_identifier' => $this->string($row, 'site_identifier'),
            'database_driver' => $this->string($row, 'database_driver'),
            'database_server_version' => $this->string($row, 'database_server_version'),
            'application_release' => $this->string($row, 'application_release'),
            'source_schema_checksum' => $this->string($row, 'source_schema_checksum'),
            'backup_manifest_checksum' => $this->string($row, 'backup_manifest_checksum'),
            'restore_tested' => $this->boolean($row, 'restore_tested'),
            'backup_created_at' => $this->date($row['backup_created_at'] ?? null),
            'verified_at' => $this->date($row['verified_at'] ?? null),
            'verified_by' => $this->string($row, 'verified_by'),
            'drill_reference' => $this->string($row, 'drill_reference'),
            'details' => $this->jsonObject($row['details'] ?? null),
        ]);
    }

    public function save(SchemaRecoveryEvidence $evidence): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?',
            $this->tables->quoted('business_schema_recovery_evidence'),
        ), [$evidence->id]);
        if ($exists !== false) {
            $stored = $this->find(SiteContext::fromString($evidence->siteIdentifier), $evidence->id);
            if ($stored === null || !hash_equals($stored->checksum(), $evidence->checksum())) {
                throw new RuntimeException('Immutable schema recovery evidence cannot be replaced.');
            }
            return;
        }
        $this->database->insert($this->tables->raw('business_schema_recovery_evidence'), [
            'id' => $evidence->id,
            'site_identifier' => $evidence->siteIdentifier,
            'database_driver' => $evidence->databaseDriver,
            'database_server_version' => $evidence->databaseServerVersion,
            'application_release' => $evidence->applicationRelease,
            'source_schema_checksum' => $evidence->sourceSchemaChecksum,
            'backup_manifest_checksum' => $evidence->backupManifestChecksum,
            'restore_tested' => $evidence->restoreTested,
            'backup_created_at' => $evidence->backupCreatedAt,
            'verified_at' => $evidence->verifiedAt,
            'verified_by' => $evidence->verifiedBy,
            'drill_reference' => $evidence->drillReference,
            'details' => $evidence->details,
        ], [
            'restore_tested' => Types::BOOLEAN,
            'backup_created_at' => Types::DATETIME_IMMUTABLE,
            'verified_at' => Types::DATETIME_IMMUTABLE,
            'details' => Types::JSON,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored recovery evidence property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, '0'], true)) {
            return false;
        }
        if (in_array($value, [1, '1'], true)) {
            return true;
        }
        throw new RuntimeException('Stored recovery evidence property ' . $key . ' is invalid.');
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored recovery evidence JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored recovery evidence details are invalid.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored recovery evidence timestamp is invalid.');
        }
        return $value;
    }
}
