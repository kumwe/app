<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;

final readonly class ListSchedulesCommand implements Command
{
    public function __construct(private ScheduleRepository $schedules)
    {
    }

    public function name(): string
    {
        return 'schedule:list';
    }

    public function description(): string
    {
        return 'List configured schedules and their next run time.';
    }

    public function execute(array $arguments, Output $output): int
    {
        foreach ($this->schedules->all() as $schedule) {
            $output->line(sprintf(
                '%-32s %-28s %-24s %s',
                (string) ($schedule['name'] ?? ''),
                (string) ($schedule['job_type'] ?? ''),
                (string) ($schedule['cron_expression'] ?? ''),
                (string) ($schedule['next_run_at'] ?? 'disabled'),
            ));
        }

        return 0;
    }
}
