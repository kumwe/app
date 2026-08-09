<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

/**
 * Console entry point that withdraws an installed extension from the compiled runtime map.
 *
 * Disabling is the reversible half of activation and the first thing to reach for when an extension is
 * implicated in a broken site: the package stays installed and on disk, and only its registry record
 * moves to `Disabled`, which is what drops it from the next compiled runtime map — so
 * `extension:activate` brings it back with no reinstall. There is no surface option: the manager already
 * knows which surfaces a theme is activated on. A theme currently serving the administrator surface is
 * still out of reach from here, because taking it away needs the same step-up credential activating it
 * would, and the console passes none.
 *
 * @since  2.0.0
 */
final readonly class DisableExtensionCommand implements Command
{
    /**
     * Wire the command to the manager that disables and the authorizer that vouches for the operator.
     *
     * @param  ExtensionManager   $extensions     Application service that records the disabling under a
     *         registry lease.
     * @param  ConsoleAuthorizer  $authorization  Resolves the token-file options into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionManager $extensions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to invoke disabling.
     *
     * @return  string  Always `extension:disable`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:disable';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary of what disabling withdraws.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Disable an extension and remove it from the runtime map.';
    }

    /**
     * Disable the extension named by the first argument and confirm the identifier the manager reported.
     *
     * Authorization requires `extensions.manage`. Every failure — a bad option, an unauthorized token,
     * a manager error — is written to the output as a message and reported as exit status 1 rather than
     * surfacing as an uncaught exception.
     *
     * @param   list<string>  $arguments  Extension identifier first, then the `--site` and `--token-file`
     *          options in `--name=value` form.
     * @param   Output        $output     Sink for the confirmation line, or for the failure message.
     *
     * @return  int  0 when the extension was disabled, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $options = CommandInput::options(array_slice($arguments, 1));
            $context = $this->authorization->require($options, 'extensions.manage');
            $extension = $this->extensions->disable($identifier, $context);
            $installedIdentifier = $extension['identifier'] ?? $identifier;

            if (!is_string($installedIdentifier)) {
                throw new \RuntimeException('The extension manager returned an invalid identifier.');
            }

            $output->line(sprintf('Disabled %s.', $installedIdentifier));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
