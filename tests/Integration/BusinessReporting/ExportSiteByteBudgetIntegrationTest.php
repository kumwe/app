<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessReporting;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Application\ExportSiteByteBudgetExhausted;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineExportSiteByteBudget;
use Kumwe\App\Infrastructure\Persistence\Migration\ExportSiteByteBudgetMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the per-site cumulative export byte budget on the configured engine: charges accumulate within a
 * UTC day, the passing charge is refused, a new day restarts the window, a rolled-back completion is never
 * charged, one site's exhaustion leaves another site untouched, and racing completions in separate
 * processes never push a site past its budget.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineExportSiteByteBudget::class)]
#[CoversClass(ExportSiteByteBudgetMigration::class)]
final class ExportSiteByteBudgetIntegrationTest extends TestCase
{
    /**
     * Container under test.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private ?Container $container = null;

    /**
     * Sites whose budget rows the test created.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $sites = [];

    /**
     * Charges accumulate within a day, the passing one is refused, and another site is unaffected.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChargesAccumulateWithinADayAndThePassingChargeIsRefused(): void
    {
        $budget = new DoctrineExportSiteByteBudget($this->database(), $this->tables(), 1_000);
        $site = $this->site();
        $other = $this->site();
        $at = new DateTimeImmutable('2026-09-24T10:00:00Z');
        $budget->charge($site, 0, $at);
        self::assertNull($this->used($site), 'An empty artifact charges nothing.');
        $budget->charge($site, 400, $at);
        $budget->charge($site, 500, $at->modify('+3 hours'));
        self::assertSame(900, $this->used($site));
        try {
            $budget->charge($site, 200, $at->modify('+4 hours'));
            self::fail('A charge past the budget must be refused.');
        } catch (ExportSiteByteBudgetExhausted $refused) {
            self::assertStringContainsString($site, $refused->getMessage());
        }
        self::assertSame(900, $this->used($site), 'A refused charge leaves the total unchanged.');
        $budget->charge($site, 100, $at->modify('+5 hours'));
        self::assertSame(1_000, $this->used($site), 'The budget can be used exactly.');
        $budget->charge($other, 1_000, $at);
        self::assertSame(1_000, $this->used($other), 'One site exhausting its budget leaves others untouched.');
        $this->expectException(ExportSiteByteBudgetExhausted::class);
        $budget->charge($this->site(), 1_001, $at);
    }

    /**
     * The first charge of a new UTC day restarts the site's window.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANewUtcDayRestartsTheWindow(): void
    {
        $budget = new DoctrineExportSiteByteBudget($this->database(), $this->tables(), 1_000);
        $site = $this->site();
        $budget->charge($site, 900, new DateTimeImmutable('2026-09-24T23:59:59+00:00'));
        $budget->charge($site, 800, new DateTimeImmutable('2026-09-25T02:00:00+02:00'));
        self::assertSame(800, $this->used($site), 'Midnight UTC, not local midnight, restarts the window.');
        $budget->charge($site, 150, new DateTimeImmutable('2026-09-25T00:00:01Z'));
        self::assertSame(950, $this->used($site));
    }

    /**
     * A charge inside a completion that rolls back is not kept.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARolledBackCompletionIsNotCharged(): void
    {
        $database = $this->database();
        $budget = new DoctrineExportSiteByteBudget($database, $this->tables(), 1_000);
        $site = $this->site();
        $at = new DateTimeImmutable('2026-09-24T10:00:00Z');
        $budget->charge($site, 100, $at);
        try {
            $database->transactional(static function () use ($budget, $site, $at): void {
                $budget->charge($site, 800, $at);
                throw new RuntimeException('The completion failed after charging.');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(100, $this->used($site));
        $budget->charge($site, 900, $at);
        self::assertSame(1_000, $this->used($site));
    }

    /**
     * A negative charge and a budget below one byte are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidChargesAndBudgetsAreRefused(): void
    {
        $budget = new DoctrineExportSiteByteBudget($this->database(), $this->tables(), 10);
        try {
            $budget->charge($this->site(), -1, new DateTimeImmutable());
            self::fail('A negative charge must be refused.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new DoctrineExportSiteByteBudget($this->database(), $this->tables(), 0);
    }

    /**
     * Six completions racing in separate processes for one fresh site admit exactly as many as fit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRacingCompletionsInSeparateProcessesNeverPassTheBudget(): void
    {
        $site = $this->site();
        $directory = sys_get_temp_dir() . '/kumwe-export-budget-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o700));
        $prefix = $this->tables()->raw('x');
        $prefix = substr($prefix, 0, -1);
        $processes = [];
        try {
            for ($index = 0; $index < 6; $index++) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/export-budget-claimant.php', $directory, $prefix,
                        $site, '1000', '300', 'claimant-' . $index],
                    [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', $directory . '/claimant-' . $index . '.stdout', 'w'],
                        2 => ['file', $directory . '/claimant-' . $index . '.stderr', 'w'],
                    ],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = $process;
            }
            file_put_contents($directory . '/start.tmp', 'go');
            rename($directory . '/start.tmp', $directory . '/start');
            foreach ($processes as $process) {
                proc_close($process);
            }
            $outcomes = [];
            for ($index = 0; $index < 6; $index++) {
                $outcome = @file_get_contents($directory . '/claimant-' . $index);
                self::assertIsString($outcome, (string) @file_get_contents(
                    $directory . '/claimant-' . $index . '.stderr',
                ));
                $outcomes[] = $outcome;
            }
            sort($outcomes);
            self::assertSame(['charged', 'charged', 'charged', 'exhausted', 'exhausted', 'exhausted'], $outcomes);
            self::assertSame(900, $this->used($site));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * Remove the budget rows the test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->container !== null) {
            foreach ($this->sites as $site) {
                $this->database()->delete($this->tables()->raw('business_report_export_site_budgets'), [
                    'site_identifier' => $site,
                ]);
            }
        }
        $this->sites = [];
        $this->container = null;
    }

    /**
     * Mint a site identifier no other run uses.
     *
     * @return  string  Site identifier.
     *
     * @since   2.0.0
     */
    private function site(): string
    {
        $site = 'export-budget-' . bin2hex(random_bytes(6));
        $this->sites[] = $site;

        return $site;
    }

    /**
     * Read a site's charged bytes in the current window.
     *
     * @param   string  $site  Site identifier.
     *
     * @return  ?int  Charged bytes, or null when the site has no row.
     *
     * @since   2.0.0
     */
    private function used(string $site): ?int
    {
        $value = $this->database()->fetchOne(sprintf(
            'SELECT window_bytes FROM %s WHERE site_identifier = ?',
            $this->tables()->quoted('business_report_export_site_budgets'),
        ), [$site]);

        return is_int($value) || is_string($value) ? (int) $value : null;
    }

    /**
     * The migrated application container, created once per test.
     *
     * @return  Container  Container.
     *
     * @since   2.0.0
     */
    private function container(): Container
    {
        return $this->container ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * The container's connection.
     *
     * @return  Connection  Connection.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = $this->container()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    /**
     * The installation's table names.
     *
     * @return  TableNames  Table names.
     *
     * @since   2.0.0
     */
    private function tables(): TableNames
    {
        $tables = $this->container()->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);

        return $tables;
    }
}
