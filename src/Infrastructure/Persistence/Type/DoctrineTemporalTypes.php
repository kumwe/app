<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Type;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Installs the microsecond-preserving temporal types in place of Doctrine's whole-second ones.
 *
 * Doctrine's stock `datetime_immutable` and `time_immutable` types declare whole-second columns and
 * format without the fractional part, so every instant Kumwe stores — audit entries, revisions, the
 * restore manifest that compares values row for row — would round, and two events inside the same
 * second would become indistinguishable. This class is the one place that swap happens:
 * `DoctrineConnectionFactory` calls `register()` before building a connection, and because the
 * replacements take over the canonical Doctrine type names, every mapping in the tree keeps spelling
 * `datetime_immutable` and picks up the precise type without naming a Kumwe class.
 *
 * @since  2.0.0
 */
final class DoctrineTemporalTypes
{
    /**
     * Override the canonical Doctrine temporal type names with the microsecond-preserving types.
     *
     * Calling this more than once is harmless and deliberate: each name is only overridden while the
     * registry does not already hold the Kumwe implementation, so a second connection reuses the type
     * instances already handed out rather than replacing them underneath existing mappings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function register(): void
    {
        if (!Type::getType(Types::DATETIME_IMMUTABLE) instanceof MicrosecondDateTimeImmutableType) {
            Type::overrideType(Types::DATETIME_IMMUTABLE, MicrosecondDateTimeImmutableType::class);
        }
        if (!Type::getType(Types::TIME_IMMUTABLE) instanceof MicrosecondTimeImmutableType) {
            Type::overrideType(Types::TIME_IMMUTABLE, MicrosecondTimeImmutableType::class);
        }
    }
}
