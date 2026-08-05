<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
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
            $context = $this->authorization->require($options, 'automation.manage');
            $result = match ($action) {
                'schedules' => [
                    'items' => $this->automation->schedules($context),
                    'job_types' => $this->automation->jobTypes($context),
                ],
                'schedule' => $this->automation->schedule($context, CommandInput::required($options, 'id')),
                'jobs' => ['items' => $this->automation->jobs($context, (int) ($options['limit'] ?? 100))],
                'create' => ['id' => $this->automation->createSchedule(
                    $context,
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
                'enable' => $this->scheduleState($context, $options, true),
                'disable' => $this->scheduleState($context, $options, false),
                'delete' => $this->deleteSchedule($context, $options),
                'retry' => $this->jobAction($context, $options, true),
                'cancel' => $this->jobAction($context, $options, false),
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
    private function scheduleState(ExecutionContext $context, array $options, bool $enabled): array
    {
        $this->automation->setScheduleEnabled(
            $context,
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
    private function deleteSchedule(ExecutionContext $context, array $options): array
    {
        $this->automation->deleteSchedule(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function jobAction(ExecutionContext $context, array $options, bool $retry): array
    {
        $id = CommandInput::required($options, 'id');
        if ($retry) {
            $this->automation->retryJob($context, $id);
        } else {
            $this->automation->cancelJob($context, $id);
        }
        return ['updated' => true];
    }
}
