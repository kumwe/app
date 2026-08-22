<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Contract\CliMachineContract;
use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use LogicException;

/**
 * Dispatcher behind `bin/kumwe`: it maps the requested name onto a registered command and runs it.
 *
 * This is the only place the console decides what an argument vector means. It resolves the command
 * name, hands the remaining arguments to the matching `Command`, and returns the status the entry
 * point exits with — so an operator and a CI job read the same outcome from `$?`. The retained CLI
 * machine contract closes the argument grammar before application code runs and closes the returned
 * status afterwards; commands still own authorization and conversion into application requests. The
 * dispatcher itself returns `0` after listing and `64` for an unknown name or invalid invocation. The
 * command set arrives as constructor input, so `bootstrap/console.php` can hand it the reduced recovery
 * set without weakening the same contract.
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
     * Retained machine surface enforced before and after every command execution.
     *
     * @var    CliMachineContract
     * @since  2.0.0
     */
    private readonly CliMachineContract $contract;

    /**
     * Index the registered commands by name and fix the order the listing prints them in.
     *
     * Every name must be unique and present in the retained machine contract. A reduced recovery
     * console may register a subset, but it cannot invent a command or replace an earlier registration.
     *
     * @param  iterable<Command>    $commands  Every command this console can dispatch, in registration order.
     * @param  Output               $output    Sink the listing, the unknown-command message and each command write to.
     * @param  ?CliMachineContract  $contract  Retained surface; generation one when not explicitly supplied.
     *
     * @since  2.0.0
     */
    public function __construct(
        iterable $commands,
        private readonly Output $output,
        ?CliMachineContract $contract = null,
    ) {
        $this->contract = $contract ?? CliV1MachineContract::contract();
        $declared = array_fill_keys($this->contract->commandNames(), true);
        foreach ($commands as $command) {
            $name = $command->name();
            if (isset($this->commands[$name])) {
                throw new LogicException(sprintf('Console command name "%s" is registered more than once.', $name));
            }
            if (!isset($declared[$name])) {
                throw new LogicException(sprintf('Console command name "%s" is absent from the CLI contract.', $name));
            }
            $this->commands[$name] = $command;
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
            $this->output->failure('core.console.application.unknown_command', ['name' => $name]);

            return 64;
        }

        $commandArguments = array_values(array_slice($arguments, 2));
        try {
            $commandArguments = $this->contract->validateInvocation($name, $commandArguments);
        } catch (InvalidArgumentException $failure) {
            $this->output->error($failure->getMessage());

            return 64;
        }

        $exitCode = $this->commands[$name]->execute($commandArguments, $this->output);
        $this->contract->assertExitCode($name, $exitCode);

        return $exitCode;
    }

    /**
     * List the live registration names for machine-contract parity checks.
     *
     * @return  list<string>  Lexically sorted registered command names.
     *
     * @since   2.0.0
     */
    public function commandNames(): array
    {
        return array_keys($this->commands);
    }

    /**
     * Print the product banner and every registered command beside its description.
     *
     * Names are padded to a fixed column so the descriptions line up in a terminal; a name longer than
     * that column pushes its own description right rather than being truncated. Each description is a
     * message identifier the summary line resolves through the output's translator here, so the listing
     * renders in catalogue wording without any command carrying a translator of its own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function renderCommandList(): void
    {
        $this->output->message('core.console.application.banner');
        $this->output->message('core.console.application.available_commands');

        foreach ($this->commands as $command) {
            $this->output->line(sprintf(
                '  %-20s %s',
                $command->name(),
                $this->output->text($command->description()),
            ));
        }
    }
}
