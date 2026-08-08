<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineBusinessSchemaInstallationRepository implements BusinessSchemaInstallationRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function find(string $definitionId): ?SchemaInstallation
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE definition_id = ?',
            $this->tables->quoted('business_schema_installations'),
        ), [$definitionId]);
        if ($row === false) {
            return null;
        }

        return $this->map($row);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): SchemaInstallation
    {
        return SchemaInstallation::fromArray([
            'definition_id' => $this->string($row, 'definition_id'),
            'site_identifier' => $this->string($row, 'site_identifier'),
            'owner_identifier' => $this->string($row, 'owner_identifier'),
            'definition_version' => $this->integer($row, 'definition_version'),
            'definition_checksum' => $this->string($row, 'definition_checksum'),
            'schema_checksum' => $this->string($row, 'schema_checksum'),
            'blueprint' => $this->jsonObject($row['blueprint'] ?? null),
            'status' => $this->string($row, 'status'),
            'installed_at' => $this->date($row['installed_at'] ?? null),
            'updated_at' => $this->date($row['updated_at'] ?? null),
        ]);
    }

    public function save(SchemaInstallation $installation): void
    {
        $values = [
            'site_identifier' => $installation->siteIdentifier,
            'owner_identifier' => $installation->ownerIdentifier,
            'definition_version' => $installation->definitionVersion,
            'definition_checksum' => $installation->definitionChecksum,
            'schema_checksum' => $installation->schemaChecksum,
            'blueprint' => $installation->blueprint->toArray(),
            'status' => $installation->status->value,
            'installed_at' => $installation->installedAt,
            'updated_at' => $installation->updatedAt,
        ];
        $types = [
            'definition_version' => Types::INTEGER,
            'blueprint' => Types::JSON,
            'installed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_schema_installations'),
            $values,
            ['definition_id' => $installation->definitionId],
            $types,
        );
        if ($affected === 0) {
            $exists = $this->database->fetchOne(sprintf(
                'SELECT definition_id FROM %s WHERE definition_id = ?',
                $this->tables->quoted('business_schema_installations'),
            ), [$installation->definitionId]);
            if ($exists !== false) {
                return;
            }
            $this->database->insert($this->tables->raw('business_schema_installations'), [
                'definition_id' => $installation->definitionId,
                ...$values,
            ], $types);
        }
    }

    public function remove(string $definitionId, string $siteIdentifier): void
    {
        $affected = $this->database->delete($this->tables->raw('business_schema_installations'), [
            'definition_id' => $definitionId,
            'site_identifier' => $siteIdentifier,
        ]);
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema installation disappeared during purge finalization.');
        }
    }

    public function ownedByForUpdate(string $ownerIdentifier): array
    {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessSchemaConflict('Schema lifecycle reconciliation requires an active transaction.');
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE owner_identifier = ? ORDER BY site_identifier, definition_id FOR UPDATE',
            $this->tables->quoted('business_schema_installations'),
        ), [$ownerIdentifier]);

        return array_map($this->map(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored schema installation property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Stored schema installation property ' . $key . ' is invalid.');
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored schema installation JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Stored schema installation blueprint is invalid.');
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
            throw new RuntimeException('Stored schema installation timestamp is invalid.');
        }
        return $value;
    }
}
