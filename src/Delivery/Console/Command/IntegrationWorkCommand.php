<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\ProcessWorkDispatcher;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;
use Throwable;

/**
 * Supervised exact-generation worker for the transactional outbox and generic process work.
 *
 * @since  2.0.0
 */
final readonly class IntegrationWorkCommand implements Command
{
    /**
     * Create the integration work command.
     *
     * @param  OutboxDispatcher             $outbox         Transactional outbox dispatcher drained by this worker.
     * @param  ProcessWorkDispatcher        $processes      Process-work dispatcher drained by this worker.
     * @param  ExtensionRuntimeMapCompiler  $runtime        Trusted active extension runtime.
     * @param  RuntimeMaterializationState  $loadedRuntime  Trusted loaded generation used to fence every claim.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OutboxDispatcher $outbox,
        private ProcessWorkDispatcher $processes,
        private ExtensionRuntimeMapCompiler $runtime,
        private RuntimeMaterializationState $loadedRuntime,
    ) {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Canonical `integration:work` command name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'integration:work';
    }

    /**
     * Return the one-line console command description.
     *
     * @return  string  One-line worker purpose shown by the console.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Dispatch durable integration events and process work under the trusted runtime generation.';
    }

    /**
     * Execute the command with validated positional arguments and options.
     *
     * @param   list<string>  $arguments  Ordered console arguments supplied by the dispatcher.
     * @param   Output        $output     Console output sink for results and sanitized failures.
     *
     * @return  int  Process exit status: zero after a clean drain, one after failure.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $once = isset($options['once']);
            $sleep = $this->integer($options, 'sleep-ms', 1_000, 50, 60_000);
            $lease = $this->integer($options, 'lease-seconds', 60, 5, 3_600);
            $maximum = $this->integer($options, 'max-items', 0, 0, 1_000_000);
            $maximumRuntime = $this->integer($options, 'max-runtime', 0, 0, 604_800);
            $stream = $options['stream'] ?? 'all';
            if (!is_string($stream) || !in_array($stream, ['all', 'outbox', 'process'], true)) {
                throw new InvalidArgumentException('Integration stream must be all, outbox, or process.');
            }
            if (!$this->loadedRuntime->trusted || $this->loadedRuntime->generation < 0) {
                throw new RuntimeException('Integration work requires a trusted runtime generation.');
            }
            $generation = (string) $this->loadedRuntime->generation;
            $workerId = 'integration:' . $this->loadedRuntime->replicaId;
            $started = hrtime(true);
            $handled = 0;
            $draining = false;
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                $drain = static function () use (&$draining): void {
                    $draining = true;
                };
                pcntl_signal(SIGTERM, $drain);
                pcntl_signal(SIGINT, $drain);
                pcntl_signal(SIGHUP, $drain);
                pcntl_signal(SIGQUIT, $drain);
            }

            do {
                $this->runtime->assertLoadedGenerationCurrent($this->loadedRuntime);
                $didWork = false;
                if ($stream !== 'process') {
                    $didWork = $this->outbox->dispatchOne($workerId, $generation, $lease) || $didWork;
                }
                if ($stream !== 'outbox') {
                    $didWork = $this->processes->dispatchOne($workerId, $generation, $lease) || $didWork;
                }
                $handled += $didWork ? 1 : 0;
                $elapsed = (int) ((hrtime(true) - $started) / 1_000_000_000);
                $draining = $draining
                    || ($maximum > 0 && $handled >= $maximum)
                    || ($maximumRuntime > 0 && $elapsed >= $maximumRuntime);
                if (!$didWork && !$once && !$draining) {
                    usleep($sleep * 1_000);
                }
            } while (!$once && !$draining);

            $output->line(sprintf('Integration worker processed %d item batch(es).', $handled));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * Parse and validate the supplied long-form command options.
     *
     * @param   list<string>  $arguments  Ordered console arguments supplied by the dispatcher.
     *
     * @return  array<string, string|true>
     *
     * @since   2.0.0
     */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if ($argument === '--once') {
                $options['once'] = true;
                continue;
            }
            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Integration worker options must use --name=value syntax.');
            }
            if (isset($options[$matches[1]])) {
                throw new InvalidArgumentException('An integration worker option is duplicated.');
            }
            $options[$matches[1]] = $matches[2];
        }
        $unknown = array_diff(array_keys($options), [
            'once', 'stream', 'sleep-ms', 'lease-seconds', 'max-items', 'max-runtime',
        ]);
        if ($unknown !== []) {
            throw new InvalidArgumentException('An integration worker option is unsupported.');
        }
        return $options;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, string|true>  $options  Validated command options keyed by long-form name.
     * @param   string                      $name     Stable contribution or option name being addressed.
     * @param   int                         $default  Fallback value used when the option is absent.
     * @param   int                         $minimum  Inclusive lower bound accepted for the value.
     * @param   int                         $maximum  Inclusive upper bound accepted for the value.
     *
     * @return  int  Validated option value inside the configured bounds.
     *
     * @since   2.0.0
     */
    private function integer(array $options, string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Integration %s must be an integer.', $name));
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException(sprintf('Integration %s is outside its allowed range.', $name));
        }
        return $integer;
    }
}
