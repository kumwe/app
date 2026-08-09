<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

/**
 * Contract for one command the `kumwe` console dispatches by name.
 *
 * `ConsoleApplication` collects every implementation registered in the container, indexes it by
 * `name()`, and prints `description()` beside that name in the command list. Implementations own their
 * own option parsing and their own authorization, because the console has neither a request nor a
 * session to inherit authority from. They also own their failures: an implementation reports trouble by
 * returning a non-zero status after writing an operator-readable message, rather than letting an
 * exception escape to the entry point where it would print a stack trace.
 *
 * @since  2.0.0
 */
interface Command
{
    /**
     * Name the operator types to invoke this command.
     *
     * @return  string  Stable, colon-separated name, unique across the registered command set.
     *
     * @since   2.0.0
     */
    public function name(): string;

    /**
     * Describe what the command does, for the console's command listing.
     *
     * @return  string  Single line short enough to sit beside the command name in a terminal.
     *
     * @since   2.0.0
     */
    public function description(): string;

    /**
     * Run the command and report the status the console process exits with.
     *
     * @param   list<string>  $arguments  Arguments following the command name, re-indexed from zero.
     * @param   Output        $output     Sink the command writes its result and its failure text to.
     *
     * @return  int  Process exit status: 0 on success, non-zero when the command could not complete.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int;
}
