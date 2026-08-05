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
        return 'Materialize and verify the authoritative extension runtime before process startup.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            if ($arguments !== []) {
                throw new \InvalidArgumentException('Runtime materialization accepts no arguments.');
            }
            $this->installs->reconcile();
            if ($this->installs->hasPending()) {
                throw new \RuntimeException('An interrupted extension install still requires reconciliation.');
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
