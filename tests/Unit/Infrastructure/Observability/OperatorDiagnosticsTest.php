<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionObservation;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Mcp\OperatorDiagnosticsMcpHandlers;
use Kumwe\App\Infrastructure\Observability\DoctrineOperatorDiagnostics;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ScriptedDiagnosticDatabase;
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins operator authority, safe unavailable answers, measured queue/retention results and every engine branch.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineOperatorDiagnostics::class)]
#[CoversClass(OperatorDiagnosticsMcpHandlers::class)]
final class OperatorDiagnosticsTest extends TestCase
{
    /**
     * A denied operator never reaches a database or retention probe, even with malformed input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizationPrecedesEveryProbeAndInputDisclosure(): void
    {
        $authorization = self::createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed')->willThrowException(new AuthorizationDenied(
            'operator',
            OperatorDiagnostics::CAPABILITY,
            'operator_diagnostics',
            '*',
            'default',
            'operator',
            'global_grant_required',
        ));
        $retention = self::createMock(RetentionObserver::class);
        $retention->expects(self::never())->method('observeAll');
        $reader = $this->reader($this->database(), $authorization, $retention);
        $this->expectException(AuthorizationDenied::class);
        $reader->read(AuthorizationContext::human([]), 'invalid');
    }

    /**
     * Engine statistics that are absent never become a false healthy result or disclose a driver error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingEngineStatisticsAreExplicitlyUnavailable(): void
    {
        $reader = $this->reader($this->database());
        $mcp = new OperatorDiagnosticsMcpHandlers($reader);
        foreach (['contention', 'slow'] as $section) {
            $result = $mcp->read(AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]), $section);
            self::assertSame('unavailable', $result['status']);
            self::assertSame([], $result['rows']);
            self::assertSame(1000, $result['statement_timeout_ms']);
            self::assertStringNotContainsString('SQLSTATE', json_encode($result, JSON_THROW_ON_ERROR));
        }
        $this->expectException(InvalidArgumentException::class);
        $reader->read(AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]), 'SELECT secret');
    }

    /**
     * Queue diagnostics reveal only stream classes and bounded counts, never payloads or tenant identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQueueRowsAreGroupedAndRedacted(): void
    {
        $database = $this->database();
        foreach (
            [
            'jobs' => ['queue', 'created_at'],
            'integration_outbox' => ['event_type', 'created_at'],
            'integration_inbox' => ['consumer_id', 'first_received_at'],
            ] as $table => [$group, $date]
        ) {
            $database->executeStatement(sprintf(
                'CREATE TABLE kumwe_%s (%s TEXT, %s TEXT, status TEXT, payload TEXT)',
                $table,
                $group,
                $date,
            ));
            foreach (['pending', 'reserved', 'completed'] as $status) {
                $database->insert('kumwe_' . $table, [
                    $group => 'core.work',
                    $date => '2026-09-29T10:00:00+00:00',
                    'status' => $status,
                    'payload' => 'private-payload-never-returned',
                ]);
            }
        }
        $result = $this->reader($database)->read(AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]));
        self::assertSame('available', $result['status']);
        self::assertIsArray($result['rows']);
        self::assertCount(3, $result['rows']);
        foreach ($result['rows'] as $row) {
            self::assertIsArray($row);
            self::assertSame(2, $row['depth_lower_bound']);
            self::assertSame(60, $row['oldest_age_seconds']);
        }
        self::assertStringNotContainsString('private-payload', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * Retention diagnostics reuse the existing observation and duty cycle without inventing unknown rates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetentionUsesSustainedDrainAndPreservesUnknownValues(): void
    {
        $now = new DateTimeImmutable('2026-09-29T10:01:00+00:00');
        $retention = self::createStub(RetentionObserver::class);
        $retention->method('observeAll')->willReturn([
            new RetentionObservation(
                RetentionStore::JobHistory,
                $now,
                10.0,
                5.0,
                12.0,
                100,
                true,
                60.0,
                200.0,
                1000,
                true,
                [],
                $now,
            ),
            new RetentionObservation(
                RetentionStore::ExportArtifacts,
                $now,
                null,
                null,
                null,
                0,
                false,
                null,
                null,
                1000,
                false,
                [],
                null,
            ),
        ]);
        $reader = $this->reader($this->database(), retention: $retention);
        foreach (['backlog', 'retention'] as $section) {
            $result = $reader->read(AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]), $section);
            self::assertIsArray($result['rows']);
            self::assertIsArray($result['rows'][0]);
            self::assertIsArray($result['rows'][1]);
            $duty = RetentionCatalogue::declared()->policy(RetentionStore::JobHistory)->dutyCycle();
            self::assertSame(12.0 * $duty, $result['rows'][0]['sustained_drain_rows_per_second']);
            self::assertSame(10.0 - 12.0 * $duty, $result['rows'][0]['net_growth_rows_per_second']);
            self::assertNull($result['rows'][1]['net_growth_rows_per_second']);
            self::assertNull($result['rows'][1]['forecast_seconds_to_capacity']);
        }
    }

    /**
     * Each engine reads its own lock views under its own server-side cancellation, and MySQL checks first that
     * the performance schema holding those views is on, so "no waits" is never read from a switched-off source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentionReadsEachEngineOwnLockViewsUnderItsOwnTimeout(): void
    {
        $waits = [['table_name' => 'kumwe_jobs', 'lock_class' => 'X', 'waiting' => 2]];
        $cases = [
            'postgresql' => [new PostgreSQLPlatform(), 'pg_locks', "SET LOCAL statement_timeout = '1000ms'", 1],
            'mariadb' => [new MariaDBPlatform(), 'INNODB_LOCK_WAITS', 'SET STATEMENT max_statement_time = 1.0', 1],
            'mysql' => [new MySQLPlatform(), 'data_lock_waits', '/*+ MAX_EXECUTION_TIME(1000) */', 2],
        ];
        foreach ($cases as $engine => [$platform, $view, $timeout, $limit]) {
            $database = $this->scripted($platform, static fn (string $sql): array => match (true) {
                str_contains($sql, '@@performance_schema AS ready') => [['ready' => '1']],
                str_contains($sql, $view) => $waits,
                default => [],
            });
            $result = $this->reader($database->connection())->read($this->operator(), 'contention');
            self::assertSame($engine, $result['engine']);
            self::assertSame('available', $result['status'], $engine);
            self::assertNull($result['status_reason']);
            self::assertSame($waits, $result['rows']);
            self::assertSame('engine_statistics', $result['cost_class']);
            self::assertSame($limit, $result['statement_limit']);
            self::assertSame($limit * 1000, $result['elapsed_ceiling_ms']);
            $reads = $this->selects($database);
            self::assertCount($limit, $reads, $engine);
            self::assertStringContainsString($view, $reads[$limit - 1]);
            self::assertStringContainsString($timeout, implode("\n", array_column($database->statements, 'sql')));
        }

        $off = $this->scripted(new MySQLPlatform(), static fn (string $sql): array => str_contains(
            $sql,
            '@@performance_schema AS ready',
        ) ? [['ready' => '0']] : $waits);
        $result = $this->reader($off->connection())->read($this->operator(), 'contention');
        self::assertSame('unavailable', $result['status']);
        self::assertSame('statement_statistics_off', $result['status_reason']);
        self::assertSame([], $result['rows']);
        self::assertCount(1, $this->selects($off));
    }

    /**
     * Each engine ranks definitions from its own statement digests after proving they are being collected, and
     * neither raw statement text nor digests that name no installed definition leave the reader.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSlowRanksDefinitionsFromEachEngineDigestOnlyWhileCollecting(): void
    {
        $id = '018f22e2-7c8b-7ab0-8f3a-88e8026bb3aa';
        $table = (new PhysicalNameCompiler('kumwe_'))->entityTable($id, 'invoice');
        $cases = [
            'postgresql' => [new PostgreSQLPlatform(), 'pg_stat_statements', 3],
            'mariadb' => [new MariaDBPlatform(), 'events_statements_summary_by_digest', 4],
            'mysql' => [new MySQLPlatform(), 'events_statements_summary_by_digest', 4],
        ];
        foreach ($cases as $engine => [$platform, $digests, $limit]) {
            foreach ([['1', 'available'], ['0', 'unavailable']] as [$ready, $status]) {
                $database = $this->scripted($platform, static fn (string $sql): array => match (true) {
                    str_contains($sql, 'AS ready') => [['ready' => $ready]],
                    str_contains($sql, 'business_definitions') => [
                        ['id' => $id, 'handle' => 'invoice'],
                        ['id' => null, 'handle' => 'malformed'],
                    ],
                    str_contains($sql, $digests) => [
                        ['statement' => 'SELECT secret_column FROM ' . $table . ' WHERE x = ?', 'calls' => 5,
                            'mean_ms' => 1.5, 'total_ms' => 7.5],
                        ['statement' => 'SELECT 1 FROM unrelated_table', 'calls' => 9, 'mean_ms' => 1, 'total_ms' => 9],
                        ['statement' => null, 'calls' => 1, 'mean_ms' => 1, 'total_ms' => 1],
                    ],
                    default => [],
                });
                $result = $this->reader($database->connection())->read($this->operator(), 'slow');
                self::assertSame($status, $result['status'], $engine);
                self::assertSame($limit, $result['statement_limit']);
                if ($ready === '0') {
                    self::assertSame('statement_statistics_off', $result['status_reason']);
                    self::assertSame([], $result['rows']);
                    self::assertCount(1, $this->selects($database), $engine);
                    continue;
                }
                self::assertSame([[
                    'definition' => 'invoice',
                    'policy_cost' => 'included_in_statement',
                    'calls' => 5,
                    'mean_ms' => 1.5,
                    'total_ms' => 7.5,
                ]], $result['rows'], $engine);
                self::assertCount($limit, $this->selects($database), $engine);
                self::assertStringNotContainsString('secret_column', json_encode($result, JSON_THROW_ON_ERROR));
            }
        }
    }

    /**
     * A MySQL-family performance schema that is on but not collecting digests is off for this question, and a
     * consumer table this role cannot read is an unreadable source rather than an idle installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSlowDistinguishesAnIdleDigestConsumerFromAnUnreadableOne(): void
    {
        $cases = [
            ['0', 'statement_statistics_off'],
            [ScriptedDiagnosticDatabase::failure('42000', 1142), 'source_unreadable'],
        ];
        foreach ($cases as [$consumer, $reason]) {
            $database = $this->scripted(new MariaDBPlatform(), static fn (string $sql): mixed => match (true) {
                str_contains($sql, '@@performance_schema AS ready') => [['ready' => 1]],
                str_contains($sql, 'setup_consumers') => $consumer === '0' ? [['ready' => 0]] : $consumer,
                default => [['id' => 'never', 'handle' => 'read']],
            });
            $result = $this->reader($database->connection())->read($this->operator(), 'slow');
            self::assertSame('unavailable', $result['status']);
            self::assertSame($reason, $result['status_reason']);
            self::assertCount(2, $this->selects($database));
        }
    }

    /**
     * Twenty slow definitions is the published bound even when more installed tables appear in the digests.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSlowStopsAtTheRowBound(): void
    {
        $names = new PhysicalNameCompiler('kumwe_');
        $definitions = [];
        $digests = [];
        for ($index = 0; $index < 30; $index++) {
            $id = sprintf('018f22e2-7c8b-7ab0-8f3a-%012d', $index);
            $definitions[] = ['id' => $id, 'handle' => 'definition_' . $index];
            $digests[] = ['statement' => 'SELECT * FROM ' . $names->entityTable($id, 'definition_' . $index),
                'calls' => 1, 'mean_ms' => 1, 'total_ms' => 1];
        }
        $database = $this->scripted(new MariaDBPlatform(), static fn (string $sql): array => match (true) {
            str_contains($sql, 'AS ready') => [['ready' => 1]],
            str_contains($sql, 'business_definitions') => $definitions,
            default => $digests,
        });
        $result = $this->reader($database->connection())->read($this->operator(), 'slow');
        self::assertIsArray($result['rows']);
        self::assertCount(20, $result['rows']);
    }

    /**
     * Engine cancellation on every engine and an oversized result are reported as the bound they hit, a refused
     * privilege as an unreadable source, and neither discloses the driver message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEngineRefusalsAreReportedAsTheBoundOrSourceTheyHit(): void
    {
        $cases = [
            [new MariaDBPlatform(), ScriptedDiagnosticDatabase::failure('70100', 1969), 'budget_exceeded', 'time'],
            [new MySQLPlatform(), ScriptedDiagnosticDatabase::failure('HY000', 3024), 'budget_exceeded', 'time'],
            [new PostgreSQLPlatform(), ScriptedDiagnosticDatabase::failure('57014'), 'budget_exceeded', 'time'],
            [new MariaDBPlatform(), [['stream' => str_repeat('x', 262145), 'depth' => 1, 'oldest' => null]],
                'budget_exceeded', 'bytes'],
            [new MariaDBPlatform(), ScriptedDiagnosticDatabase::failure('42000', 1227), 'unavailable',
                'source_unreadable'],
            [new PostgreSQLPlatform(), ScriptedDiagnosticDatabase::failure('42501'), 'unavailable',
                'source_unreadable'],
        ];
        foreach ($cases as $index => [$platform, $answer, $status, $reason]) {
            $database = $this->scripted($platform, static fn (string $sql): mixed => str_starts_with($sql, 'SET LOCAL')
                ? [] : $answer);
            foreach (['queues', 'contention'] as $section) {
                $result = $this->reader($database->connection())->read($this->operator(), $section);
                self::assertSame($status, $result['status'], (string) $index);
                self::assertSame($reason, $result['status_reason'], (string) $index);
                self::assertSame([], $result['rows']);
                self::assertStringNotContainsString('Scripted', json_encode($result, JSON_THROW_ON_ERROR));
            }
        }
    }

    /**
     * An engine with no statistics branch runs nothing for the engine questions, and an unreachable server is an
     * unreadable source rather than an exception or an empty healthy answer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedEnginesAndUnreachableServersAreExplicitlyUnavailable(): void
    {
        $database = $this->scripted(new SQLitePlatform(), static fn (): array => [['ready' => 1]]);
        foreach (['contention', 'slow'] as $section) {
            $result = $this->reader($database->connection())->read($this->operator(), $section);
            self::assertSame('unsupported', $result['engine']);
            self::assertSame('unavailable', $result['status']);
            self::assertSame('engine_unsupported', $result['status_reason']);
        }
        self::assertSame([], $database->statements);

        $unreachable = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'nobody',
            'dbname' => 'none',
        ]);
        foreach (['contention', 'queues'] as $section) {
            $result = $this->reader($unreachable)->read($this->operator(), $section);
            self::assertSame('unknown', $result['engine']);
            self::assertSame('unavailable', $result['status']);
            self::assertSame('source_unreadable', $result['status_reason']);
        }
    }

    /**
     * Every section declares the most statements it may run and the elapsed ceiling that follows from them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySectionDeclaresItsStatementCeiling(): void
    {
        $ledgers = count(RetentionCatalogue::declared()->policies());
        $expected = [
            'queues' => [3, 'bounded_probes'],
            'backlog' => [1 + 5 * $ledgers, 'bounded_probes'],
            'retention' => [1 + 5 * $ledgers, 'bounded_probes'],
        ];
        $database = $this->scripted(new PostgreSQLPlatform(), static fn (): array => []);
        foreach ($expected as $section => [$statements, $cost]) {
            $result = $this->reader($database->connection())->read($this->operator(), $section);
            self::assertSame('available', $result['status']);
            self::assertSame($statements, $result['statement_limit']);
            self::assertSame($statements * 1000, $result['elapsed_ceiling_ms']);
            self::assertSame($cost, $result['cost_class']);
            self::assertSame(262144, $result['statement_byte_limit']);
        }
        self::assertCount(3, $this->selects($database));
    }

    /**
     * Build a scripted connection for one engine.
     *
     * @param   AbstractPlatform  $platform   Engine the connection reports.
     * @param   \Closure         $responder  Answer per statement.
     *
     * @return  ScriptedDiagnosticDatabase  Database recording every statement it receives.
     *
     * @since   2.0.0
     */
    private function scripted(AbstractPlatform $platform, \Closure $responder): ScriptedDiagnosticDatabase
    {
        return new ScriptedDiagnosticDatabase($platform, $responder);
    }

    /**
     * Read the SELECT statements a scripted database received, ignoring session timeout set-up.
     *
     * @param   ScriptedDiagnosticDatabase  $database  Recording database.
     *
     * @return  list<string>  SQL of each read, in order.
     *
     * @since   2.0.0
     */
    private function selects(ScriptedDiagnosticDatabase $database): array
    {
        return array_values(array_filter(
            array_column($database->statements, 'sql'),
            static fn (string $sql): bool => !str_starts_with($sql, 'SET LOCAL')
                && !str_starts_with($sql, 'SAVEPOINT') && !str_starts_with($sql, 'ROLLBACK'),
        ));
    }

    /**
     * Context holding the diagnostics capability.
     *
     * @return  \Kumwe\Context\Value\ExecutionContext  Operator context.
     *
     * @since   2.0.0
     */
    private function operator(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]);
    }

    /**
     * Create a transient SQL store for the actual bounded queue queries.
     *
     * @return  Connection  Empty private SQLite database.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * Build the reader with a fixed clock and replaceable authority and retention source.
     *
     * @param   Connection             $database       Database to probe.
     * @param   ?AuthorizationGateway  $authorization  Authority override for refusal checks.
     * @param   ?RetentionObserver     $retention      Existing retention observation source.
     *
     * @return  DoctrineOperatorDiagnostics  Reader under test.
     *
     * @since   2.0.0
     */
    private function reader(
        Connection $database,
        ?AuthorizationGateway $authorization = null,
        ?RetentionObserver $retention = null,
    ): DoctrineOperatorDiagnostics {
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-29T10:01:00+00:00'));

        return new DoctrineOperatorDiagnostics(
            $database,
            new TableNames($database, 'kumwe_'),
            $authorization ?? self::createStub(AuthorizationGateway::class),
            $retention ?? self::createStub(RetentionObserver::class),
            RetentionCatalogue::declared(),
            new PhysicalNameCompiler('kumwe_'),
            $clock,
        );
    }
}
