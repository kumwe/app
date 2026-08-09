<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;

/**
 * Console command that answers the readiness question from inside the application process.
 *
 * This is the thorough readiness check, and it is deliberately not the one `/health/ready` serves:
 * that endpoint is polled continuously by a load balancer and answers from a local marker, while this
 * command re-verifies the database, the migration ledger and every optional dependency on the spot.
 * Reach for it when the answer has to be current rather than cheap — a deployment gate, a container
 * start-up probe, or an operator establishing whether a replica is genuinely fit to serve. It needs
 * no `--site` or `--token-file`, because it discloses nothing beyond a ready-or-not verdict, and it
 * carries that verdict in its exit status so a probe can branch on it without reading the output.
 *
 * @since  2.0.0
 */
final readonly class HealthCheckCommand implements Command
{
    /**
     * Wire the probe whose verdict this command reports.
     *
     * @param  ReadinessProbe  $probe  Aggregate check over the database connection, the migration
     *         ledger, and whichever of Redis, the trust store and the
     *         compiled runtime map this installation wired up.
     *
     * @since  2.0.0
     */
    public function __construct(private ReadinessProbe $probe)
    {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `app:health`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'app:health';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command decides.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Check whether Kumwe is ready to serve traffic.';
    }

    /**
     * Print the current readiness verdict and encode it in the exit status.
     *
     * The probe absorbs and logs whatever made it fail, so the not-ready message is deliberately the
     * same for every cause: a health endpoint that is reachable before authentication must not
     * narrate which dependency is down. The reason belongs in the application log, which the probe
     * writes to at warning level.
     *
     * @param   list<string>  $arguments  Ignored; the command takes no arguments or options.
     * @param   Output        $output     Sink the single verdict line is written to.
     *
     * @return  int  `0` when the installation is ready to serve traffic, `1` when it is not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        if ($this->probe->ready()) {
            $output->line('Kumwe is ready.');

            return 0;
        }

        $output->error('Kumwe is not ready.');

        return 1;
    }
}
