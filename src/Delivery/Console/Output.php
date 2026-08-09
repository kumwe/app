<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

/**
 * Sink a console command writes its result text and its failure text to.
 *
 * Commands never reach for `STDOUT` or `echo` themselves; they are handed this contract so the same
 * command can run under the `kumwe` entry point, under a shell script such as
 * `tools/development-server.sh`, or under a unit test that captures every line and asserts on it. Two
 * methods exist so an implementation can keep ordinary output on one stream and failure text on
 * another, which is what lets an operator pipe a command's result somewhere while still seeing why it
 * stopped. Implementations write whole lines and own the line terminator; a command never sends one.
 *
 * @since  2.0.0
 */
interface Output
{
    /**
     * Write one line of ordinary command output.
     *
     * @param   string  $message  Result text a command wants the operator to read, without a terminator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void;

    /**
     * Write one line of failure text, kept apart from ordinary output.
     *
     * @param   string  $message  Operator-facing explanation of why the command could not complete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void;
}
