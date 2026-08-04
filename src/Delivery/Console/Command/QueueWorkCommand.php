<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

final readonly class QueueWorkCommand implements Command
{
    public function __construct(private Worker $worker)
    {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Run the durable PostgreSQL job worker.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
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

            do {
                $handled = $this->worker->runOnce($queue, $workerId);

                if (!$handled && !$once) {
                    usleep($sleepMilliseconds * 1_000);
                }
            } while (!$once);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
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
