<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueueFairness;
use Kumwe\App\Infrastructure\Automation\DoctrineQueuePermits;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\QueuePermitSchemaFixture;
use Kumwe\Automation\QueueRuntimePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Races six real claimant processes on one declared queue ceiling and proves nobody ever over-claimed.
 *
 * V2-SCL-007 requires no over-claim beyond the declared tolerance, no starvation, bounded recovery after
 * a lost worker, and no site able to monopolise capacity. Each claimant in
 * `tests/Support/permit-claimant.php` records the slot it was granted and the monotonic instants of its
 * hold, so the drill can check the one property a row count cannot: that no slot was held by two
 * claimants at the same time and that the number of simultaneously held slots never exceeded the
 * ceiling. A seventh claimant is killed holding a permit while the race runs, and the time until its slot
 * is granted again is measured against the lease. Fairness is then proved on the same engine by a site
 * that keeps adding work and still cannot push two smaller sites out of their turns.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineQueuePermits::class)]
#[CoversClass(DoctrineJobQueueFairness::class)]
final class QueuePermitOverclaimAndFairnessIntegrationTest extends TestCase
{
    /**
     * Declared ceiling the racing claimants share.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int CEILING = 3;

    /**
     * Racing claimant processes, more than the ceiling so exhaustion is exercised.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int CLAIMANTS = 6;

    /**
     * Permits each racing claimant must be granted before it stops.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int GRANTS_PER_CLAIMANT = 25;

    /**
     * Six processes racing three permits never hold more than three, never share a slot, all finish.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRacingClaimantsNeverExceedTheCeilingAndALostHolderIsReclaimedWithinItsLease(): void
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $database = (new DoctrineConnectionFactory($configuration->database))->create();
        $tables = QueuePermitSchemaFixture::create($database);
        $policy = new QueueRuntimePolicy('acme.race', 5, 5, self::CEILING, 7, 9);
        $permits = new DoctrineQueuePermits($database, $tables);
        $directory = $this->handshakeDirectory();
        $processes = [];
        try {
            $permits->synchronize($policy, new DateTimeImmutable());
            for ($index = 0; $index < self::CLAIMANTS; $index++) {
                $processes['loop-' . $index] = $this->spawn(
                    $directory,
                    'loop',
                    $tables->prefix(),
                    $policy,
                    'loop-' . $index,
                    self::GRANTS_PER_CLAIMANT,
                );
            }
            $processes['hold'] = $this->spawn($directory, 'hold', $tables->prefix(), $policy, 'hold', 1);
            usleep(300_000);
            $this->mark($directory, 'start');
            self::assertTrue($this->await($directory . '/held-hold', 30.0), 'The holder never acquired a permit.');
            [$heldSlot, $heldToken] = explode(':', (string) file_get_contents($directory . '/held-hold'), 2);
            $heldSlot = (int) $heldSlot;
            proc_terminate($processes['hold'], 9);
            proc_close($processes['hold']);
            unset($processes['hold']);
            $killedAt = hrtime(true);

            $reclaimedAfter = null;
            $deadline = $killedAt + 12_000_000_000;
            while ($reclaimedAfter === null && hrtime(true) < $deadline) {
                $now = new DateTimeImmutable();
                $holder = $database->fetchAssociative(sprintf(
                    'SELECT lease_token, lease_expires_at FROM %s WHERE queue_id = ? AND slot_number = ?',
                    $tables->quoted('job_queue_permits'),
                ), [$policy->queue, $heldSlot]);
                $expiry = is_array($holder) && is_string($holder['lease_expires_at'])
                    ? new DateTimeImmutable($holder['lease_expires_at'])
                    : null;
                if ($expiry === null || $expiry <= $now || ($holder['lease_token'] ?? null) !== $heldToken) {
                    $reclaimedAfter = (hrtime(true) - $killedAt) / 1_000_000_000;
                    break;
                }
                usleep(50_000);
            }
            self::assertNotNull($reclaimedAfter, 'The killed holder\'s slot was never freed.');
            self::assertLessThanOrEqual(
                7.0,
                $reclaimedAfter,
                'A lost holder\'s slot returns within its five-second lease.',
            );

            $holds = [];
            $granted = 0;
            $exhausted = 0;
            foreach ($processes as $label => $process) {
                self::assertTrue(
                    $this->await($directory . '/claimant-' . $label . '.json', 90.0),
                    sprintf('Claimant %s never reported.', $label),
                );
                $result = json_decode((string) file_get_contents($directory . '/claimant-' . $label . '.json'), true);
                self::assertIsArray($result);
                self::assertSame('completed', $result['outcome'], sprintf('Claimant %s failed.', $label));
                self::assertSame(self::GRANTS_PER_CLAIMANT, $result['acquired']);
                $granted += (int) $result['acquired'];
                $exhausted += (int) $result['exhausted'];
                foreach ($result['holds'] as $hold) {
                    $holds[] = ['label' => $label] + $hold;
                }
                proc_close($process);
                unset($processes[$label]);
            }
            self::assertSame(self::CLAIMANTS * self::GRANTS_PER_CLAIMANT, $granted);
            self::assertGreaterThan(0, $exhausted, 'Six claimants on three permits must have met exhaustion.');
            self::assertSame(0, $this->overlappingSlotHolds($holds), 'No slot was ever held by two claimants at once.');
            self::assertLessThanOrEqual(
                self::CEILING,
                $this->peakConcurrentHolds($holds),
                'The number of simultaneously held permits never exceeded the declared ceiling.',
            );
            foreach (array_column($holds, 'slot') as $slot) {
                self::assertLessThan(self::CEILING, $slot, 'Only declared slots are ever granted.');
            }
            self::assertSame(0, (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE queue_id = ? AND lease_expires_at > ?',
                $tables->quoted('job_queue_permits'),
            ), [$policy->queue, new DateTimeImmutable()], [Types::STRING, Types::DATETIME_IMMUTABLE]));
        } finally {
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            QueuePermitSchemaFixture::drop($database, $tables);
            $database->close();
            $this->removeDirectory($directory);
        }
    }

    /**
     * A site that keeps adding jobs cannot push two smaller sites out of their durable turns.
     *
     * Site A starts with forty pending jobs and adds five more after every claim; sites B and C hold two
     * each. Two independent sessions take twelve turns between them. Every one of B's and C's four jobs
     * is served within the first eight turns, and no two consecutive turns go to the same site while
     * another site still has runnable work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASiteWithASustainedBacklogCannotMonopoliseClaimTurns(): void
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $factory = new DoctrineConnectionFactory($configuration->database);
        $primary = $factory->create();
        $replica = $factory->create();
        $tables = QueuePermitSchemaFixture::create($primary);
        $replicaTables = new TableNames($replica, $tables->prefix());
        $fairness = [
            new DoctrineJobQueueFairness($primary, $tables),
            new DoctrineJobQueueFairness($replica, $replicaTables),
        ];
        $sessions = [$primary, $replica];
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $scopeOf = static fn (string $site): string => hash('sha256', $site . "\0");
        try {
            foreach (['site-a' => 40, 'site-b' => 2, 'site-c' => 2] as $site => $count) {
                for ($index = 0; $index < $count; $index++) {
                    $this->enqueue($primary, $tables, $fairness[0], $site, $now);
                }
            }
            $served = [];
            for ($turn = 0; $turn < 12; $turn++) {
                $session = $sessions[$turn % 2];
                $names = $turn % 2 === 0 ? $tables : $replicaTables;
                $scope = $session->transactional(function () use ($session, $names, $fairness, $turn, $now): ?string {
                    $scope = $fairness[$turn % 2]->claim('acme.work', $now);
                    if ($scope === null) {
                        return null;
                    }
                    $job = $session->fetchOne(sprintf(
                        "SELECT id FROM %s WHERE queue = ? AND worker_scope = ? AND status = 'pending' "
                        . 'ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                        $names->quoted('jobs'),
                    ), ['acme.work', $scope]);
                    if (!is_string($job)) {
                        return null;
                    }
                    $session->update($names->raw('jobs'), [
                        'status' => 'reserved',
                        'lease_expires_at' => $now->modify('+1 hour'),
                    ], ['id' => $job], ['lease_expires_at' => Types::DATETIME_IMMUTABLE]);

                    return $scope;
                });
                self::assertNotNull($scope, sprintf('Turn %d found no runnable lane.', $turn));
                $served[] = $scope;
                for ($index = 0; $index < 5; $index++) {
                    $this->enqueue($primary, $tables, $fairness[0], 'site-a', $now);
                }
            }
            $counts = array_count_values($served);
            self::assertSame(2, $counts[$scopeOf('site-b')] ?? 0, 'Site B\'s two jobs were both served.');
            self::assertSame(2, $counts[$scopeOf('site-c')] ?? 0, 'Site C\'s two jobs were both served.');
            $lastSmall = max(array_merge(
                array_keys($served, $scopeOf('site-b'), true),
                array_keys($served, $scopeOf('site-c'), true),
            ));
            self::assertLessThan(8, $lastSmall, 'Every small-site job is served within the first eight turns.');
            foreach (array_slice($served, 0, 6) as $index => $scope) {
                if ($index > 0) {
                    self::assertNotSame($served[$index - 1], $scope, 'Turns alternate while other lanes have work.');
                }
            }
            self::assertSame(8, $counts[$scopeOf('site-a')] ?? 0, 'The backlog site still receives every spare turn.');
        } finally {
            QueuePermitSchemaFixture::drop($primary, $tables);
            $primary->close();
            $replica->close();
        }
    }

    /**
     * Insert one pending job for a site and register its fairness lane.
     *
     * @param   Connection                $database  Session.
     * @param   TableNames                $tables    Fixture names.
     * @param   DoctrineJobQueueFairness  $fairness  Lane registry bound to the session.
     * @param   string                    $site      Owning site.
     * @param   DateTimeImmutable         $now       Availability instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function enqueue(
        Connection $database,
        TableNames $tables,
        DoctrineJobQueueFairness $fairness,
        string $site,
        DateTimeImmutable $now,
    ): void {
        $id = Uuid::uuid7()->toString();
        $database->insert($tables->raw('jobs'), [
            'id' => $id, 'queue' => 'acme.work', 'status' => 'pending',
            'execution_scope' => 'installation', 'available_at' => $now,
        ], ['available_at' => Types::DATETIME_IMMUTABLE]);
        $fairness->record('acme.work', $id, $site, null);
    }

    /**
     * Count pairs of holds on the same slot whose intervals overlap.
     *
     * @param   list<array{label: string, slot: int, begin_ns: int, end_ns: int}>  $holds  Recorded holds.
     *
     * @return  int  Overlapping same-slot pairs; zero means no slot was ever double-granted.
     *
     * @since   2.0.0
     */
    private function overlappingSlotHolds(array $holds): int
    {
        $bySlot = [];
        foreach ($holds as $hold) {
            $bySlot[$hold['slot']][] = $hold;
        }
        $overlaps = 0;
        foreach ($bySlot as $slotHolds) {
            usort($slotHolds, static fn (array $a, array $b): int => $a['begin_ns'] <=> $b['begin_ns']);
            for ($index = 1; $index < count($slotHolds); $index++) {
                if ($slotHolds[$index]['begin_ns'] < $slotHolds[$index - 1]['end_ns']) {
                    $overlaps++;
                }
            }
        }

        return $overlaps;
    }

