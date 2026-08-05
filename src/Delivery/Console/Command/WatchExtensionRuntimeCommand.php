<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallReconciler;
use Throwable;

final readonly class WatchExtensionRuntimeCommand implements Command
{
    public function __construct(
        private ExtensionRuntimeMapCompiler $runtime,
        private ExtensionInstallReconciler $installs,
    ) {
    }

    public function name(): string
    {
        return 'extension:runtime:watch';
    }

    public function description(): string
    {
        return 'Continuously verify authority/artifacts and refresh local runtime readiness.';
    }

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

    /** @param list<string> $arguments */
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
            if ($argument !== '--once' && !str_starts_with($argument, '--interval=')
                && !str_starts_with($argument, '--reload-pid=')) {
                throw new \InvalidArgumentException('Unknown runtime watcher option.');
            }
        }

        return $default;
    }
}
