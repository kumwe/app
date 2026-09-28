<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Kernel\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves the generation-aware fence protocol of V2-SCL-001 with a genuinely blocked second process.
 *
 * The existing fence tests show a lifecycle writer *failing* against an in-flight record transaction
 * under a one-second lock timeout. That is half of the acceptance clause. The other half is that the
 * transition waits and then proceeds once the last writer leaves, that a new writer arriving while the
 * transition holds the row is held back rather than admitted to the old generation, and that a writer
 * which rolls back or is killed hands its authority back without any reaper or timeout. Each of those
 * needs a session that is actually blocked while the test keeps running, which only a second operating
 * system process provides; `tests/Support/fence-transition-partner.php` plays it.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordMutationFence::class)]
final class SchemaTransitionWriterFenceIntegrationTest extends TestCase
{
    /**
     * Longest a partner process may take to notice a released lock before the drill calls it stuck.
     *
     * @var    float
     * @since  2.0.0
     */
    private const float RELEASE_DEADLINE_SECONDS = 15.0;

    /**
     * A transition issued against an in-flight writer waits, proceeds on commit, and fences later writers.
     *
     * The partner's transition request is observably outstanding for at least half a second while the
     * writer holds the shared fence, is granted only after that writer commits, and while it is held a
     * fresh writer with a one-second lock budget is refused as temporarily unavailable rather than being
     * admitted to the generation the transition is retiring. Once the transition commits, the old
     * generation is no longer active and a new writer is refused by name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATransitionWaitsForInFlightWritersAndBlocksNewWritersOnTheOldGeneration(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString()),
        );
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $fence = $this->fence($container);
        $directory = $this->handshakeDirectory();
        $partner = $this->spawnPartner($directory, 'transition', $definition->id, $definition->handle);

        try {
            $database->beginTransaction();
            $generation = $fence->lock($context, $definition->handle);
            self::assertSame($definition->definitionVersion, $generation->definitionVersion);
            $this->mark($directory, 'writer-holds');
            self::assertTrue(
                $this->await($directory . '/transition-requested', 30.0),
                'The partner never issued its transition; the wait could not be observed.',
            );
            usleep(600_000);
            self::assertFileDoesNotExist(
                $directory . '/transition-acquired',
                'The transition was granted while a writer still held the shared fence.',
            );

            $database->commit();
            self::assertTrue(
                $this->await($directory . '/transition-acquired', self::RELEASE_DEADLINE_SECONDS),
                'The transition was not granted after the last writer committed.',
            );
            $waitedMs = (float) file_get_contents($directory . '/transition-acquired');
            self::assertGreaterThan(500.0, $waitedMs, 'The transition must have waited on the writer.');

            $this->shortenLockWait($database);
            $database->beginTransaction();
            try {
                $fence->lock($context, $definition->handle);
                self::fail('A new writer must not enter the generation a transition is retiring.');
            } catch (BusinessRecordTemporarilyUnavailable) {
                self::assertTrue($database->isTransactionActive());
            } finally {
                $database->rollBack();
            }

            $this->mark($directory, 'release-transition');
            self::assertTrue(
                $this->await($directory . '/transition-committed', self::RELEASE_DEADLINE_SECONDS),
                'The partner never committed its transition.',
            );
            $database->beginTransaction();
            try {
                $fence->lock($context, $definition->handle);
                self::fail('The retired generation must refuse new writers by name.');
            } catch (BusinessRecordSchemaUnavailable) {
                self::assertTrue(true);
            } finally {
                $database->rollBack();
            }
            self::assertSame('completed', $this->awaitOutcome($directory));
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $this->reap($partner);
            $database->update(
                $tables->raw('business_schema_installations'),
                ['status' => SchemaInstallationStatus::Active->value],
                ['definition_id' => $definition->id],
            );
            $this->cleanUp($directory);
        }
    }

    /**
     * A writer that rolls back, and a writer that is killed, both hand the fence back without a reaper.
     *
     * While either partner holds the shared fence a transition with a one-second lock budget is refused,
     * proving the authority is real. After the rollback, and after `SIGKILL`, the same transition is
     * granted promptly: the server releases the session's locks itself, so no lease timeout, heartbeat
     * or operator step is what frees the generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRollbackAndCrashBothReleaseWriterAuthorityWithoutATimeout(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString()),
        );
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $this->shortenLockWait($database);
        $transition = static fn (): int => (int) $database->executeStatement(sprintf(
            "UPDATE %s SET status = 'installing' WHERE definition_id = ?",
            $tables->quoted('business_schema_installations'),
        ), [$definition->id]);

        foreach (['writer-rollback', 'writer-crash'] as $mode) {
            $directory = $this->handshakeDirectory();
            $partner = $this->spawnPartner($directory, $mode, $definition->id, $definition->handle);
            try {
                self::assertTrue(
                    $this->await($directory . '/writer-holds', 30.0),
                    sprintf('The %s partner never took the shared fence.', $mode),
                );
                $database->beginTransaction();
                try {
                    $transition();
                    self::fail(sprintf('A transition must wait while the %s partner holds the fence.', $mode));
                } catch (DbalException) {
                    self::assertTrue(true);
                } finally {
                    $database->rollBack();
                }

                if ($mode === 'writer-rollback') {
                    $this->mark($directory, 'rollback');
                    self::assertTrue(
                        $this->await($directory . '/writer-released', self::RELEASE_DEADLINE_SECONDS),
                        'The partner never rolled back.',
                    );
                } else {
                    proc_terminate($partner, 9);
                    $status = proc_close($partner);
                    self::assertNotSame(0, $status, 'A killed writer must not report a clean exit.');
                    $partner = null;
                }

                $granted = false;
                $started = hrtime(true);
                $deadline = $started + (int) (self::RELEASE_DEADLINE_SECONDS * 1_000_000_000);
                while (!$granted && hrtime(true) < $deadline) {
                    $database->beginTransaction();
                    try {
                        $transition();
                        $granted = true;
                    } catch (DbalException) {
                        // The server has not yet ended the dead session; try again within the deadline.
                    } finally {
                        $database->rollBack();
                    }
                }
                self::assertTrue($granted, sprintf(
                    'The fence was not released within %.0f seconds after the %s partner left.',
                    self::RELEASE_DEADLINE_SECONDS,
                    $mode,
                ));
                if ($mode === 'writer-rollback') {
                    self::assertSame('completed', $this->awaitOutcome($directory));
                }
            } finally {
                if ($database->isTransactionActive()) {
                    $database->rollBack();
                }
                $this->reap($partner);
                $this->cleanUp($directory);
            }
        }
        self::assertSame(
            SchemaInstallationStatus::Active->value,
            $database->fetchOne(sprintf(
                'SELECT status FROM %s WHERE definition_id = ?',
                $tables->quoted('business_schema_installations'),
            ), [$definition->id]),
            'Every transition in this drill was rolled back, so the installation must still be active.',
        );
    }

    /**
     * Start the partner process for one drill mode.
     *
     * @param   string  $directory     Handshake directory shared with the partner.
     * @param   string  $mode          Partner mode: `transition`, `writer-rollback` or `writer-crash`.
     * @param   string  $definitionId  Definition whose installation row is fenced.
     * @param   string  $handle        Definition handle the writer modes lock.
     *
     * @return  resource  The running process.
     *
     * @since   2.0.0
     */
    private function spawnPartner(string $directory, string $mode, string $definitionId, string $handle)
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/fence-transition-partner.php', $mode, $directory,
                $definitionId, $handle],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/partner.stdout', 'w'],
                2 => ['file', $directory . '/partner.stderr', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The fence partner process could not be started.');
        }

        return $process;
    }

    /**
     * Wait for the partner to finish and read the outcome it wrote.
     *
     * @param   string  $directory  Handshake directory.
     *
     * @return  string  The partner's recorded outcome, or a description of why none was recorded.
     *
     * @since   2.0.0
     */
    private function awaitOutcome(string $directory): string
    {
        if (!$this->await($directory . '/partner-outcome', self::RELEASE_DEADLINE_SECONDS)) {
            return 'partner-outcome-missing: ' . (string) file_get_contents($directory . '/partner.stderr');
        }

        return (string) file_get_contents($directory . '/partner-outcome');
    }

    /**
     * End a partner process that is still running and release its handle.
     *
     * @param   resource|null  $partner  Partner process, or null when it was already closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reap(mixed $partner): void
    {
        if (!is_resource($partner)) {
            return;
        }
        $deadline = microtime(true) + 5.0;
        while (proc_get_status($partner)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($partner)['running']) {
            proc_terminate($partner, 9);
        }
        proc_close($partner);
    }

    /**
     * Write a handshake marker atomically.
     *
     * @param   string  $directory  Handshake directory.
     * @param   string  $name       Marker file name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function mark(string $directory, string $name): void
    {
        file_put_contents($directory . '/' . $name . '.tmp', 'done');
        rename($directory . '/' . $name . '.tmp', $directory . '/' . $name);
    }

    /**
     * Poll for a handshake marker.
     *
     * @param   string  $path     Marker file path.
     * @param   float   $seconds  Longest to wait.
     *
     * @return  bool  Whether the marker appeared in time.
     *
     * @since   2.0.0
     */
    private function await(string $path, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return true;
            }
            usleep(5_000);
        }

        return false;
    }

    /**
     * Give the test session a one-second lock budget so a held row is reported instead of waited on.
     *
     * @param   Connection  $database  Session to bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function shortenLockWait(Connection $database): void
    {
        $database->executeStatement($database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'SET SESSION innodb_lock_wait_timeout = 1'
            : "SET lock_timeout = '1s'");
    }

    /**
     * Create a private handshake directory for one drill.
     *
     * @return  string  Absolute directory path.
     *
     * @since   2.0.0
     */
    private function handshakeDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-fence-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('The handshake directory could not be created.');
        }

        return $directory;
    }

    /**
     * Remove a handshake directory and everything the drill wrote into it.
     *
     * @param   string  $directory  Handshake directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanUp(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    /**
     * Derive a unique fixture suffix.
     *
     * @return  string  Twelve lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    /**
     * Resolve the kernel's database connection.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  Connection  Shared connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    /**
     * Resolve the kernel's table-name compiler.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  TableNames  Installation table names.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);

        return $tables;
    }

    /**
     * Resolve the production mutation fence.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  BusinessRecordMutationFence  Fence bound to the kernel connection.
     *
     * @since   2.0.0
     */
    private function fence(Container $container): BusinessRecordMutationFence
    {
        $fence = $container->get(BusinessRecordMutationFence::class);
        self::assertInstanceOf(BusinessRecordMutationFence::class, $fence);

        return $fence;
    }
}
