<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class CreateScheduleCommand implements Command
{
    public function __construct(private ScheduleRepository $schedules, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'schedule:create';
    }

    public function description(): string
    {
        return 'Create a validated recurring job schedule.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $payload = $this->payload($options['payload'] ?? '{}');
            $id = $this->schedules->create(
                $this->required($options, 'name'),
                $this->required($options, 'cron'),
                $options['timezone'] ?? 'UTC',
                $this->required($options, 'job'),
                $payload,
                $options['queue'] ?? 'default',
                isset($options['first-run']) ? new DateTimeImmutable($options['first-run']) : $this->clock->now(),
            );
            $output->line(sprintf('Created schedule %s.', $id));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.*)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Schedule options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function payload(string $json): array
    {
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The schedule payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
