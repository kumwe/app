<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\EventSchemaDefinition;
use Kumwe\Integration\EventSensitivity;
use Kumwe\Integration\RecordedIntegrationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Crashes and races real sequencer processes to prove the committed-source journal never loses or repeats.
 *
 * V2-SCL-002 asks for three things a single-process test cannot show: that a sequencer which dies after
 * allocating a range but before committing leaves nothing behind and nothing missing, that a sequencer
 * which dies holding the head lock only delays its siblings, and that several sequencers publishing at
 * once neither skip nor duplicate a source row. `tests/Support/sequencer-partner.php` supplies the
 * processes; every statement they run is the production sequencer's own.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineProjectionEventSequencer::class)]
#[CoversClass(DoctrineOutboxStore::class)]
final class SequencerCrashAndRaceIntegrationTest extends TestCase
{
    /**
     * A sequencer killed after inserting its journal range leaves the head, staging and journal untouched.
     *
     * The partner runs the whole publication transaction — head lock, journal inserts, head advance and
     * staging delete — and is killed before commit. Afterwards none of its rows are visible, the head has
     * not moved, the staged sources are all still there, and an ordinary sequencer then publishes them
     * exactly once in a contiguous range starting at the old head.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACrashAfterRangeAllocationPublishesNothingAndLosesNothing(): void
    {
        [$database, $tables] = $this->session();
        $events = [$this->event(), $this->event(), $this->event()];
        $ids = array_map(static fn (RecordedIntegrationEvent $event): string => $event->eventId(), $events);
        $sequencer = $this->sequencer($database, $tables);
        $directory = $this->handshakeDirectory();
        $partner = null;
        try {
            $this->drain($sequencer);
            $outbox = $this->outbox($database, $tables);
            foreach ($events as $event) {
                $outbox->append($event);
            }
            $head = $this->head($database, $tables);
            $partner = $this->spawn($directory, 'crash-after-allocation', 'crash', 1_000);
            self::assertTrue($this->await($directory . '/allocated', 30.0), 'The partner never allocated.');
            self::assertSame('3', file_get_contents($directory . '/allocated'));
            proc_terminate($partner, 9);
            proc_close($partner);
            $partner = null;

            $this->awaitLockRelease($database, $tables);
            self::assertSame($head, $this->head($database, $tables), 'A crashed allocation must not move the head.');
            self::assertSame(0, $this->journalCount($database, $tables, $ids), 'No uncommitted range is visible.');
            self::assertSame(3, $this->stagingCount($database, $tables, $ids), 'Every staged source survives.');

            self::assertSame(3, $sequencer->sequence(1_000));
            self::assertSame($head + 3, $this->head($database, $tables));
            $sequences = $this->sequences($database, $tables, $ids);
            self::assertSame(range($head + 1, $head + 3), $sequences, 'Recovery publishes one contiguous range.');
            self::assertSame(0, $this->stagingCount($database, $tables, $ids));
        } finally {
            $this->reap($partner);
            $this->cleanup($database, $tables, $ids);
            $this->removeDirectory($directory);
        }
    }

    /**
     * A sequencer killed while holding the head lock only delays its siblings until the server frees it.
     *
     * While the partner holds the head, a live sequencer publishes nothing and reports so; after the
     * partner is killed the same sequencer publishes every staged source with no gap and no timeout,
     * heartbeat or operator step in between.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACrashBeforeRangeAllocationOnlyDelaysASiblingSequencer(): void
    {
        [$database, $tables] = $this->session();
        $events = [$this->event(), $this->event()];
        $ids = array_map(static fn (RecordedIntegrationEvent $event): string => $event->eventId(), $events);
        $sequencer = $this->sequencer($database, $tables);
        $directory = $this->handshakeDirectory();
        $partner = null;
        try {
            $this->drain($sequencer);
            $outbox = $this->outbox($database, $tables);
            foreach ($events as $event) {
                $outbox->append($event);
            }
            $head = $this->head($database, $tables);
            $partner = $this->spawn($directory, 'hold-head', 'hold');
            self::assertTrue($this->await($directory . '/head-held-hold', 30.0), 'The partner never held the head.');
            self::assertSame(0, $sequencer->sequence(1_000), 'A held head is skipped, never waited on.');
            self::assertSame(2, $this->stagingCount($database, $tables, $ids));
            proc_terminate($partner, 9);
            proc_close($partner);
            $partner = null;

            $this->awaitLockRelease($database, $tables);
            self::assertSame(2, $sequencer->sequence(1_000));
            self::assertSame(range($head + 1, $head + 2), $this->sequences($database, $tables, $ids));
        } finally {
            $this->reap($partner);
            $this->cleanup($database, $tables, $ids);
            $this->removeDirectory($directory);
        }
    }

    /**
     * Four sequencer processes draining one backlog publish every source exactly once, contiguously.
     *
     * Sixty committed sources are staged, four partners race in small batches, and the drill then checks
     * that the journal holds each event once, that the sixty sequences form one unbroken range from the
     * old head, that staging is empty, and that the partners' own counts add up to sixty.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConcurrentSequencersNeitherDuplicateNorSkipCommittedSources(): void
    {
        [$database, $tables] = $this->session();
        $events = [];
        for ($index = 0; $index < 60; $index++) {
            $events[] = $this->event();
        }
        $ids = array_map(static fn (RecordedIntegrationEvent $event): string => $event->eventId(), $events);
        $sequencer = $this->sequencer($database, $tables);
        $directory = $this->handshakeDirectory();
        $partners = [];
        try {
            $this->drain($sequencer);
            $outbox = $this->outbox($database, $tables);
            foreach ($events as $event) {
                $outbox->append($event);
            }
            $head = $this->head($database, $tables);
            for ($worker = 0; $worker < 4; $worker++) {
                $partners[$worker] = $this->spawn($directory, 'race', (string) $worker, 7);
            }
            usleep(200_000);
            $this->mark($directory, 'start');
            $published = 0;
            foreach ($partners as $worker => $partner) {
                self::assertTrue(
                    $this->await($directory . '/outcome-' . $worker, 90.0),
                    sprintf('Sequencer %d never finished.', $worker),
                );
                self::assertSame('completed', file_get_contents($directory . '/outcome-' . $worker));
                $result = json_decode((string) file_get_contents($directory . '/race-' . $worker . '.json'), true);
                self::assertIsArray($result);
                $published += (int) $result['published'];
                proc_close($partner);
                unset($partners[$worker]);
            }

            self::assertSame(60, $published, 'The partners together must have published exactly the backlog.');
            self::assertSame(range($head + 1, $head + 60), $this->sequences($database, $tables, $ids));
            self::assertSame(60, $this->journalCount($database, $tables, $ids));
            self::assertSame(0, $this->stagingCount($database, $tables, $ids));
            self::assertSame($head + 60, $this->head($database, $tables));
        } finally {
            foreach ($partners as $partner) {
                $this->reap($partner);
            }
            $this->cleanup($database, $tables, $ids);
            $this->removeDirectory($directory);
        }
    }

    /**
     * Open an independent session on the configured engine.
     *
     * @return  array{Connection, TableNames}  Session and installation names.
     *
     * @since   2.0.0
     */
    private function session(): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $database = (new DoctrineConnectionFactory($configuration->database))->create();

