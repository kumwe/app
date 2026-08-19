<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Doctrine `datetime_immutable` mapping that keeps the microsecond component on every supported engine.
 *
 * Doctrine's stock type declares a whole-second column and formats without the fractional part, so
 * every stored instant — audit records, revisions, the clean-target restore manifest that compares
 * microsecond values row for row — would silently round. This override declares `DATETIME(6)` on MySQL
 * and MariaDB and `TIMESTAMP(6) WITHOUT TIME ZONE` on PostgreSQL, writes and reads `Y-m-d H:i:s.u`
 * normalised to UTC, and still parses the whole-second values left in columns written before the
 * precision was widened. `DoctrineTemporalTypes::register()` installs it under the canonical
 * `datetime_immutable` name, so every mapping in the tree picks it up without naming this class.
 *
 * @since  2.0.0
 */
final class MicrosecondDateTimeImmutableType extends DateTimeImmutableType
{
    /**
     * Names the platform column types that introspect back to this Doctrine type.
     *
     * Schema comparison reads this mapping, so a `datetime` or `timestamp` column found on the live
     * database resolves to the microsecond type rather than to Doctrine's stock one and no phantom
     * schema change is reported.
     *
     * @param   AbstractPlatform  $platform  Platform whose introspected type names are being claimed.
     *
     * @return  list<string>  Platform type names to map, empty on a platform this type does not claim.
     *
     * @since   2.0.0
     */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => ['datetime'],
            $platform instanceof PostgreSQLPlatform => ['timestamp'],
            default => [],
        };
    }

    /**
     * Builds the column declaration that reserves six fractional-second digits.
     *
     * @param   array<string, mixed>  $column    Doctrine column definition; read only on the fallback
     *          path, where the parent type builds the declaration.
     * @param   AbstractPlatform      $platform  Platform the declaration is generated for.
     *
     * @return  string  `DATETIME(6)` on MySQL and MariaDB, `TIMESTAMP(6) WITHOUT TIME ZONE` on
     *          PostgreSQL, and the parent type's declaration on any other platform.
     *
     * @since   2.0.0
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'DATETIME(6)',
            $platform instanceof PostgreSQLPlatform => 'TIMESTAMP(6) WITHOUT TIME ZONE',
            default => parent::getSQLDeclaration($column, $platform),
        };
    }

    /**
     * Formats an instant for storage, shifted to UTC and carrying its microseconds.
     *
     * The shift happens here rather than at the call site, so a value built in any zone is stored as
     * the same UTC instant and rows written by differently configured processes stay comparable.
     * Anything that is not a `DateTimeImmutable` is handed to the parent type, which passes null
     * through and rejects a value it cannot convert.
     *
     * @param   mixed             $value     Value being written; a `DateTimeImmutable` takes the
     *          microsecond path.
     * @param   AbstractPlatform  $platform  Platform the value is being written to.
     *
     * @return  ?string  The instant as `Y-m-d H:i:s.u` in UTC, or null when there is nothing to store.
     *
     * @since   2.0.0
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * Reads a stored timestamp back as a UTC instant, tolerating whole-second rows.
     *
     * Two formats are tried, the fractional one and then the whole-second one, so rows written before
     * the column gained its six digits still load; neither accepts a string the other was meant for.
     * Both are anchored with `!`, which zeroes the fields the stored string does not carry instead of
     * borrowing them from the current time — a legacy value reads back on an exact second rather than
     * with today's microseconds attached. A string neither format accepts falls through to the parent
     * type.
     *
     * @param   mixed             $value     Raw column value; a string takes the microsecond path.
     * @param   AbstractPlatform  $platform  Platform the value was read from.
     *
     * @return  ?DateTimeImmutable  The stored instant in UTC, or null when the column held no value.
     *
     * @since   2.0.0
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if (is_string($value)) {
            foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
