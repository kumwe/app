<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use Throwable;

/**
 * Runs reusable static conformance checks over one extension package.
 *
 * @since  2.0.0
 */
final readonly class RunExtensionConformanceCommand implements Command
{
    /**
     * Bind the command to code-free package conformance.
     *
     * @param  StaticConformanceRunner  $runner  Code-free package conformance service.
     *
     * @since  2.0.0
     */
    public function __construct(private StaticConformanceRunner $runner)
    {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Always `extension:conformance`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:conformance';
    }

    /**
     * Describe static conformance for the command list.
     *
     * @return  string  One-line command summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_conformance.description';
    }

    /**
     * Print the complete conformance report and encode its verdict in the exit status.
     *
     * @param   list<string>  $arguments  Exactly one canonical absolute package path.
     * @param   Output        $output     Report and failure sink.
     *
     * @return  int  Zero only when every check passes, otherwise one.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if (count($arguments) !== 1 || $arguments[0] === '' || str_starts_with($arguments[0], '--')) {
                throw new InvalidArgumentException('Usage: extension:conformance /absolute/package.zip');
            }
            $report = $this->runner->run($arguments[0]);
            $output->line(CommandInput::render($report->toArray()));

            return $report->conforms() ? 0 : 1;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
