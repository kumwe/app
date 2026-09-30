<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Performance;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\EngineExaminedRows;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tools\Performance\PerfDataset;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Record\Query\RecordCursor;
use Kumwe\Record\Query\RecordProjection;
use Kumwe\Record\Query\RecordQuerySpecification;
use Kumwe\Record\Query\RecordSort;
use Kumwe\Record\Query\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Qualifies every browse ordering against the declared deterministic aged dataset (P5-G, ADR 0021).
 *
 * A generated record table is grown with the capacity contract's 2,000-row aged dataset
 * (`tools/PerfDataset.php`, default seed): each row's creation and update instants are backdated to the
 * drawn age, and each row carries its own unique name and amount, a nullable display name that is empty on
 * every third row, and a nullable summary wider than the business-schema package indexes. Pages are read
 * through the production service and their captured statements are measured by the engine, not estimated:
 * MariaDB and MySQL through the session's storage-engine read counters, PostgreSQL through `EXPLAIN
 * (ANALYZE)`'s actual rows on the record table.
 *
 * Every ordering an installed index serves — the default last-updated order, a unique, an indexed and a
 * sortable-only field in either direction, and a nullable field in all four direction and null-placement
 * combinations — examines at most four times the page look-ahead on the first page and on a page nine pages
 * deep (eight times for a nullable key, which reads its valued and its empty rows as two capped ranges).
 * An ordering no index serves sorts at most 10,001 candidates and is refused once its candidates pass
 * 10,000 (docs/operations/scale-topology.md).
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordQueryCompiler::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
final class BrowseExaminedRowsIntegrationTest extends TestCase
{
    /**
     * Declared multiple of (page size + 1) an index-served browse page may examine per range it reads.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int EXAMINED_MULTIPLE = 4;

    /**
     * Page size the orderings are walked with.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int PAGE = 100;

    /**
     * Pages walked before the deep page is measured.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int DEPTH = 9;

    /**
     * Prefix every grown row's name carries, so the growth can be removed.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PREFIX = 'zz-examined-';

    /**
     * Index-served orderings examine a bounded multiple of the page on the first and on a deep page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIndexServedOrderingsExamineABoundedMultipleOfThePageOnTheAgedDataset(): void
    {
        [$container, $context, $records, $definition, $table, $database] = $this->agedInstallation();
        try {
            $orderings = [
                'default updated time' => [[], 1],
                'record identity' => [[new RecordSort('id', SortDirection::Descending)], 1],
                'unique ascending' => [[new RecordSort('name')], 1],
                'unique descending' => [[new RecordSort('name', SortDirection::Descending)], 1],
                'indexed descending' => [[new RecordSort('amount', SortDirection::Descending)], 1],
                'sortable only' => [[new RecordSort('status')], 1],
                'nullable ascending, empty last' => [[new RecordSort('display_name')], 2],
                'nullable ascending, empty first' => [[new RecordSort('display_name', nullsLast: false)], 2],
                'nullable descending, empty last' => [[new RecordSort('display_name', SortDirection::Descending)], 2],
                'nullable descending, empty first' => [
                    [new RecordSort('display_name', SortDirection::Descending, false)],
                    2,
                ],
            ];
            foreach ($orderings as $name => [$sorts, $ranges]) {
                $bound = self::EXAMINED_MULTIPLE * $ranges * (self::PAGE + 1);
                $statements = $this->walk($container, $context, $records, $definition, $table, $sorts);
                foreach ($statements as $depth => $statement) {
                    self::assertStringNotContainsString(
                        'COUNT(*) OVER',
                        $statement['sql'],
                        sprintf('The %s ordering must be index-served.', $name),
                    );
                    $examined = EngineExaminedRows::examined(
                        $database,
                        $statement['sql'],
                        $statement['params'],
                        $table->physicalName,
                    );
                    self::assertLessThanOrEqual($bound, $examined, sprintf(
                        'The %s page %d examined %d rows of a %d-row table.',
                        $name,
                        $depth === 0 ? 1 : self::DEPTH + 1,
                        $examined,
                        $this->rowCount($database, $table),
                    ));
                }
            }
        } finally {
            $this->shrink($database, $table);
        }
    }

    /**
     * An ordering no index serves sorts a capped candidate set and is refused once the set is exceeded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnindexedOrderingSortsABoundedCandidateSetAndIsRefusedPastIt(): void
    {
        [$container, $context, $records, $definition, $table, $database] = $this->agedInstallation();
        try {
            $unindexed = [
                'wide sortable field' => [new RecordSort('summary')],
                'two keys' => [new RecordSort('status'), new RecordSort('amount', SortDirection::Descending)],
            ];
            $cap = DoctrineBusinessRecordQueryCompiler::UNINDEXED_SORT_CANDIDATES + 1;
            $rows = $this->rowCount($database, $table);
            foreach ($unindexed as $name => $sorts) {
                $statements = $this->walk($container, $context, $records, $definition, $table, $sorts);
                foreach ($statements as $statement) {
                    self::assertStringContainsString('COUNT(*) OVER', $statement['sql'], $name);
                    self::assertStringContainsString(' LIMIT ' . $cap . ') ', $statement['sql'], $name);
                    $this->assertCandidateRead($database, $statement, $table, $rows, $name);
                }
            }

            $this->grow(
                $database,
                $table,
                new PerfDataset(PerfDataset::DEFAULT_SEED, $cap - 1),
                1_000_000,
            );
            self::assertGreaterThan($cap, $this->rowCount($database, $table));
            $counter = ContainerConnectionCounter::wrap($container);
            foreach ($unindexed as $name => $sorts) {
                $counter->reset();
                try {
                    $records->browse(new BrowseRecordsQuery(
                        $context,
                        $definition->handle,
                        new RecordQuerySpecification(sorts: $sorts, pageSize: self::PAGE),
                    ));
                    self::fail(sprintf('The %s ordering must be refused past its candidate bound.', $name));
                } catch (InvalidBusinessRecordQuery $refused) {
                    self::assertStringContainsString('has no index', $refused->getMessage());
                }
                $statement = $this->pageStatement($counter->statements(), $table->physicalName);
                $this->assertCandidateRead($database, $statement, $table, $cap, $name);
            }
            $page = $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(sorts: [new RecordSort('amount')], pageSize: self::PAGE),
            ));
            self::assertCount(self::PAGE, $page->records);
        } finally {
            $this->shrink($database, $table);
        }
    }

    /**
     * Install a definition, seed three records through the service and grow it with the aged dataset.
     *
     * @return  array{Container, ExecutionContext, BusinessRecordService, EntityTypeDefinition,
     *          PhysicalTableBlueprint, Connection}  The installation.
     *
     * @since   2.0.0
     */
    private function agedInstallation(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->service($container, BusinessRecordService::class);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('examined' . $suffix, Uuid::uuid7()->toString());
        self::assertIsArray($document['fields']);
        self::assertIsArray($document['fields'][0]);
        $document['fields'][0]['sortable'] = true;
        $document['fields'][] = [
            'handle' => 'summary',
            'label' => 'Summary',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 255,
            'sortable' => true,
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);
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
        $database = $this->service($container, Connection::class);
        require_once dirname(__DIR__, 3) . '/tools/PerfDataset.php';
        $this->grow($database, $table, new PerfDataset(PerfDataset::DEFAULT_SEED, 2_000), 0);

        return [$container, $context, $records, $definition, $table, $database];
    }

    /**
     * Walk an ordering through the service and capture its first and its deep page statement.
     *
     * @param   Container               $container   Container.
     * @param   ExecutionContext        $context     Administrator context.
     * @param   BusinessRecordService   $records     Record service.
     * @param   EntityTypeDefinition    $definition  Installed definition.
     * @param   PhysicalTableBlueprint  $table       Record table.
     * @param   list<RecordSort>        $sorts       Ordering.
     *
     * @return  list<array{sql: string, params: array<int|string, mixed>}>  The first and deep page statements.
     *
     * @since   2.0.0
     */
    private function walk(
        Container $container,
        ExecutionContext $context,
        BusinessRecordService $records,
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $sorts,
    ): array {
        $counter = ContainerConnectionCounter::wrap($container);
        $captured = [];
        $cursor = null;
        for ($page = 0; $page <= self::DEPTH; $page++) {
            $counter->reset();
            $result = $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(
                    sorts: $sorts,
                    after: $cursor === null ? null : RecordCursor::fromString($cursor),
                    pageSize: self::PAGE,
                    projection: new RecordProjection(['name']),
                ),
            ));
            self::assertCount(self::PAGE, $result->records);
            if ($page === 0 || $page === self::DEPTH) {
                $captured[] = $this->pageStatement($counter->statements(), $table->physicalName);
            }
            $cursor = $result->nextCursor?->value();
            self::assertNotNull($cursor);
        }

        return $captured;
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
            $sql = EngineExaminedRows::unbounded($statement['sql']);
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, $table) && str_contains($sql, 'ORDER BY')) {
                return ['sql' => $sql, 'params' => $statement['params']];
            }
        }
        self::fail('No page statement was captured.');
    }

    /**
     * Grow the table with aged rows that share its scope.
     *
     * Each row copies a seeded record, takes a fresh identity, its own name, amount and status, a display
     * name that is empty on every third row, a summary that is empty on every fourth, and creation and
     * update instants backdated by the dataset's drawn age.
     *
     * @param   Connection              $database  Connection.
     * @param   PhysicalTableBlueprint  $table     Record table.
     * @param   PerfDataset             $dataset   Declared aged dataset.
     * @param   int                     $offset    First name index, so a second growth never collides.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function grow(Connection $database, PhysicalTableBlueprint $table, PerfDataset $dataset, int $offset): void
    {
        $quoted = $database->quoteSingleIdentifier($table->physicalName);
        $template = $database->fetchAssociative(sprintf('SELECT * FROM %s', $quoted));
        self::assertIsArray($template);
        $types = [];
        foreach ($template as $column => $value) {
            if (is_resource($value)) {
                $template[$column] = (string) stream_get_contents($value);
                $types[$column] = ParameterType::BINARY;
            } elseif (is_bool($value)) {
                $types[$column] = ParameterType::BOOLEAN;
            } elseif (is_int($value)) {
                $types[$column] = ParameterType::INTEGER;
            } else {
                $types[$column] = ParameterType::STRING;
            }
        }
        $identity = $this->column($table, 'record_id');
        $name = $this->column($table, 'name');
        $amount = $this->column($table, 'amount');
        $status = $this->column($table, 'status');
        $display = $this->column($table, 'display_name');
        $summary = $this->column($table, 'summary');
        $created = $this->column($table, 'created_at');
        $updated = $this->column($table, 'updated_at');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $batch = [];
        $database->beginTransaction();
        try {
            foreach ($dataset->rows() as $aged) {
                $index = $offset + $aged['index'];
                $instant = $now->modify(sprintf('-%d seconds', $aged['age_seconds']))->format('Y-m-d H:i:s.u');
                $row = $template;
                $row[$identity] = Uuid::uuid7()->toString();
                $row[$name] = sprintf('%s%07d', self::PREFIX, $index);
                $row[$amount] = sprintf('%d.000000000000000000000000000000', 1_000_000 + $index);
                $row[$status] = $index % 2 === 0 ? 'draft' : 'ready';
                $row[$display] = $index % 3 === 0 ? null : sprintf('zz-display-%07d', ($index * 7_919) % 1_000_003);
                $row[$summary] = $index % 4 === 0 ? null : sprintf('zz-summary-%07d', ($index * 104_729) % 1_000_003);
                $row[$created] = $instant;
                $row[$updated] = $instant;
                $batch[] = $row;
                if (count($batch) === 250) {
                    $this->insert($database, $quoted, $types, $batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->insert($database, $quoted, $types, $batch);
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
     * Insert one batch of rows in a single statement.
     *
     * @param   Connection                    $database  Connection.
     * @param   string                        $quoted    Quoted table name.
     * @param   array<string, ParameterType>  $types     Binding type per column, in row order.
     * @param   list<array<string, mixed>>    $rows      Rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insert(Connection $database, string $quoted, array $types, array $rows): void
    {
        $placeholders = '(' . implode(', ', array_fill(0, count($types), '?')) . ')';
        $parameters = [];
        $bound = [];
        foreach ($rows as $row) {
            foreach ($types as $column => $type) {
                $parameters[] = $row[$column];
                $bound[] = $type;
            }
        }
        $database->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $quoted,
            implode(', ', array_map($database->quoteSingleIdentifier(...), array_keys($types))),
            implode(', ', array_fill(0, count($rows), $placeholders)),
        ), $parameters, $bound);
    }

    /**
     * Remove every grown row.
     *
     * @param   Connection              $database  Connection.
     * @param   PhysicalTableBlueprint  $table     Record table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function shrink(Connection $database, PhysicalTableBlueprint $table): void
    {
        $database->executeStatement(sprintf(
            'DELETE FROM %s WHERE %s LIKE ?',
            $database->quoteSingleIdentifier($table->physicalName),
            $database->quoteSingleIdentifier($this->column($table, 'name')),
        ), [self::PREFIX . '%']);
    }

    /**
     * Rows the record table holds.
     *
     * @param   Connection              $database  Connection.
     * @param   PhysicalTableBlueprint  $table     Record table.
     *
     * @return  int  Row count.
     *
     * @since   2.0.0
     */
    private function rowCount(Connection $database, PhysicalTableBlueprint $table): int
    {
        $count = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $database->quoteSingleIdentifier($table->physicalName),
        ));

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Assert that an unindexed ordering's statement read no more record rows than its candidate set.
     *
     * PostgreSQL and MariaDB report the rows read from the record table itself (`EXPLAIN (ANALYZE)`,
     * `ANALYZE FORMAT=JSON`), separately from the derived candidate set the sort and window run over. MySQL
     * is measured by the session's handler counters, which also count that derived set, so it is held to
     * a multiple of the candidates instead.
     *
     * @param   Connection                                            $database    Connection.
     * @param   array{sql: string, params: array<int|string, mixed>}  $statement   Page statement.
     * @param   PhysicalTableBlueprint                                $table       Record table.
     * @param   int                                                   $candidates  Most rows it may read.
     * @param   string                                                $name        Ordering under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertCandidateRead(
        Connection $database,
        array $statement,
        PhysicalTableBlueprint $table,
        int $candidates,
        string $name,
    ): void {
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $read = EngineExaminedRows::examined(
                $database,
                $statement['sql'],
                $statement['params'],
                $table->physicalName,
            );
        } elseif ($platform instanceof MariaDBPlatform) {
            $plan = $database->fetchOne(
                'ANALYZE FORMAT=JSON '
                    . EngineExaminedRows::literal($database, $statement['sql'], $statement['params']),
            );
            $decoded = json_decode(is_string($plan) ? $plan : '[]', true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $read = $this->analyzedRows($decoded);
        } else {
            $examined = EngineExaminedRows::examined(
                $database,
                $statement['sql'],
                $statement['params'],
                $table->physicalName,
            );
            $read = intdiv($examined, 10);
        }
        self::assertGreaterThan(0, $read, $name);
        self::assertLessThanOrEqual($candidates, $read, sprintf('The %s ordering read %d rows.', $name, $read));
    }

    /**
     * Sum MariaDB's measured rows over every access to the record table's alias.
     *
     * @param   array<mixed>  $node  `ANALYZE FORMAT=JSON` node or list.
     *
     * @return  int  Rows read from the record table.
     *
     * @since   2.0.0
     */
    private function analyzedRows(array $node): int
    {
        $rows = 0;
        if (($node['table_name'] ?? null) === 'r0') {
            $loops = is_numeric($node['r_loops'] ?? null) ? (float) $node['r_loops'] : 1.0;
            $read = is_numeric($node['r_rows'] ?? null) ? (float) $node['r_rows'] : 0.0;
            $rows += (int) round($read * $loops);
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $rows += $this->analyzedRows($child);
            }
        }

        return $rows;
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
