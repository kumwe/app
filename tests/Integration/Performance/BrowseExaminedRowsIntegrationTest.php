<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Performance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\Record\Query\RecordQuerySpecification;
use Kumwe\Record\Query\RecordSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Holds the rows an indexed browse page examines to the declared multiple of its size on a table far larger
 * than the page, measured by the engine rather than estimated.
 *
 * The page statements are captured from live browses ordered by a NOT NULL unique field and by a NOT NULL
 * indexed field, the table is then grown to fifteen hundred rows, each with its own value of both, and the
 * captured statements are measured again:
 * MariaDB and MySQL through the session's storage-engine read counters, PostgreSQL through `EXPLAIN
 * (ANALYZE)`'s actual row counts on the record table. A plan that scanned or sorted the table would examine
 * every row; the declared bound is four times the page plus its one-row look-ahead. The default ordering
 * (last update, newest first) has no index in the installed schema the business-schema package compiles,
 * so it is bounded by the statement's execution time rather than this multiple
 * (docs/operations/scale-topology.md).
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordQueryCompiler::class)]
final class BrowseExaminedRowsIntegrationTest extends TestCase
{
    /**
     * Declared multiple of (page size + 1) a browse page may examine.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int EXAMINED_MULTIPLE = 4;

    /**
     * Rows added after the page so a scan could not hide.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int GROWTH = 1_500;

    /**
     * Pages ordered by a unique and by an indexed NOT NULL field examine at most the declared multiple of
     * their size.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIndexedBrowsePageExaminesAtMostTheDeclaredMultipleOfItsSize(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->service($container, BusinessRecordService::class);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document('examined' . $suffix, Uuid::uuid7()->toString()),
        );
        foreach ([1, 2, 3] as $index) {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                NeutralBusinessFixture::recordValues('Examined ' . $index . ' ' . $suffix),
                NeutralBusinessFixture::idempotencyKey('examined-' . $index . '-' . $suffix),
                recordId: Uuid::uuid7()->toString(),
            ));
        }
        $installation = $this->service($container, BusinessSchemaInstallationRepository::class)
            ->find($definition->id);
        $table = $installation?->blueprint->table('record');
        self::assertNotNull($table);
        $counter = ContainerConnectionCounter::wrap($container);
        $pages = [];
        foreach (
            [
                'unique' => new RecordQuerySpecification(sorts: [new RecordSort('name')], pageSize: 20),
                'indexed' => new RecordQuerySpecification(sorts: [new RecordSort('amount')], pageSize: 20),
            ] as $name => $specification
        ) {
            $counter->reset();
            $page = $records->browse(new BrowseRecordsQuery($context, $definition->handle, $specification));
            self::assertCount(3, $page->records);
            $pages[$name] = $this->pageStatement($counter->statements(), $table->physicalName);
        }
        $database = $this->service($container, Connection::class);
        $this->grow($database, $table);
        try {
            foreach ($pages as $name => $statement) {
                $examined = $this->examined($database, $statement['sql'], $statement['params'], $table);
                self::assertLessThanOrEqual(
                    self::EXAMINED_MULTIPLE * 21,
                    $examined,
                    sprintf('The %s page examined %d rows of a %d-row table.', $name, $examined, self::GROWTH + 3),
                );
            }
        } finally {
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s LIKE ?',
                $database->quoteSingleIdentifier($table->physicalName),
                $database->quoteSingleIdentifier($this->column($table, 'name')),
            ), ['zz-examined-%']);
        }
    }

    /**
     * The page statement a browse sent, unwrapped from MariaDB's execution-time prefix.
     *
     * @param   list<array{sql: string, params: array<int|string, mixed>}>  $statements  Captured statements.
     * @param   string                                                      $table       Record table.
     *
     * @return  array{sql: string, params: array<int|string, mixed>}  The page statement.
     *
     * @since   2.0.0
     */
    private function pageStatement(array $statements, string $table): array
    {
        foreach ($statements as $statement) {
            $sql = ltrim($statement['sql']);
            if (preg_match('/^SET STATEMENT max_statement_time = [0-9.]+ FOR (SELECT\b.*)$/Ds', $sql, $bounded) === 1) {
                $sql = $bounded[1];
            }
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, $table) && str_contains($sql, 'LIMIT')) {
                return ['sql' => $sql, 'params' => $statement['params']];
            }
        }
        self::fail('No page statement was captured.');
    }

    /**
     * Grow the table with rows that share its scope, each with its own name and amount.
     *
     * @param   Connection              $database  Connection.
     * @param   PhysicalTableBlueprint  $table     Record table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function grow(Connection $database, PhysicalTableBlueprint $table): void
    {
        $quoted = $database->quoteSingleIdentifier($table->physicalName);
        $template = $database->fetchAssociative(sprintf('SELECT * FROM %s', $quoted));
        self::assertIsArray($template);
        $identity = $this->column($table, 'record_id');
        $name = $this->column($table, 'name');
        $amount = $this->column($table, 'amount');
        $types = [];
        foreach ($template as $column => $value) {
            if (is_resource($value)) {
                $template[$column] = (string) stream_get_contents($value);
                $types[$column] = ParameterType::BINARY;
            } elseif (is_bool($value)) {
                $types[$column] = ParameterType::BOOLEAN;
            } elseif (is_int($value)) {
                $types[$column] = ParameterType::INTEGER;
            }
        }
        $database->beginTransaction();
        try {
            for ($index = 1; $index <= self::GROWTH; $index++) {
                $row = $template;
                $row[$identity] = Uuid::uuid7()->toString();
                $row[$name] = sprintf('zz-examined-%05d', $index);
                $row[$amount] = sprintf('%d.000000000000000000000000000000', 1_000_000 + $index);
                $database->insert($table->physicalName, $row, $types);
            }
            $database->commit();
        } catch (\Throwable $failure) {
            $database->rollBack();
            throw $failure;
        }
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $database->executeStatement('ANALYZE ' . $quoted);
        } else {
            $database->fetchAllAssociative('ANALYZE TABLE ' . $quoted);
        }
    }

    /**
     * Rows the engine examined on the record table to answer the statement.
     *
     * @param   Connection                $database  Connection.
     * @param   string                    $sql       Page statement.
     * @param   array<int|string, mixed>  $params    Its bound values.
     * @param   PhysicalTableBlueprint    $table     Record table.
     *
     * @return  int  Examined rows.
     *
     * @since   2.0.0
     */
    private function examined(Connection $database, string $sql, array $params, PhysicalTableBlueprint $table): int
    {
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $plan = $database->fetchOne('EXPLAIN (ANALYZE, FORMAT JSON) ' . $this->literal($database, $sql, $params));
            $decoded = json_decode(is_string($plan) ? $plan : '[]', true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $this->examinedNodes($decoded, $table->physicalName);
        }
        $before = $this->handlerReads($database);
        $database->executeQuery($sql, array_values($params))->fetchAllAssociative();

        return $this->handlerReads($database) - $before;
    }

    /**
     * Sum actual examined rows over every plan node reading the table.
     *
     * @param   array<mixed>  $node   Plan node or list.
     * @param   string        $table  Record table.
     *
     * @return  int  Examined rows.
     *
     * @since   2.0.0
     */
    private function examinedNodes(array $node, string $table): int
    {
        $examined = 0;
        if (($node['Relation Name'] ?? null) === $table) {
            $loops = is_int($node['Actual Loops'] ?? null) ? $node['Actual Loops'] : 1;
            $rows = 0;
            foreach (['Actual Rows', 'Rows Removed by Filter', 'Rows Removed by Index Recheck'] as $key) {
                $rows += is_int($node[$key] ?? null) ? $node[$key] : 0;
            }
            $examined += $rows * $loops;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $examined += $this->examinedNodes($child, $table);
            }
        }

        return $examined;
    }

    /**
     * The session's storage-engine row reads so far.
     *
     * @param   Connection  $database  Connection.
     *
     * @return  int  Sum of the Handler_read counters.
     *
     * @since   2.0.0
     */
    private function handlerReads(Connection $database): int
    {
        $total = 0;
        foreach ($database->fetchAllAssociative("SHOW SESSION STATUS LIKE 'Handler_read%'") as $row) {
            $value = array_values($row)[1] ?? 0;
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }

    /**
     * Substitute bound values as literals for `EXPLAIN`.
     *
     * @param   Connection                $database  Connection.
     * @param   string                    $sql       Statement with positional placeholders.
     * @param   array<int|string, mixed>  $params    Bound values in order.
     *
     * @return  string  Literal statement.
     *
     * @since   2.0.0
     */
    private function literal(Connection $database, string $sql, array $params): string
    {
        foreach ($params as $value) {
            $replacement = match (true) {
                $value === null => 'NULL',
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                default => $database->quote(is_scalar($value) ? (string) $value : ''),
            };
            $position = strpos($sql, '?');
            self::assertNotFalse($position);
            $sql = substr_replace($sql, $replacement, $position, 1);
        }

        return $sql;
    }

    /**
     * Physical name of one logical column.
     *
     * @param   PhysicalTableBlueprint  $table    Record table.
     * @param   string                  $logical  Logical column.
     *
     * @return  string  Physical column.
     *
     * @since   2.0.0
     */
    private function column(PhysicalTableBlueprint $table, string $logical): string
    {
        $column = $table->column($logical);
        self::assertNotNull($column);

        return $column->physicalName;
    }

    /**
     * Resolve one strongly typed service from the container.
     *
     * @template T of object
     *
     * @param   Container        $container  Container.
     * @param   class-string<T>  $class      Requested service type.
     *
     * @return  T  Requested service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