        return [$database, new TableNames($database, $configuration->database->tablePrefix)];
    }

    /**
     * Build the production sequencer on a session.
     *
     * @param   Connection  $database  Session outside any authoritative transaction.
     * @param   TableNames  $tables    Installation names.
     *
     * @return  DoctrineProjectionEventSequencer  Committed-source sequencer.
     *
     * @since   2.0.0
     */
    private function sequencer(Connection $database, TableNames $tables): DoctrineProjectionEventSequencer
    {
        return new DoctrineProjectionEventSequencer($database, $tables, new DoctrineTransactionManager($database));
    }

    /**
     * Bind the production outbox to an independent test contract so it stages committed sources.
     *
     * @param   Connection  $database  Writer session.
     * @param   TableNames  $tables    Installation names.
     *
     * @return  DoctrineOutboxStore  Production outbox adapter.
     *
     * @since   2.0.0
     */
    private function outbox(Connection $database, TableNames $tables): DoctrineOutboxStore
    {
        $encoder = new DeterministicCanonicalEncoder();
        $contracts = new EventContractRegistry($encoder, [new EventSchemaDefinition(
            $encoder,
            'acme.sequencer.changed',
            1,
            EventSensitivity::INTERNAL,
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        )], []);

        return new DoctrineOutboxStore(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $this->clock(),
            $contracts,
            $encoder,
            $this->sequencer($database, $tables),
        );
    }

    /**
     * Create one unique committed fact for the drill.
     *
     * @return  RecordedIntegrationEvent  Validated fixture event.
     *
     * @since   2.0.0
     */
    private function event(): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            new DeterministicCanonicalEncoder(),
            'acme.sequencer.changed',
            1,
            Uuid::uuid7()->toString(),
            $this->clock()->now(),
            'sequencer-drill-actor',
            null,
            'sequencer-drill-site',
            null,
            'acme.sequencer',
            Uuid::uuid7()->toString(),
            1,
            'sequencer-correlation',
            'sequencer-request',
            EventSensitivity::INTERNAL,
            ['value' => 'fixture'],
        );
    }

    /**
     * Date fixture rows outside every other fixture's retention window.
     *
     * @return  ClockInterface  Fixed clock.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Return the fixture's fixed instant.
             *
             * @return  DateTimeImmutable  UTC test instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('1970-01-02T00:00:00+00:00');
            }
        };
    }

    /**
     * Publish everything other fixtures left staged, so the drill's own range starts at a settled head.
     *
     * @param   DoctrineProjectionEventSequencer  $sequencer  Sequencer on a free session.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function drain(DoctrineProjectionEventSequencer $sequencer): void
    {
        for ($batch = 0; $batch < 100; ++$batch) {
            if ($sequencer->sequence(1_000) < 1_000) {
                return;
            }
        }
        self::fail('The bounded fixture drain did not settle.');
    }

    /**
     * Wait until the head row can be locked again after a partner died holding it.
     *
     * @param   Connection  $database  Free session.
     * @param   TableNames  $tables    Installation names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function awaitLockRelease(Connection $database, TableNames $tables): void
    {
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            $database->beginTransaction();
            try {
                $held = $database->fetchOne(sprintf(
                    'SELECT last_sequence FROM %s WHERE singleton_id = 1 FOR UPDATE SKIP LOCKED',
                    $tables->quoted('business_projection_event_head'),
                ));
            } finally {
                $database->rollBack();
            }
            if ($held !== false) {
                return;
            }
            usleep(50_000);
        }
        self::fail('The server did not release the dead sequencer\'s head lock within fifteen seconds.');
    }

    /**
     * Read the journal head.
     *
     * @param   Connection  $database  Free session.
     * @param   TableNames  $tables    Installation names.
     *
     * @return  int  Last published sequence.
     *
     * @since   2.0.0
     */
    private function head(Connection $database, TableNames $tables): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT last_sequence FROM %s WHERE singleton_id = 1',
            $tables->quoted('business_projection_event_head'),
        ));
    }

    /**
     * Count the drill's events present in the journal.
     *
     * @param   Connection    $database  Free session.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Drill event identities.
     *
     * @return  int  Journal rows carrying one of the identities.
     *
     * @since   2.0.0
     */
    private function journalCount(Connection $database, TableNames $tables, array $ids): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE event_id IN (?)',
            $tables->quoted('business_projection_source_events'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * Count the drill's events still staged.
     *
     * @param   Connection    $database  Free session.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Drill event identities.
     *
     * @return  int  Staging rows carrying one of the identities.
     *
     * @since   2.0.0
     */
    private function stagingCount(Connection $database, TableNames $tables, array $ids): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE event_id IN (?)',
            $tables->quoted('business_projection_event_staging'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * Read the journal sequences assigned to the drill's events, ascending.
     *
     * @param   Connection    $database  Free session.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Drill event identities.
     *
     * @return  list<int>  Assigned sequences in ascending order.
     *
     * @since   2.0.0
     */
    private function sequences(Connection $database, TableNames $tables, array $ids): array
    {
        return array_map('intval', $database->fetchFirstColumn(sprintf(
            'SELECT source_sequence FROM %s WHERE event_id IN (?) ORDER BY source_sequence',
            $tables->quoted('business_projection_source_events'),
        ), [$ids], [ArrayParameterType::STRING]));
    }

    /**
     * Start a partner process.
     *
     * @param   string  $directory  Handshake directory.
     * @param   string  $mode       Partner mode.
     * @param   string  $label      Label the partner writes its markers under.
     * @param   int     $batch      Batch size for the sequencing modes.
     *
     * @return  resource  Running process.
     *
     * @since   2.0.0
     */
    private function spawn(string $directory, string $mode, string $label, int $batch = 7)
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/sequencer-partner.php', $mode, $directory, $label,
                (string) $batch],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/partner-' . $label . '.stdout', 'w'],
                2 => ['file', $directory . '/partner-' . $label . '.stderr', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The sequencer partner process could not be started.');
        }

        return $process;
    }

    /**
     * End a partner that is still running and release its handle.
     *
     * @param   resource|null  $partner  Partner process, or null when already closed.
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
        if (proc_get_status($partner)['running']) {
            proc_terminate($partner, 9);
        }
        proc_close($partner);
    }

    /**
     * Write a handshake marker atomically.
     *
     * @param   string  $directory  Handshake directory.
     * @param   string  $name       Marker name.
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
     * @param   string  $path     Marker path.
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
     * Create a private handshake directory.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    private function handshakeDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-sequencer-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('The handshake directory could not be created.');
        }

        return $directory;
    }

    /**
     * Remove a handshake directory.
     *
     * @param   string  $directory  Handshake directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    /**
     * Remove only the drill's own rows, leaving the monotonic head where the drill advanced it.
     *
     * @param   Connection    $database  Free session.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Drill event identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(Connection $database, TableNames $tables, array $ids): void
    {
        if ($database->isTransactionActive()) {
            $database->rollBack();
        }
        foreach (
            ['integration_outbox', 'business_projection_event_staging', 'business_projection_source_events'] as $name
        ) {
            $database->executeStatement(
                sprintf('DELETE FROM %s WHERE event_id IN (?)', $tables->quoted($name)),
                [$ids],
                [ArrayParameterType::STRING],
            );
        }
        $database->close();
    }
}
