<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins operator authority, safe unavailable answers and measured queue/retention results.
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
