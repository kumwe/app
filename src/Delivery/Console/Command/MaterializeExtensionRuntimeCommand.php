<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Application\Install\ExtensionInstallReconciler;
use Throwable;

/**
 * Console entry point that brings this replica's extension runtime map in step with database authority.
 *
 * Container startup runs it before the application accepts traffic, which is what guarantees a process
 * never serves an extension set the database no longer describes. It authorizes nothing, because it
 * takes no operator input beyond `--repair` and is registered as a recovery command in
 * `bootstrap/console.php` so it still runs when a broken registry keeps the ordinary container from
 * being built. An interrupted install is settled first: a pending one is a refusal, not something to
 * materialize over.
 *
 * @since  2.0.0
 */
final readonly class MaterializeExtensionRuntimeCommand implements Command
{
    /**
     * Wire the command to the runtime compiler and to the install reconciler it runs first.
     *
     * @param  ExtensionRuntimeMapCompiler  $runtime   Compiler that verifies authority and writes the local map.
     * @param  ExtensionInstallReconciler   $installs  Settles installs interrupted mid-flight before compilation.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private ExtensionInstallReconciler $installs,
    ) {
    }

    /**
     * Name the operator types to materialize the runtime map.
     *
     * @return  string  Always `extension:runtime:materialize`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:runtime:materialize';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  Two sentences: what the command does, and what `--repair` adds.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_runtime_materialize.description';
    }

    /**
     * Reconcile pending installs, then write and verify the authoritative runtime generation locally.
     *
     * The only argument accepted is `--repair`, and it must be the whole argument list. Without it the
     * compiler refuses to overwrite a local generation ahead of the database, which is what a restored
     * backup or a rolled-back host leaves behind; `--repair` is the operator's explicit decision to
     * throw that local copy away and re-derive it from authority. Success prints the generation number
     * now on disk. Failure is written as a message and reported as exit status 1, so a supervisor sees a
     * failed start rather than a stack trace.
     *
     * @param   list<string>  $arguments  Empty, or exactly `['--repair']`; anything else is rejected.
     * @param   Output        $output     Sink for the progress lines, or for the failure message.
     *
     * @return  int  0 when the generation was materialized, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $repair = $arguments === ['--repair'];
            if ($arguments !== [] && !$repair) {
                throw new \InvalidArgumentException('Runtime materialization accepts only an optional --repair.');
            }
            $this->installs->reconcile();
            if ($this->installs->hasPending()) {
                throw new \RuntimeException('An interrupted extension install still requires reconciliation.');
            }
            if ($repair) {
                // Restoring a backup, or rolling a host back, can leave a local generation ahead of
                // database authority. Materialization refuses that on purpose; --repair is the
                // explicit operator decision to discard the local copy and re-derive it.
                $this->runtime->discardLocal();
                $output->message('core.console.extension_runtime_materialize.discarded_local_generation');
            }
            $state = $this->runtime->reconcileAndMaterialize(true);
            $output->message('core.console.extension_runtime_materialize.materialized_generation', [
                'generation' => $state->generation,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
