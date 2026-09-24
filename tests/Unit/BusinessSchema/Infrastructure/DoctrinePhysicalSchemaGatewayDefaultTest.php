<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Infrastructure;

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
use Kumwe\BusinessDefinition\Domain\Expression;
use Kumwe\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\BusinessSchema\Domain\SchemaOperation;
use Kumwe\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\BusinessSchema\Domain\SchemaRisk;
use Kumwe\App\BusinessDefinition\Application\FormulaEvaluation;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\DoctrinePhysicalSchemaGateway;
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

    /**
     * A backfill with an Expression source reads every row of the chunk, evaluates the formula once for
     * the whole batch through the port, and writes each computed value to its own row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBackfillEvaluatesTheColumnFormulaAsOneBatchPerChunk(): void
    {
        $identity = self::column('id', 'integer');
        $name = self::column('name', 'string', ['length' => 64]);
        $code = self::column('code', 'string', ['length' => 64]);
        $operation = new SchemaOperation(
            1,
            SchemaOperationKind::Backfill,
            SchemaRisk::BackfillRequired,
            'record',
            'code',
            null,
            [
                'column' => $code->toArray(),
                'expression' => ['op' => 'field', 'type' => 'string', 'field' => 'name'],
                'dependencies' => ['name' => $name->toArray()],
            ],
            true,
        );
        $updates = [];
        $database = $this->createMock(Connection::class);
        $database->method('fetchAllAssociative')->willReturn([
            ['backfill_identity' => 1, 'backfill_value_0' => 'Alpha'],
            ['backfill_identity' => 2, 'backfill_value_0' => 'Beta'],
        ]);
        $database->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$updates): int {
                $updates[] = $parameters;

                return 1;
            });
        $formulas = $this->createMock(FormulaEvaluation::class);
        $formulas->expects(self::once())
            ->method('evaluateAll')
            ->with(self::isInstanceOf(Expression::class), [
                ['fields' => ['name' => 'Alpha'], 'lines' => []],
                ['fields' => ['name' => 'Beta'], 'lines' => []],
            ])
            ->willReturn(['Alpha!', 'Beta!']);
        $gateway = new DoctrinePhysicalSchemaGateway($database, $formulas);

        $result = $gateway->backfillChunk(
            $operation,
            self::blueprint([$identity, $name, $code], $identity),
            null,
            10,
        );

        self::assertSame(2, $result->processed);
        self::assertTrue($result->complete);
        self::assertSame(['last_identity' => 2], $result->cursor);
        self::assertSame([['Alpha!', 1], ['Beta!', 2]], $updates);
    }

    /**
     * A transform reads the dependency values of every row of the chunk, evaluates the conversion
     * formula once for the whole batch through the port, and writes each result to the shadow column.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTransformEvaluatesTheConversionFormulaAsOneBatchPerChunk(): void
    {
        $identity = self::column('id', 'integer');
        $name = self::column('name', 'string', ['length' => 64]);
        $shadow = self::column('name_shadow', 'string', ['length' => 64]);
        $operation = new SchemaOperation(
            1,
            SchemaOperationKind::Transform,
            SchemaRisk::RebuildOrLocking,
            'record',
            'name.transform',
            $name->toArray(),
            [
                'source' => $name->toArray(),
                'target' => $shadow->toArray(),
                'expression' => ['op' => 'field', 'type' => 'string', 'field' => 'name'],
                'dependencies' => ['name' => $name->toArray()],
                'primary_key' => [$identity->physicalName],
            ],
            true,
        );
        $updates = [];
        $database = $this->createMock(Connection::class);
        $database->method('fetchAllAssociative')->willReturn([
            ['transform_identity' => 7, 'transform_value_0' => 'Alpha'],
            ['transform_identity' => 8, 'transform_value_0' => null],
        ]);
        $database->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$updates): int {
                $updates[] = $parameters;

                return 1;
            });
        $formulas = $this->createMock(FormulaEvaluation::class);
        $formulas->expects(self::once())
            ->method('evaluateAll')
            ->with(self::isInstanceOf(Expression::class), [
                ['fields' => ['name' => 'Alpha'], 'lines' => []],
                ['fields' => ['name' => null], 'lines' => []],
            ])
            ->willReturn(['ALPHA', null]);
        $gateway = new DoctrinePhysicalSchemaGateway($database, $formulas);

        $result = $gateway->transformChunk(
            $operation,
            self::blueprint([$identity, $name, $shadow], $identity),
            ['last_identity' => 6],
            2,
        );

        self::assertSame(2, $result->processed);
        self::assertFalse($result->complete);
        self::assertSame(['last_identity' => 8], $result->cursor);
        self::assertSame([['ALPHA', 7], [null, 8]], $updates);
    }

    /**
     * Build a single-table blueprint keyed on the given identity column.
     *
     * @param   list<PhysicalColumnBlueprint>  $columns   Columns of the record table.
     * @param   PhysicalColumnBlueprint        $identity  Column that forms the primary key.
     *
     * @return  PhysicalSchemaBlueprint  Blueprint whose only table is `record`.
     *
     * @since   2.0.0
     */
    private static function blueprint(array $columns, PhysicalColumnBlueprint $identity): PhysicalSchemaBlueprint
    {
        return new PhysicalSchemaBlueprint(
            '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
            1,
            hash('sha256', 'record'),
            [new PhysicalTableBlueprint(
                'record',
                'kb_e_record_1234567890abcdef',
                PhysicalTableKind::Entity,
                $columns,
                [$identity->physicalName],
            )],
        );
    }

    private function gateway(?AbstractPlatform $platform = null): DoctrinePhysicalSchemaGateway
    {
        $database = $this->createStub(Connection::class);
        $database->method('getDatabasePlatform')->willReturn($platform ?? new PostgreSQLPlatform());

        return new DoctrinePhysicalSchemaGateway($database, $this->createStub(FormulaEvaluation::class));
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
