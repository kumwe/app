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

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        foreach ($this->schedules->all() as $schedule) {
            $output->line(sprintf(
                '%-32s %-28s %-24s %s',
                $this->value($schedule, 'name'),
                $this->value($schedule, 'job_type'),
                $this->value($schedule, 'cron_expression'),
                $this->value($schedule, 'next_run_at', 'disabled'),
            ));
        }

        return 0;
    }

    /** @param array<string, mixed> $schedule */
    private function value(array $schedule, string $field, string $default = ''): string
    {
        $value = $schedule[$field] ?? null;

        return is_string($value) ? $value : $default;
    }
}