    /**
     * Sweep every hold interval and report the largest number held at one instant.
     *
     * @param   list<array{label: string, slot: int, begin_ns: int, end_ns: int}>  $holds  Recorded holds.
     *
     * @return  int  Peak simultaneous holds.
     *
     * @since   2.0.0
     */
    private function peakConcurrentHolds(array $holds): int
    {
        $events = [];
        foreach ($holds as $hold) {
            $events[] = [$hold['begin_ns'], 1];
            $events[] = [$hold['end_ns'], -1];
        }
        sort($events);
        $active = 0;
        $peak = 0;
        foreach ($events as [, $change]) {
            $active += $change;
            $peak = max($peak, $active);
        }

        return $peak;
    }

    /**
     * Start one claimant process.
     *
     * @param   string              $directory  Handshake directory.
     * @param   string              $mode       `loop` or `hold`.
     * @param   string              $prefix     Fixture table prefix.
     * @param   QueueRuntimePolicy  $policy     Shared policy.
     * @param   string              $label      Claimant label.
     * @param   int                 $target     Grants the claimant must reach.
     *
     * @return  resource  Running process.
     *
     * @since   2.0.0
     */
    private function spawn(
        string $directory,
        string $mode,
        string $prefix,
        QueueRuntimePolicy $policy,
        string $label,
        int $target,
    ) {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/permit-claimant.php', $mode, $directory, $prefix,
                $policy->queue, (string) $policy->maximumInFlight, $label, (string) $target],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/' . $label . '.stdout', 'w'],
                2 => ['file', $directory . '/' . $label . '.stderr', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The permit claimant process could not be started.');
        }

        return $process;
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
        $directory = sys_get_temp_dir() . '/kumwe-permits-' . bin2hex(random_bytes(6));
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
}
