<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\MariaDB1010Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\Type\DoctrineTemporalTypes;
use Kumwe\CMS\Infrastructure\Persistence\Type\MicrosecondDateTimeImmutableType;
use Kumwe\CMS\Infrastructure\Persistence\Type\MicrosecondTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineTemporalTypes::class)]
#[CoversClass(MicrosecondDateTimeImmutableType::class)]
#[CoversClass(MicrosecondTimeImmutableType::class)]
final class DoctrineTemporalTypesTest extends TestCase
{
    public function testDeclarationsPreserveSixDigitsOnEverySupportedEngine(): void
    {
        $dateTime = new MicrosecondDateTimeImmutableType();
        $time = new MicrosecondTimeImmutableType();

        foreach ([new MariaDB1010Platform(), new MySQL84Platform()] as $platform) {
            self::assertSame('DATETIME(6)', $dateTime->getSQLDeclaration([], $platform));
            self::assertSame('TIME(6)', $time->getSQLDeclaration([], $platform));
        }

        $postgres = new PostgreSQLPlatform();
        self::assertSame('TIMESTAMP(6) WITHOUT TIME ZONE', $dateTime->getSQLDeclaration([], $postgres));
        self::assertSame('TIME(6) WITHOUT TIME ZONE', $time->getSQLDeclaration([], $postgres));
    }

    public function testExactRoundTripAndLegacyValuesRemainReadable(): void
    {
        $platform = new PostgreSQLPlatform();
        $dateTime = new MicrosecondDateTimeImmutableType();
        $time = new MicrosecondTimeImmutableType();
        $instant = new DateTimeImmutable('2026-08-08T11:14:15.123456+02:00');
        $localTime = DateTimeImmutable::createFromFormat(
            '!H:i:s.u',
            '13:14:15.654321',
            new DateTimeZone('UTC'),
        );
        self::assertInstanceOf(DateTimeImmutable::class, $localTime);

        $storedInstant = $dateTime->convertToPHPValue('2026-08-08 09:14:15.123456', $platform);
        $legacyInstant = $dateTime->convertToPHPValue('2026-08-08 09:14:15', $platform);
        $storedTime = $time->convertToPHPValue('13:14:15.654321', $platform);
        $legacyTime = $time->convertToPHPValue('13:14:15', $platform);

        self::assertSame('2026-08-08 09:14:15.123456', $dateTime->convertToDatabaseValue($instant, $platform));
        self::assertSame('2026-08-08 09:14:15.123456', $storedInstant?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-08-08 09:14:15.000000', $legacyInstant?->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $storedInstant?->getTimezone()->getName());
        self::assertSame('UTC', $legacyInstant?->getTimezone()->getName());
        self::assertSame('13:14:15.654321', $time->convertToDatabaseValue($localTime, $platform));
        self::assertSame('13:14:15.654321', $storedTime?->format('H:i:s.u'));
        self::assertSame('13:14:15.000000', $legacyTime?->format('H:i:s.u'));
        self::assertSame('UTC', $storedTime?->getTimezone()->getName());
        self::assertSame('UTC', $legacyTime?->getTimezone()->getName());
    }

    public function testRegistrationIsIdempotentAndKeepsCanonicalDoctrineNames(): void
    {
        DoctrineTemporalTypes::register();
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE);
        $time = Type::getType(Types::TIME_IMMUTABLE);

        DoctrineTemporalTypes::register();

        self::assertSame($dateTime, Type::getType(Types::DATETIME_IMMUTABLE));
        self::assertSame($time, Type::getType(Types::TIME_IMMUTABLE));
        self::assertSame(Types::DATETIME_IMMUTABLE, Type::lookupName($dateTime));
        self::assertSame(Types::TIME_IMMUTABLE, Type::lookupName($time));
    }

    public function testSupportedPlatformIntrospectionMapsBackToImmutableTypes(): void
    {
        DoctrineTemporalTypes::register();

        foreach ([new MariaDB1010Platform(), new MySQL84Platform()] as $platform) {
            self::assertSame(Types::DATETIME_IMMUTABLE, $platform->getDoctrineTypeMapping('datetime'));
            self::assertSame(Types::TIME_IMMUTABLE, $platform->getDoctrineTypeMapping('time'));
        }

        $postgres = new PostgreSQLPlatform();
        self::assertSame(Types::DATETIME_IMMUTABLE, $postgres->getDoctrineTypeMapping('timestamp'));
        self::assertSame(Types::TIME_IMMUTABLE, $postgres->getDoctrineTypeMapping('time'));
    }
}
