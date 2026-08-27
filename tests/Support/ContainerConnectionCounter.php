<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Connection as LoggingConnection;
use Doctrine\DBAL\Logging\Driver as LoggingDriver;
use Kumwe\App\Kernel\Container;
use ReflectionProperty;
use WeakMap;

/**
 * Counts every statement the container's own shared connection executes, without replacing it.
 *
 * The budget tests that count statements used to hand-build a second connection around the write
 * repository, which proves the repository and nothing above it. The claim P4-B makes is about the whole
 * command — that a thousand-line document through `BusinessRecordService` does not issue a thousand
 * round trips from anywhere, the idempotency ledger and the publication included — and that claim can
 * only be counted on the connection every one of those collaborators actually shares.
 *
 * That connection is materialized while the container is still being built, so no middleware can reach
 * it through configuration. This helper therefore wraps the live connection in place with Doctrine's own
 * logging decorators, pointed at a `BusinessQueryCounter`: the driver, so a reconnect stays counted, and
 * the open driver connection, so the session — its pinned UTC time zone included — survives untouched.
 * A connection is wrapped exactly once for the life of the process, and every later caller receives the
 * same counter reset to zero, so two tests sharing one kernel cannot leave each other a dead counter.
 * The two properties are DBAL internals, which is exactly why the reflection lives in this one file and
 * nowhere else.
 *
 * @since  2.0.0
 */
final class ContainerConnectionCounter
{
    /**
     * The counter attached to each already-wrapped connection, keyed weakly so kernels can be reclaimed.
     *
     * @var    WeakMap<Connection, BusinessQueryCounter>|null
     * @since  2.0.0
     */
    private static ?WeakMap $wrapped = null;

    /**
     * Wrap the container's shared connection and return the counter every statement now reports to.
     *
     * Counting starts from zero at return: the wrap resets the counter, so neither boot-time statements
     * nor an earlier test's traffic is attributed to the operation under measurement.
     *
     * @param   Container  $container  Live integration container whose connection is instrumented.
     *
     * @return  BusinessQueryCounter  Counter fed by every statement the shared connection executes.
     *
     * @since   2.0.0
     */
    public static function wrap(Container $container): BusinessQueryCounter
    {
        /** @var WeakMap<Connection, BusinessQueryCounter> $wrapped */
        $wrapped = self::$wrapped ??= new WeakMap();
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);
        $counter = $wrapped[$connection] ?? null;
        if ($counter === null) {
            $counter = new BusinessQueryCounter();
            $driverProperty = new ReflectionProperty(Connection::class, 'driver');
            $driverProperty->setValue(
                $connection,
                new LoggingDriver($driverProperty->getValue($connection), $counter),
            );
            $liveProperty = new ReflectionProperty(Connection::class, '_conn');
            $live = $liveProperty->getValue($connection);
            if ($live !== null && !$live instanceof LoggingConnection) {
                $liveProperty->setValue($connection, new LoggingConnection($live, $counter));
            }
            $wrapped[$connection] = $counter;
        }
        $counter->reset();

        return $counter;
    }
}
