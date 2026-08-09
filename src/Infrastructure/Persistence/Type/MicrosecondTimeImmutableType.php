<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\TimeImmutableType;

/**
 * Doctrine `time_immutable` mapping that keeps the microsecond component on every supported engine.
 *
 * Doctrine's stock type declares a whole-second `TIME` column and formats without the fractional part.
 * This override declares `TIME(6)` on MySQL and MariaDB and `TIME(6) WITHOUT TIME ZONE` on PostgreSQL
 * so the digits survive the round trip. A time-of-day column carries no date and no zone, so the
 * wall-clock time is stored exactly as the value spells it — unlike the datetime type, nothing is
 * shifted, because shifting a bare time would move the hour the operator entered. Reading one back
 * anchors it on the Unix epoch in UTC, so only the time fields carry meaning.
 * `DoctrineTemporalTypes::register()` installs it under the canonical `time_immutable` name.
 *
 * @since  2.0.0
 */
final class MicrosecondTimeImmutableType extends TimeImmutableType
{
    /**
     * Names the platform column types that introspect back to this Doctrine type.
     *
     * Schema comparison reads this mapping, so a `time` column found on the live database resolves to
     * the microsecond type rather than to Doctrine's stock one and no phantom schema change is
     * reported.
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
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform => ['time'],
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
     * @return  string  `TIME(6)` on MySQL and MariaDB, `TIME(6) WITHOUT TIME ZONE` on PostgreSQL, and
     *          the parent type's declaration on any other platform.
     *
     * @since   2.0.0
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'TIME(6)',
            $platform instanceof PostgreSQLPlatform => 'TIME(6) WITHOUT TIME ZONE',
            default => parent::getSQLDeclaration($column, $platform),
        };
    }

    /**
     * Formats a time of day for storage, carrying its microseconds.
     *
     * The value's own zone is left alone: only the hour, minute, second and fraction it already spells
     * are written, so a time entered as 09:00 is stored as 09:00 whatever zone the instant behind it
     * belongs to. Anything that is not a `DateTimeImmutable` is handed to the parent type, which passes
     * null through and rejects a value it cannot convert.
     *
     * @param   mixed             $value     Value being written; a `DateTimeImmutable` takes the
     *          microsecond path.
     * @param   AbstractPlatform  $platform  Platform the value is being written to.
     *
     * @return  ?string  The time of day as `H:i:s.u`, or null when there is nothing to store.
     *
     * @since   2.0.0
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('H:i:s.u');
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * Reads a stored time of day back, tolerating whole-second rows.
     *
     * Two formats are tried, the fractional one and then the whole-second one, so rows written before
     * the column gained its six digits still load. Both are anchored with `!` and parsed in UTC, which
     * lands the result on 1970-01-01 rather than on today's date, so two stored times always compare on
     * their time fields alone. A string neither format accepts falls through to the parent type.
     *
     * @param   mixed             $value     Raw column value; a string takes the microsecond path.
     * @param   AbstractPlatform  $platform  Platform the value was read from.
     *
     * @return  ?DateTimeImmutable  The stored time on the Unix epoch in UTC, or null when the column
     *          held no value.
     *
     * @since   2.0.0
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if (is_string($value)) {
            foreach (['!H:i:s.u', '!H:i:s'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
