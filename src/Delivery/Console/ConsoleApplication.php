<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

final class ConsoleApplication
{
    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    /**
     * @param iterable<Command> $commands
     */
    public function __construct(iterable $commands, private readonly Output $output)
    {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }

        ksort($this->commands);
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $name = $arguments[1] ?? 'list';

        if ($name === 'list' || $name === '--help' || $name === '-h') {
            $this->renderCommandList();

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $this->output->error(sprintf('Unknown Kumwe command: %s', $name));

            return 64;
        }

        return $this->commands[$name]->execute(array_values(array_slice($arguments, 2)), $this->output);
    }

    private function renderCommandList(): void
    {
        $this->output->line('Kumwe CMS 2.0');
        $this->output->line('Available commands:');

        foreach ($this->commands as $command) {
            $this->output->line(sprintf('  %-20s %s', $command->name(), $command->description()));
        }
    }
}
