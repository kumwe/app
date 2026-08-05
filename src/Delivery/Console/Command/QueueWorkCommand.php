<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

final readonly class QueueWorkCommand implements Command
{
    public function __construct(private Worker $worker, private SystemPrincipal $system)
    {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Run the durable, crash-recovering job worker.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        $workerId = null;
        $context = null;
        $queue = null;

        try {
            $options = $this->options($arguments);
            $queue = $options['queue'] ?? 'default';
            $once = isset($options['once']);

            if (!is_string($queue)) {
                throw new InvalidArgumentException('The worker queue must be a string.');
            }

            $sleepOption = $options['sleep-ms'] ?? null;

            if ($sleepOption !== null && (!is_string($sleepOption) || preg_match('/^[0-9]+$/D', $sleepOption) !== 1)) {
                throw new InvalidArgumentException('Worker sleep must be an integer.');
            }

            $sleepMilliseconds = $sleepOption === null ? 1_000 : (int) $sleepOption;

            if ($sleepMilliseconds < 50 || $sleepMilliseconds > 60_000) {
                throw new InvalidArgumentException('Worker sleep must be between 50 and 60000 milliseconds.');
            }

            $leaseSeconds = $this->integerOption($options, 'lease-seconds', 60, 5, 3_600);
            $maximumJobs = $this->integerOption($options, 'max-jobs', 0, 0, 1_000_000);
            $maximumRuntime = $this->integerOption($options, 'max-runtime', 0, 0, 604_800);

            $host = gethostname();
            $host = $host === false ? 'kumwe' : $host;
            $safeHost = preg_replace('/[^A-Za-z0-9._-]/', '-', $host);
            $safeHost = $safeHost === null || $safeHost === '' ? 'kumwe' : $safeHost;
            $processId = getmypid();
            $processId = $processId === false ? 1 : $processId;
            $workerId = sprintf(
                '%s:%d:%s',
                $safeHost,
                $processId,
                bin2hex(random_bytes(4)),
            );
            $output->line(sprintf('Kumwe worker %s is consuming queue %s.', $workerId, $queue));
            $context = $this->system->context(
                SiteContext::default(),
                'worker-' . bin2hex(random_bytes(16)),
            );

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

            $startedAt = hrtime(true);
            $handledJobs = 0;

            do {
                $handled = $this->worker->runOnce($context, $queue, $workerId, $leaseSeconds);
                $handledJobs += $handled ? 1 : 0;

                $runtimeSeconds = (int) ((hrtime(true) - $startedAt) / 1_000_000_000);
                $draining = $draining
                    || ($maximumJobs > 0 && $handledJobs >= $maximumJobs)
                    || ($maximumRuntime > 0 && $runtimeSeconds >= $maximumRuntime);

                if (!$handled && !$once && !$draining) {
                    usleep($sleepMilliseconds * 1_000);
                }
            } while (!$once && !$draining);

            $output->line(sprintf(
                'Kumwe worker %s drained after %d job(s).',
                $workerId,
                $handledJobs,
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        } finally {
            if (is_string($workerId) && $context instanceof ExecutionContext && is_string($queue)) {
                try {
                    $this->worker->disconnect($context, $workerId, $queue);
                } catch (Throwable $exception) {
                    $output->error(sprintf('Worker heartbeat cleanup failed: %s', $exception->getMessage()));
                }
            }
        }
    }

    /** @param array<string, string|true> $options */
    private function integerOption(
        array $options,
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Worker %s must be an integer.', $name));
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Worker %s must be between %d and %d.',
                $name,
                $minimum,
                $maximum,
            ));
        }

        return $integer;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string|true>
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
                throw new InvalidArgumentException('Worker options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }
}
