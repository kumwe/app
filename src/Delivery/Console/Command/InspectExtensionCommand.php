<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Extension\Toolchain\PackageInspector;
use Throwable;

/**
 * Prints a code-free inventory of an extension package.
 *
 * @since  2.0.0
 */
final readonly class InspectExtensionCommand implements Command
{
    /**
     * Bind the command to production package inspection.
     *
     * @param  PackageInspector  $inspector  Production-safe package inspector.
     *
     * @since  2.0.0
     */
    public function __construct(private PackageInspector $inspector)
    {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Always `extension:inspect`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:inspect';
    }

    /**
     * Describe code-free inspection for the command list.
     *
     * @return  string  One-line command summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_inspect.description';
    }

    /**
     * Print the safe package inventory as stable JSON.
     *
     * @param   list<string>  $arguments  Exactly one canonical absolute package path.
     * @param   Output        $output     Inventory and failure sink.
     *
     * @return  int  Zero after inspection, one when usage or package validation fails.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if (count($arguments) !== 1 || $arguments[0] === '' || str_starts_with($arguments[0], '--')) {
                throw new InvalidArgumentException('Usage: extension:inspect /absolute/package.zip');
            }
            $output->line(CommandInput::render($this->inspector->inspect($arguments[0])->toArray()));

            return 0;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
