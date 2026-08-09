<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallReconciler;
use Throwable;

/**
 * Long-lived console command that keeps a replica's extension runtime in step with database authority.
 *
 * A replica materializes its runtime map once at startup, but the authoritative extension set can move
 * underneath it at any time — an install elsewhere, a key rotation, a retirement pass. This command is
 * the out-of-band worker that notices: on every pass it settles interrupted installs, reconciles and
 * materializes the current generation, confirms the result still matches authority, and refreshes the
 * signed readiness marker that `LocalRuntimeReadinessProbe` serves to the load balancer. That division
 * is deliberate — the readiness endpoint stays a cheap file check because this command carries the
 * verification cost.
 *
 * Run it with `--once` as a startup gate, which is how `tools/development-server.sh` refuses traffic
 * until the local runtime verifies; run it without to supervise a replica for as long as the process
 * lives. In the supervising mode a failed pass is reported and retried on the next tick rather than
 * ending the process, because a transient database or lock failure must not take readiness reporting
 * down with it. It is registered as a recovery command in `bootstrap/console.php`, so it still runs
 * when a broken registry prevents the full container from being built.
 *
 * @since  2.0.0
 */
final readonly class WatchExtensionRuntimeCommand implements Command
{
    /**
     * Wire the watcher to the runtime compiler and to the install reconciler each pass runs first.
     *
     * @param  ExtensionRuntimeMapCompiler  $runtime   Compiler that verifies authority, writes the local map and
     *         publishes the readiness marker.
     * @param  ExtensionInstallReconciler   $installs  Settles installs interrupted mid-flight before each pass.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private ExtensionInstallReconciler $installs,
    ) {
    }

    /**
     * Name the operator types to supervise the local extension runtime.
     *
     * @return  string  Always `extension:runtime:watch`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:runtime:watch';
    }

    /**
     * Describe the watcher for the console command listing.
     *
     * @return  string  One-line summary shown beside the command name.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Continuously verify authority/artifacts and refresh local runtime readiness.';
    }

    /**
     * Run convergence passes until the replica is verified once, or for as long as the process lives.
     *
     * One pass reconciles pending installs, materializes the authoritative generation without claiming
     * a lease, optionally signals a PHP-FPM master, re-checks that the materialized state is still
     * current, and publishes the readiness marker. `--once` performs exactly one pass and reports its
     * outcome in the exit status; otherwise a failed pass only writes its message and the loop sleeps
     * `--interval=` seconds before trying again, so the process keeps supervising through a transient
     * failure. `--reload-pid=` names a PHP-FPM master to send `SIGUSR2` to when the generation actually
     * changed under a replica that had already materialized one; the signal is skipped on the first
     * pass after a cold start, where there is no previously loaded generation for workers to reload
     * away from. The convergence check doubles as a heartbeat on this replica's materialization row, so
     * a watcher that keeps running also keeps a retirement pass from removing the tree it serves.
     *
     * @param   list<string>  $arguments  Options after the command name: `--once`, `--interval=`, `--reload-pid=`.
     * @param   Output        $output     Sink each pass writes its failure text to; a clean pass is silent.
     *
     * @return  int  Exit status: 0 after a `--once` pass converged, 1 when a `--once` pass failed or the
     *          options could not be parsed. Without `--once` the loop never ends on its own, so the
     *          process runs until it is signalled and this value is not reached.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $once = in_array('--once', $arguments, true);
            $interval = $this->integerOption($arguments, '--interval=', 10);
            $reloadPid = $this->integerOption($arguments, '--reload-pid=', 0, true);
            do {
                try {
                    $this->installs->reconcile();
                    if ($this->installs->hasPending()) {
                        throw new \RuntimeException('An interrupted extension install still requires reconciliation.');
                    }
                    $before = $this->runtime->inspectLocal()->generation;
                    $state = $this->runtime->reconcileAndMaterialize(false, false);
                    if ($reloadPid > 0 && $before > 0 && $before !== $state->generation) {
                        if (!function_exists('posix_kill') || !posix_kill($reloadPid, SIGUSR2)) {
                            throw new \RuntimeException('PHP-FPM could not be reloaded after runtime convergence.');
                        }
                    }
                    if (!$this->runtime->isCurrent($state)) {
                        throw new \RuntimeException(
                            'Runtime materialization did not converge with database authority.',
                        );
                    }
                    $this->runtime->publishLocalReadiness($state);
                } catch (Throwable $failure) {
                    $output->error($failure->getMessage());
                    if ($once) {
                        return 1;
                    }
                }
                if (!$once) {
                    sleep($interval);
                }
            } while (!$once);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Read one `--name=value` integer option from the argument list, or fall back to its default.
     *
     * The first argument carrying the prefix decides the value, and a malformed one is refused rather
     * than silently defaulted — an operator who mistypes an interval gets an error, not a watcher
     * running on a number they did not choose. The scan for unrecognised options happens only on the
     * fall-through path, so an unknown option is caught by whichever lookup does not find its own
     * prefix.
     *
     * @param   list<string>  $arguments  Options the operator passed after the command name.
     * @param   string        $prefix     Option prefix to match, including the trailing `=`.
     * @param   int           $default    Value to use when no argument carries the prefix.
     * @param   bool          $allowZero  Whether zero is a meaningful value, as it is for "no PHP-FPM master".
     *
     * @return  int  The parsed value, or the default when the option was not supplied.
     *
     * @throws  \InvalidArgumentException  When the option's value is not a non-negative integer, when it is
     *          zero and zero is not allowed, or when the arguments contain an option this
     *          command does not recognise.
     *
     * @since   2.0.0
     */
    private function integerOption(array $arguments, string $prefix, int $default, bool $allowZero = false): int
    {
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }
            $value = substr($argument, strlen($prefix));
            if (
                preg_match('/^[0-9]+$/D', $value) !== 1
                || (!$allowZero && (int) $value < 1)
            ) {
                throw new \InvalidArgumentException(sprintf('Invalid %s option.', rtrim($prefix, '=')));
            }

            return (int) $value;
        }
        foreach ($arguments as $argument) {
            if (
                $argument !== '--once' && !str_starts_with($argument, '--interval=')
                && !str_starts_with($argument, '--reload-pid=')
            ) {
                throw new \InvalidArgumentException('Unknown runtime watcher option.');
            }
        }

        return $default;
    }
}
