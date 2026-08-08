<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSchema\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\CMS\BusinessSchema\Infrastructure\Schema\DoctrinePhysicalSchemaGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(DoctrinePhysicalSchemaGateway::class)]
final class DoctrinePhysicalSchemaGatewayDefaultTest extends TestCase
{
    public function testBooleanDefaultsMatchEverySupportedDbalIntrospectionRepresentation(): void
    {
        $gateway = $this->gateway();
        $column = self::column('enabled', 'boolean', ['default' => false]);

        foreach ([false, 0, '0', 'false', 'f'] as $actual) {
            self::assertTrue($this->invoke($gateway, 'defaultMatches', [$actual, false, $column]));
        }
        foreach ([true, 1, '1', 'true', 't'] as $actual) {
            self::assertFalse($this->invoke($gateway, 'defaultMatches', [$actual, false, $column]));
        }
    }

    public function testExactDecimalAndTemporalDefaultsUsePortablePhysicalForms(): void
    {
        $gateway = $this->gateway();
        $decimal = self::column('amount', 'decimal', [
            'precision' => 12,
            'scale' => 4,
            'default' => '1.25',
        ]);
        $time = self::column('local_time', 'time_immutable', ['default' => '13:14:15']);
        $instant = self::column(
            'recorded_at',
            'datetime_immutable',
            ['default' => '2026-08-08T11:14:15.123456Z'],
        );

        self::assertSame(
            '1.2500',
            $this->invoke($gateway, 'columnOptions', [$decimal])['default'] ?? null,
        );
        self::assertSame(
            '13:14:15.000000',
            $this->invoke($gateway, 'columnOptions', [$time])['default'] ?? null,
        );
        self::assertSame(
            '2026-08-08 11:14:15.123456',
            $this->invoke($gateway, 'columnOptions', [$instant])['default'] ?? null,
        );
        self::assertTrue($this->invoke($gateway, 'defaultMatches', ['1.2500', '1.25', $decimal]));
        self::assertTrue($this->invoke(
            $gateway,
            'defaultMatches',
            ['2026-08-08 11:14:15.123456', '2026-08-08T11:14:15.123456Z', $instant],
        ));
    }

    public function testTemporalBackfillValuesAreConvertedForImmutableDbalTypes(): void
    {
        $gateway = $this->gateway();
        $instant = self::column('recorded_at', 'datetime_immutable');
        $value = $this->invoke(
            $gateway,
            'boundPhysicalValue',
            [$instant, '2026-08-08T11:14:15.123456Z'],
        );

        self::assertInstanceOf(DateTimeImmutable::class, $value);
        self::assertSame('2026-08-08 11:14:15.123456+00:00', $value->format('Y-m-d H:i:s.uP'));
    }

    public function testDecimalDefaultOutsideDeclaredScaleIsRejectedBeforeDdl(): void
    {
        $gateway = $this->gateway();
        $column = self::column('amount', 'decimal', [
            'precision' => 12,
            'scale' => 2,
            'default' => '1.234',
        ]);

        $this->expectException(InvalidBusinessSchema::class);
        $this->invoke($gateway, 'columnOptions', [$column]);
    }

    public function testIntegerDefaultOutsidePortableColumnRangeIsRejectedBeforeDdl(): void
    {
        $gateway = $this->gateway();
        $column = self::column('sequence', 'integer', ['default' => 2_147_483_648]);

        $this->expectException(InvalidBusinessSchema::class);
        $this->invoke($gateway, 'columnOptions', [$column]);
    }

    public function testStringDefaultOutsideDeclaredLengthIsRejectedBeforeDdl(): void
    {
        $gateway = $this->gateway();
        $column = self::column('code', 'string', ['length' => 3, 'default' => 'TOO-LONG']);

        $this->expectException(InvalidBusinessSchema::class);
        $this->invoke($gateway, 'columnOptions', [$column]);
    }

