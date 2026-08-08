<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

final class MicrosecondDateTimeImmutableType extends DateTimeImmutableType
{
    /** @return list<string> */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => ['datetime'],
            $platform instanceof PostgreSQLPlatform => ['timestamp'],
            default => [],
        };
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'DATETIME(6)',
            $platform instanceof PostgreSQLPlatform => 'TIMESTAMP(6) WITHOUT TIME ZONE',
            default => parent::getSQLDeclaration($column, $platform),
        };
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

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
