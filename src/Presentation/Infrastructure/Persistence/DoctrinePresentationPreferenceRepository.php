<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceRepository;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict;
use RuntimeException;

/**
 * DBAL preference store using a portable composite key and atomic optimistic writes.
 *
 * Nullable scope identifiers are mirrored into a non-null `scope_key` column because supported
 * databases disagree on whether nulls collide in unique constraints. Creation uses a unique insert;
 * updates and resets compare the expected version in the mutation statement, so no read/write race can
 * silently replace a newer preference. Every row is revalidated through the portable domain record on
 * read, making direct SQL corruption fail closed before a renderer sees it.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePresentationPreferenceRepository implements PresentationPreferenceRepository
{
    /**
     * Bind the repository to the application connection and prefixed table resolver.
     *
     * @param  Connection  $database  DBAL connection carrying preference transactions.
     * @param  TableNames  $tables    Resolver for the installation's physical table prefix.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Read and revalidate one exact presentation preference.
     *
     * @param   PresentationPreferenceKey  $key  Complete durable identity to select.
     *
     * @return  ?PresentationPreference  Stored record, or null when this layer has no override.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored JSON value or timestamp cannot be decoded.
     * @throws  InvalidArgumentException  When stored fields violate the portable KIS contract.
     *
     * @since   2.0.0
     */
    public function find(PresentationPreferenceKey $key): ?PresentationPreference
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT schema_version, standard_version, surface_id, owner_identifier, scope, scope_id, '
            . 'slot, preference_value, version, updated_by, updated_at FROM %s '
            . 'WHERE surface_id = ? AND slot = ? AND scope = ? AND scope_key = ?',
            $this->tables->quoted('interface_presentation_preferences'),
        ), [
            $key->surface->value(),
            $key->slot->value,
            $key->scope->value,
            $key->storageScopeKey(),
        ]);
        if ($row === false) {
            return null;
        }

        $preference = PresentationPreference::fromArray([
            'schema' => self::integer($row['schema_version'] ?? null, 'schema version'),
            'standard' => self::string($row['standard_version'] ?? null, 'standard version'),
            'surface' => self::string($row['surface_id'] ?? null, 'surface'),
            'owner' => self::string($row['owner_identifier'] ?? null, 'owner'),
            'scope' => self::string($row['scope'] ?? null, 'scope'),
            'scope_id' => self::nullableString($row['scope_id'] ?? null, 'scope identity'),
            'slot' => self::string($row['slot'] ?? null, 'slot'),
            'value' => self::decode($row['preference_value'] ?? null),
            'version' => self::integer($row['version'] ?? null, 'version'),
            'updated_by' => self::string($row['updated_by'] ?? null, 'update actor'),
            'updated_at' => self::timestamp($row['updated_at'] ?? null),
        ]);
        if (!PresentationPreferenceKey::fromPreference($preference)->equals($key)) {
            throw new RuntimeException('A stored KIS presentation preference key is internally inconsistent.');
        }

        return $preference;
    }

    /**
     * Insert version one or atomically replace one exact stored version.
     *
     * @param   PresentationPreference  $preference       Next complete record to persist.
     * @param   int                     $expectedVersion  Zero for creation, otherwise the version being replaced.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When another writer changed or removed the record.
     * @throws  InvalidArgumentException  When the proposed version is not the exact successor.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the mutation.
     *
     * @since   2.0.0
     */
    public function save(PresentationPreference $preference, int $expectedVersion): void
    {
        if ($expectedVersion < 0 || $preference->version() !== $expectedVersion + 1) {
            throw new InvalidArgumentException('A KIS preference save must carry the exact next version.');
        }
        $key = PresentationPreferenceKey::fromPreference($preference);
        if ($expectedVersion === 0) {
            try {
                $this->database->insert(
                    $this->tables->raw('interface_presentation_preferences'),
                    $this->row($preference),
                    $this->types(),
                );
            } catch (UniqueConstraintViolationException $exception) {
                throw new PresentationPreferenceVersionConflict(
                    $key,
                    0,
                    $this->actualVersion($key),
                );
            }
            return;
        }

        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET schema_version = ?, standard_version = ?, owner_identifier = ?, scope_id = ?, '
            . 'preference_value = ?, version = ?, updated_by = ?, updated_at = ? '
            . 'WHERE surface_id = ? AND slot = ? AND scope = ? AND scope_key = ? '
            . 'AND owner_identifier = ? AND version = ?',
            $this->tables->quoted('interface_presentation_preferences'),
        ), [
            PresentationPreference::SCHEMA_VERSION,
            PresentationPreference::STANDARD_VERSION,
            $preference->owner()->identifier(),
            $preference->scopeId(),
            $preference->value()->value(),
            $preference->version(),
            $preference->updatedBy(),
            $preference->updatedAt(),
            $key->surface->value(),
            $key->slot->value,
            $key->scope->value,
            $key->storageScopeKey(),
            $preference->owner()->identifier(),
            $expectedVersion,
        ], [
            Types::SMALLINT,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::JSON,
            Types::BIGINT,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::BIGINT,
        ]);
        if ($affected !== 1) {
            throw new PresentationPreferenceVersionConflict($key, $expectedVersion, $this->actualVersion($key));
        }
    }

    /**
     * Delete one exact stored version and refuse a stale reset.
     *
     * @param   PresentationPreferenceKey  $key              Complete durable identity to delete.
     * @param   ContributionOwner          $expectedOwner    Owner observed with the removable record.
     * @param   int                        $expectedVersion  Positive version the caller last observed.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When the record is absent or its version moved.
     * @throws  InvalidArgumentException  When the caller does not provide a positive version.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete.
     *
     * @since   2.0.0
     */
    public function delete(
        PresentationPreferenceKey $key,
        ContributionOwner $expectedOwner,
        int $expectedVersion,
    ): void {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('A KIS preference reset requires a positive expected version.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE surface_id = ? AND slot = ? AND scope = ? AND scope_key = ? '
            . 'AND owner_identifier = ? AND version = ?',
            $this->tables->quoted('interface_presentation_preferences'),
        ), [
            $key->surface->value(),
            $key->slot->value,
            $key->scope->value,
            $key->storageScopeKey(),
            $expectedOwner->identifier(),
            $expectedVersion,
        ], [Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::BIGINT]);
        if ($affected !== 1) {
            throw new PresentationPreferenceVersionConflict($key, $expectedVersion, $this->actualVersion($key));
        }
    }

    /**
     * Build the complete insert row for one preference.
     *
     * @param   PresentationPreference  $preference  Validated record being created.
     *
     * @return  array<string, mixed>  Physical column values including the non-null scope key.
     *
     * @since   2.0.0
     */
    private function row(PresentationPreference $preference): array
    {
        $key = PresentationPreferenceKey::fromPreference($preference);

        return [
            'schema_version' => PresentationPreference::SCHEMA_VERSION,
            'standard_version' => PresentationPreference::STANDARD_VERSION,
            'surface_id' => $preference->surface()->value(),
            'owner_identifier' => $preference->owner()->identifier(),
            'scope' => $preference->scope()->value,
            'scope_key' => $key->storageScopeKey(),
            'scope_id' => $preference->scopeId(),
            'slot' => $preference->slot()->value,
            'preference_value' => $preference->value()->value(),
            'version' => $preference->version(),
            'updated_by' => $preference->updatedBy(),
            'updated_at' => $preference->updatedAt(),
        ];
    }

    /**
     * Return DBAL conversion types matching the insert row order.
     *
     * @return  array<string, string>  Column-keyed DBAL type map.
     *
     * @since   2.0.0
     */
    private function types(): array
    {
        return [
            'schema_version' => Types::SMALLINT,
            'standard_version' => Types::STRING,
            'surface_id' => Types::STRING,
            'owner_identifier' => Types::STRING,
            'scope' => Types::STRING,
            'scope_key' => Types::STRING,
            'scope_id' => Types::STRING,
            'slot' => Types::STRING,
            'preference_value' => Types::JSON,
            'version' => Types::BIGINT,
            'updated_by' => Types::STRING,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /**
     * Read the current version after a failed compare-and-swap.
     *
     * @param   PresentationPreferenceKey  $key  Durable key whose mutation failed.
     *
     * @return  int  Positive stored version, or zero when the record is absent.
     *
     * @throws  RuntimeException  When a stored version is not a non-negative integer representation.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the diagnostic read.
     *
     * @since   2.0.0
     */
    private function actualVersion(PresentationPreferenceKey $key): int
    {
        $version = $this->database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE surface_id = ? AND slot = ? AND scope = ? AND scope_key = ?',
            $this->tables->quoted('interface_presentation_preferences'),
        ), [
            $key->surface->value(),
            $key->slot->value,
            $key->scope->value,
            $key->storageScopeKey(),
        ]);
        if ($version === false) {
            return 0;
        }

        return self::integer($version, 'version');
    }

    /**
     * Decode a stored JSON preference value.
     *
     * @param   mixed  $value  Driver value from the JSON column.
     *
     * @return  mixed  Decoded scalar, list, or object array.
     *
     * @throws  RuntimeException  When the driver did not return valid JSON text or a decoded value.
     *
     * @since   2.0.0
     */
    private static function decode(mixed $value): mixed
    {
        if (is_array($value) || is_int($value) || is_bool($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored KIS presentation preference value is invalid.');
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('A stored KIS presentation preference value is invalid JSON.', 0, $exception);
        }
    }

    /**
     * Normalize one required string returned by a driver.
     *
     * @param   mixed   $value  Raw column value.
     * @param   string  $field  Column meaning used in the failure message.
     *
     * @return  string  Driver string.
     *
     * @throws  RuntimeException  When the driver value is not a string.
     *
     * @since   2.0.0
     */
    private static function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The stored KIS preference %s is invalid.', $field));
        }

        return $value;
    }

    /**
     * Normalize one nullable string returned by a driver.
     *
     * @param   mixed   $value  Raw nullable column value.
     * @param   string  $field  Column meaning used in the failure message.
     *
     * @return  ?string  Driver string or null.
     *
     * @throws  RuntimeException  When the driver value has another type.
     *
     * @since   2.0.0
     */
    private static function nullableString(mixed $value, string $field): ?string
    {
        if (!is_string($value) && $value !== null) {
            throw new RuntimeException(sprintf('The stored KIS preference %s is invalid.', $field));
        }

        return $value;
    }

    /**
     * Normalize an exact non-negative integer returned as an int or decimal driver string.
     *
     * @param   mixed   $value  Raw numeric column value.
     * @param   string  $field  Column meaning used in the failure message.
     *
     * @return  int  Exact integer representation.
     *
     * @throws  RuntimeException  When the value is negative, non-numeric, or outside the platform int range.
     *
     * @since   2.0.0
     */
    private static function integer(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RuntimeException(sprintf('The stored KIS preference %s is invalid.', $field));
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($integer)) {
            throw new RuntimeException(sprintf('The stored KIS preference %s is outside the integer range.', $field));
        }

        return $integer;
    }

    /**
     * Normalize a driver timestamp into the portable RFC 3339 representation.
     *
     * @param   mixed  $value  Raw date-time column value.
     *
     * @return  string  RFC 3339 timestamp accepted by the domain record.
     *
     * @throws  RuntimeException  When the driver value cannot represent an instant.
     *
     * @since   2.0.0
     */
    private static function timestamp(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format(DATE_RFC3339);
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('The stored KIS preference update timestamp is invalid.');
        }
        try {
            return (new DateTimeImmutable($value))->format(DATE_RFC3339);
        } catch (\Exception $exception) {
            throw new RuntimeException('The stored KIS preference update timestamp is invalid.', 0, $exception);
        }
    }
}
