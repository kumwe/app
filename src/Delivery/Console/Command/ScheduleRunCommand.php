<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

final readonly class ScheduleRunCommand implements Command
{
    public function __construct(private Scheduler $scheduler, private SystemPrincipal $system)
    {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Dispatch due schedules once or continuously with --loop.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $loop = in_array('--loop', $arguments, true);

            foreach ($arguments as $argument) {
                if ($argument !== '--loop') {
                    throw new InvalidArgumentException('schedule:run accepts only the optional --loop flag.');
                }
            }

            $context = $this->system->context(
                SiteContext::default(),
                'scheduler-' . bin2hex(random_bytes(16)),
            );

            do {
                $dispatched = $this->scheduler->dispatchDue($context);

                if (!$loop) {
                    $output->line(sprintf('Dispatched %d due schedule(s).', $dispatched));
                } else {
                    sleep(15);
                }
            } while ($loop);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
