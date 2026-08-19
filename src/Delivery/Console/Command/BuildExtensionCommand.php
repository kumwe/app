<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Development\DeterministicPackageBuilder;
use Throwable;

/**
 * Builds an install-safe deterministic extension ZIP.
 *
 * @since  2.0.0
 */
final readonly class BuildExtensionCommand implements Command
{
    /**
     * Bind the command to the reproducible package builder.
     *
     * @param  DeterministicPackageBuilder  $builder  Reproducible package builder.
     *
     * @since  2.0.0
     */
    public function __construct(private DeterministicPackageBuilder $builder)
    {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Always `extension:build`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:build';
    }

    /**
     * Describe deterministic package construction for the command list.
     *
     * @return  string  One-line command summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_build.description';
    }

    /**
     * Build the positional source directory into the required output path.
     *
     * @param   list<string>  $arguments  Absolute source path followed by `--output=PATH`.
     * @param   Output        $output     Result and failure sink.
     *
     * @return  int  Zero after publication, one after any refused or failed step.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $source = $arguments[0] ?? '';
            if ($source === '' || str_starts_with($source, '--')) {
                throw new InvalidArgumentException(
                    'Usage: extension:build /absolute/source --output=/absolute/package.zip',
                );
            }
            $options = CommandInput::options(array_slice($arguments, 1));
            $result = $this->builder->build($source, CommandInput::required($options, 'output'));
            $output->line(CommandInput::render($result->toArray()));

            return 0;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
