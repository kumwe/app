<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

/**
 * Dispatcher behind `bin/kumwe`: it maps the requested name onto a registered command and runs it.
 *
 * This is the only place the console decides what an argument vector means. It resolves the command
 * name, hands the remaining arguments to the matching `Command`, and returns the status the entry
 * point exits with — so an operator and a CI job read the same outcome from `$?`. Three statuses come
 * from here rather than from a command: `0` after printing the command list, `64` for a name nothing
 * is registered under, and otherwise whatever the command returned. It performs no authorization and
 * no option parsing beyond the name; both belong to the command. The command set arrives as
 * constructor input, so `bootstrap/console.php` can hand it the reduced recovery set — the commands
 * that must still run when a broken site keeps the full container from being built — without this
 * class knowing the difference.
 *
 * @since  2.0.0
 */
final class ConsoleApplication
{
    /**
     * Registered commands indexed by the name an operator types, sorted for the command listing.
     *
     * @var    array<string, Command>
     * @since  2.0.0
     */
    private array $commands = [];

    /**
     * Index the registered commands by name and fix the order the listing prints them in.
     *
     * A later registration silently replaces an earlier one with the same name, so the container is
     * the single place that decides which implementation owns a given command name.
     *
     * @param  iterable<Command>  $commands  Every command this console can dispatch, in registration order.
     * @param  Output             $output    Sink the listing, the unknown-command message and each command write to.
     *
     * @since  2.0.0
     */
    public function __construct(iterable $commands, private readonly Output $output)
    {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }

        ksort($this->commands);
    }

    /**
     * Dispatch the argument vector and report the status the console process should exit with.
     *
     * The vector arrives exactly as PHP built it, so element zero is the script path and the command
     * name is element one; an empty vector therefore lists the commands rather than failing. `list`,
     * `--help` and `-h` all print the listing, and an unrecognised name is reported on the error
     * stream as a usage failure instead of being guessed at.
     *
     * @param   list<string>  $arguments  Raw process argument vector, script path included.
     *
     * @return  int  Exit status: 0 after a listing, 64 when no command carries the requested name, and
     *          otherwise the status the dispatched command returned.
     *
     * @since   2.0.0
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

    /**
     * Print the product banner and every registered command beside its description.
     *
     * Names are padded to a fixed column so the descriptions line up in a terminal; a name longer than
     * that column pushes its own description right rather than being truncated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function renderCommandList(): void
    {
        $this->output->line('Kumwe CMS 2.0');
        $this->output->line('Available commands:');

        foreach ($this->commands as $command) {
            $this->output->line(sprintf('  %-20s %s', $command->name(), $command->description()));
        }
    }
}
