<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

final readonly class ManageAutomationCommand implements Command
{
    public function __construct(
        private AutomationManagementService $automation,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    public function name(): string
    {
        return 'automation';
    }

    public function description(): string
    {
        return 'List and manage schedules and queued jobs.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'schedules';
            $options = CommandInput::options($arguments);
            $actor = $this->authorization->require($options, 'automation.manage')->subject();
            $result = match ($action) {
                'schedules' => [
                    'items' => $this->automation->schedules(),
                    'job_types' => $this->automation->jobTypes(),
                ],
                'schedule' => $this->automation->schedule(CommandInput::required($options, 'id')),
                'jobs' => ['items' => $this->automation->jobs((int) ($options['limit'] ?? 100))],
                'create' => ['id' => $this->automation->createSchedule(
                    $actor,
                    CommandInput::required($options, 'name'),
                    CommandInput::required($options, 'cron'),
                    $options['timezone'] ?? 'UTC',
                    CommandInput::required($options, 'job'),
                    CommandInput::jsonObject($options, 'payload'),
                    $options['queue'] ?? 'default',
                    isset($options['first-run'])
                        ? new DateTimeImmutable($options['first-run'])
                        : new DateTimeImmutable(),
                )],
                'enable' => $this->scheduleState($actor, $options, true),
                'disable' => $this->scheduleState($actor, $options, false),
                'delete' => $this->deleteSchedule($actor, $options),
                'retry' => $this->jobAction($actor, $options, true),
                'cancel' => $this->jobAction($actor, $options, false),
                default => throw new \InvalidArgumentException('Unsupported automation action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function scheduleState(string $actor, array $options, bool $enabled): array
    {
        $this->automation->setScheduleEnabled(
            $actor,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
            $enabled,
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{deleted: bool}
     */
    private function deleteSchedule(string $actor, array $options): array
    {
        $this->automation->deleteSchedule(
            $actor,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function jobAction(string $actor, array $options, bool $retry): array
    {
        $id = CommandInput::required($options, 'id');
        if ($retry) {
            $this->automation->retryJob($actor, $id);
        } else {
            $this->automation->cancelJob($actor, $id);
        }
        return ['updated' => true];
    }
}
