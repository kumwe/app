<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Performance;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineProcessManagerStore;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreListingSortIndexMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\EngineExaminedRows;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tools\Performance\PerfDataset;
use Kumwe\Content\Application\ContentBrowseQuery;
use Kumwe\Content\Application\ContentRepository;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Integration\ProcessManagerStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Holds the core listings whose ordering the core schema now indexes to a bounded page on aged data (P5-G).
 *
 * The content browser's title orderings and the operator's recent-process listing are grown with the
 * capacity contract's 2,000-row aged dataset (`tools/PerfDataset.php`, default seed), whose drawn ages
 * become each row's creation and update instants, and the statements the repositories actually send are
 * measured by the engine (`EngineExaminedRows`). Before `CoreListingSortIndexMigration` both sorted every
 * row before returning the first batch.
 *
 * @since  2.0.0
 */
#[CoversClass(CoreListingSortIndexMigration::class)]
#[CoversClass(DoctrineContentRepository::class)]
#[CoversClass(DoctrineProcessManagerStore::class)]
final class CoreListingSortPlanIntegrationTest extends TestCase
{
    /**
     * Declared multiple of the batch size an index-served listing may examine.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int EXAMINED_MULTIPLE = 4;

    /**
     * Rows the listings are asked for.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int BATCH = 50;

    /**
     * Marker every grown row carries, so the growth can be removed.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PREFIX = 'zz-core-listing-';

    /**
     * Content title orderings and the recent-process listing examine a bounded multiple of the batch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreListingOrderingsExamineABoundedMultipleOfTheBatchOnTheAgedDataset(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $indexes = CoreListingSortIndexMigration::indexes($tables);
        $manager = $database->createSchemaManager();
        foreach ($indexes as $table => [$name]) {
            self::assertTrue($manager->introspectTableByUnquotedName($table)->hasIndex($name), $name);
        }
        require_once dirname(__DIR__, 3) . '/tools/PerfDataset.php';
        $dataset = new PerfDataset(PerfDataset::DEFAULT_SEED, 2_000);
        $content = $tables->raw('content_entries');
        $processes = $tables->raw('business_process_instances');
        $bound = self::EXAMINED_MULTIPLE * self::BATCH;
        try {
            $this->growContent($database, $content, $dataset);
            $this->growProcesses($database, $processes, $dataset);
            $repository = $this->service($container, ContentRepository::class);
            self::assertInstanceOf(DoctrineContentRepository::class, $repository);
            $store = $this->service($container, ProcessManagerStore::class);
            $counter = ContainerConnectionCounter::wrap($container);
            foreach (['title_asc', 'title_desc', 'updated_asc', 'updated_desc'] as $sort) {
                $counter->reset();
                $batch = $repository->searchForSite(
                    SiteContext::default(),
                    new ContentBrowseQuery(sort: $sort),
                    self::BATCH,
                    0,
                );
                self::assertCount(self::BATCH, $batch);
                $this->assertBounded($database, $counter->statements(), $content, $bound, 'content ' . $sort);
            }
            $counter->reset();
            self::assertCount(self::BATCH, $store->recent(self::BATCH));
            $this->assertBounded($database, $counter->statements(), $processes, $bound, 'recent processes');
        } finally {
            $database->executeStatement(
                sprintf('DELETE FROM %s WHERE slug LIKE ?', $database->quoteSingleIdentifier($content)),
                [self::PREFIX . '%'],
            );
            $database->executeStatement(
                sprintf('DELETE FROM %s WHERE process_type = ?', $database->quoteSingleIdentifier($processes)),
                [self::PREFIX . 'process'],
            );
        }
    }

    /**
     * Assert that every captured statement reading the listed table examined no more than the bound.
     *
     * @param   Connection                                                  $database    Connection.
     * @param   list<array{sql: string, params: array<int|string, mixed>}>  $statements  Captured statements.
     * @param   string                                                      $table       Listed table.
     * @param   int                                                         $bound       Most rows each may
     *          examine.
     * @param   string                                                      $name        Listing under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertBounded(
        Connection $database,
        array $statements,
        string $table,
        int $bound,
        string $name,
    ): void {
        $measured = 0;
        foreach ($statements as $statement) {
            if (!str_contains($statement['sql'], $table)) {
                continue;
            }
            $examined = EngineExaminedRows::examined($database, $statement['sql'], $statement['params'], $table);
            self::assertLessThanOrEqual(
                $bound,
                $examined,
                sprintf('A %s statement examined %d rows: %s', $name, $examined, $statement['sql']),
            );
            ++$measured;
        }
        self::assertGreaterThan(0, $measured, $name);
    }

    /**
     * Grow the default site's content with active, aged entries copied from an existing entry.
     *
     * @param   Connection   $database  Connection.
     * @param   string       $table     Prefixed content table.
     * @param   PerfDataset  $dataset   Declared aged dataset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function growContent(Connection $database, string $table, PerfDataset $dataset): void
    {
        $template = $database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND translation_group_id IS NULL',
            $database->quoteSingleIdentifier($table),
        ), ['default']);
        self::assertIsArray($template);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->beginTransaction();
        try {
            foreach ($dataset->rows() as $aged) {
                $instant = $now->modify(sprintf('-%d seconds', $aged['age_seconds']))->format('Y-m-d H:i:s.u');
                $row = $template;
                $row['id'] = Uuid::uuid7()->toString();
                $row['title'] = sprintf('Listing %07d', ($aged['index'] * 7_919) % 1_000_003);
                $row['slug'] = sprintf('%s%07d', self::PREFIX, $aged['index']);
                $row['deleted_at'] = null;
                $row['locale'] = null;
                $row['created_at'] = $instant;
                $row['updated_at'] = $instant;
                $database->insert($table, $row);
            }
            $database->commit();
        } catch (\Throwable $failure) {
            $database->rollBack();
            throw $failure;
        }
        $this->analyze($database, $table);
    }

    /**
     * Grow the process instances with aged, running instances of one marker process type.
     *
     * @param   Connection   $database  Connection.
     * @param   string       $table     Prefixed process-instance table.
     * @param   PerfDataset  $dataset   Declared aged dataset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function growProcesses(Connection $database, string $table, PerfDataset $dataset): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->beginTransaction();
        try {
            foreach ($dataset->rows() as $aged) {
                $instant = $now->modify(sprintf('-%d seconds', $aged['age_seconds']))->format('Y-m-d H:i:s.u');
                $database->insert($table, [
                    'process_id' => Uuid::uuid7()->toString(),
                    'process_type' => self::PREFIX . 'process',
                    'correlation_id' => sprintf('aged-%07d', $aged['index']),
                    'site_identifier' => 'default',
                    'version' => 1,
                    'status' => 'running',
                    'state' => '{}',
                    'created_at' => $instant,
                    'updated_at' => $instant,
                ]);
            }
            $database->commit();
        } catch (\Throwable $failure) {
            $database->rollBack();
            throw $failure;
        }
        $this->analyze($database, $table);
    }

    /**
     * Refresh the planner's statistics for a grown table.
     *
     * @param   Connection  $database  Connection.
     * @param   string      $table     Prefixed table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function analyze(Connection $database, string $table): void
    {
        $quoted = $database->quoteSingleIdentifier($table);
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $database->executeStatement('ANALYZE ' . $quoted);
        } else {
            $database->fetchAllAssociative('ANALYZE TABLE ' . $quoted);
        }
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
