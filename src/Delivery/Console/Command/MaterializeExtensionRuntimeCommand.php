<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallReconciler;
use Throwable;

final readonly class MaterializeExtensionRuntimeCommand implements Command
{
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private ExtensionInstallReconciler $installs,
    ) {
    }

    public function name(): string
    {
        return 'extension:runtime:materialize';
    }

    public function description(): string
    {
        return 'Materialize and verify the authoritative extension runtime before process startup. '
            . 'Pass --repair to discard a diverged local generation first.';
    }

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
                $output->line('Discarded the local extension runtime generation.');
            }
            $state = $this->runtime->reconcileAndMaterialize(true);
            $output->line(sprintf('Materialized trusted extension runtime generation %d.', $state->generation));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
