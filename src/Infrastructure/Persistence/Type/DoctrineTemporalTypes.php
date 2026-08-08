<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Type;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class DoctrineTemporalTypes
{
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