    public function testMySqlFamilyIntrospectionAliasesPreserveExactPhysicalShape(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $gateway = $this->gateway($platform);

            self::assertTrue($this->columnMatches(
                $gateway,
                self::actualColumn('record_id', 'string', ['length' => 36, 'fixed' => true]),
                self::column('record_id', 'guid'),
            ));
            self::assertFalse($this->columnMatches(
                $gateway,
                self::actualColumn('record_id', 'string', ['length' => 35, 'fixed' => true]),
                self::column('record_id', 'guid'),
            ));
            self::assertTrue($this->columnMatches(
                $gateway,
                self::actualColumn('currency', 'string', ['length' => 3, 'fixed' => true]),
                self::column('currency', 'ascii_string', ['length' => 3, 'fixed' => true]),
            ));
        }
    }

    public function testPostgreSqlIntrospectionAliasesOnlyIgnoreUnrepresentableBinaryOptions(): void
    {
        $gateway = $this->gateway(new PostgreSQLPlatform());

        self::assertTrue($this->columnMatches(
            $gateway,
            self::actualColumn('nonce', 'blob'),
            self::column('nonce', 'binary', ['length' => 24, 'fixed' => true]),
        ));
        self::assertFalse($this->columnMatches(
            $gateway,
            self::actualColumn('nonce', 'binary', ['length' => 24, 'fixed' => true]),
            self::column('nonce', 'blob'),
        ));
        self::assertFalse($this->columnMatches(
            $gateway,
            self::actualColumn('currency', 'string', ['length' => 4, 'fixed' => true]),
            self::column('currency', 'ascii_string', ['length' => 3, 'fixed' => true]),
        ));
    }

    public function testImmutableDateMatchesDbalMutablePhysicalIntrospectionType(): void
    {
        $gateway = $this->gateway(new PostgreSQLPlatform());

        self::assertTrue($this->columnMatches(
            $gateway,
            self::actualColumn('service_date', 'date'),
            self::column('service_date', 'date_immutable'),
        ));
    }

    public function testIntrospectablePlatformOptionsStillDetectPhysicalDrift(): void
    {
        $gateway = $this->gateway(new PostgreSQLPlatform());
        $jsonb = self::actualColumn('payload', 'json');
        (new ReflectionProperty(Column::class, '_platformOptions'))->setValue($jsonb, ['jsonb' => true]);

        self::assertFalse($this->columnMatches(
            $gateway,
            $jsonb,
            self::column('payload', 'json'),
        ));
        self::assertFalse($this->columnMatches(
            $gateway,
            self::actualColumn('sequence', 'integer'),
            self::column('sequence', 'integer', ['autoincrement' => true]),
        ));
        self::assertFalse($this->columnMatches(
            $gateway,
            self::actualColumn('code', 'string', ['length' => 3, 'comment' => 'drift']),
            self::column('code', 'string', ['length' => 3]),
        ));
    }

    public function testMutableTemporalAliasesStillRequireExactMicrosecondPhysicalPrecision(): void
    {
        $gateway = $this->gateway(new PostgreSQLPlatform());
        foreach (
            [
            'time_immutable' => 'time',
            'datetime_immutable' => 'datetime',
            'datetimetz_immutable' => 'datetimetz',
            ] as $expectedType => $actualType
        ) {
            $expected = self::column('temporal_' . $actualType, $expectedType);
            $actual = self::actualColumn($expected->physicalName, $actualType);

            self::assertTrue($this->columnMatches(
                $gateway,
                $actual,
                $expected,
                [$expected->physicalName => 6],
            ));
            self::assertFalse($this->columnMatches(
                $gateway,
                $actual,
                $expected,
                [$expected->physicalName => 0],
            ));
        }
    }

    public function testExactTableComparisonReadsColumnNamesFromDbalListValues(): void
    {
        $gateway = $this->gateway(new PostgreSQLPlatform());
        $column = self::column('record_id', 'guid');
        $actual = new Table('kb_e_record_1234567890abcdef');
        $actual->addColumn($column->physicalName, 'guid');
        $actual->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames($column->physicalName)->create(),
        );
        $expected = new PhysicalTableBlueprint(
            'record',
            'kb_e_record_1234567890abcdef',
            PhysicalTableKind::Entity,
            [$column],
            [$column->physicalName],
        );

        self::assertSame([0], array_keys($actual->getColumns()));
        self::assertTrue($this->invoke($gateway, 'tableMatches', [$actual, $expected]));
    }

    private function gateway(?AbstractPlatform $platform = null): DoctrinePhysicalSchemaGateway
    {
        $database = $this->createStub(Connection::class);
        $database->method('getDatabasePlatform')->willReturn($platform ?? new PostgreSQLPlatform());

        return new DoctrinePhysicalSchemaGateway($database);
    }

    /** @param list<mixed> $arguments */
    private function invoke(
        DoctrinePhysicalSchemaGateway $gateway,
        string $method,
        array $arguments,
    ): mixed {
        return (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$arguments);
    }

    /** @param array<string, int> $temporalPrecisions */
    private function columnMatches(
        DoctrinePhysicalSchemaGateway $gateway,
        Column $actual,
        PhysicalColumnBlueprint $expected,
        array $temporalPrecisions = [],
    ): bool {
        return $this->invoke($gateway, 'columnMatches', [$actual, $expected, $temporalPrecisions]);
    }

    /** @param array<string, mixed> $options */
    private static function actualColumn(string $name, string $type, array $options = []): Column
    {
        return new Column($name, Type::getType($type), ['notnull' => true, ...$options]);
    }

    /** @param array<string, mixed> $options */
    private static function column(
        string $logical,
        string $type,
        array $options = [],
    ): PhysicalColumnBlueprint {
        return new PhysicalColumnBlueprint(
            $logical,
            'c_' . $logical . '_1234567890abcdef',
            $type,
            $options,
        );
    }
}
