<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Development\ComponentScaffolder;
use Kumwe\CMS\Extension\Development\ScaffoldRequest;
use Throwable;

/**
 * Creates a complete extension component source tree from the shipped scaffold.
 *
 * @since  2.0.0
 */
final readonly class ScaffoldExtensionCommand implements Command
{
    /**
     * Bind the command to atomic complete-component generation.
     *
     * @param  ComponentScaffolder  $scaffolder  Atomic source-tree generator.
     *
     * @since  2.0.0
     */
    public function __construct(private ComponentScaffolder $scaffolder)
    {
    }

    /**
     * Return the stable console dispatcher name.
     *
     * @return  string  Always `extension:scaffold`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:scaffold';
    }

    /**
     * Describe complete component generation for the command list.
     *
     * @return  string  One-line command summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Create a complete Kumwe component extension source tree.';
    }

    /**
     * Generate the requested component source tree and print its stable summary.
     *
     * @param   list<string>  $arguments  Identifier followed by namespace, target, label, and version options.
     * @param   Output        $output     Scaffold summary and failure sink.
     *
     * @return  int  Zero after atomic publication, one after any refused or failed step.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            if ($identifier === '' || str_starts_with($identifier, '--')) {
                throw new InvalidArgumentException('Usage: extension:scaffold vendor/name --namespace=Acme\\Name '
                    . '--target=/absolute/path --label="Name" [--version=1.0.0]');
            }
            $options = CommandInput::options(array_slice($arguments, 1));
            $request = new ScaffoldRequest(
                $identifier,
                CommandInput::required($options, 'namespace'),
                CommandInput::required($options, 'target'),
                CommandInput::required($options, 'label'),
                trim($options['version'] ?? '1.0.0'),
            );
            $output->line(CommandInput::render($this->scaffolder->scaffold($request)->toArray()));

            return 0;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
