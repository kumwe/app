<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\TimeImmutableType;

final class MicrosecondTimeImmutableType extends TimeImmutableType
{
    /** @return list<string> */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform => ['time'],
            default => [],
        };
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'TIME(6)',
            $platform instanceof PostgreSQLPlatform => 'TIME(6) WITHOUT TIME ZONE',
            default => parent::getSQLDeclaration($column, $platform),
        };
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('H:i:s.u');
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

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
