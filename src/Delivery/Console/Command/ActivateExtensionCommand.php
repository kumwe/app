<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Contribution\ExtensionContributionSummary;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Throwable;

/**
 * Console entry point that activates an installed extension, or a site theme.
 *
 * Only an active extension is admitted to the compiled runtime map, so this is the command an operator
 * runs after `extension:install` and after an upgrade has returned a record to `Disabled`. It moves the
 * registry record and bumps its version; publishing the new runtime is `extension:runtime:materialize`
 * and the watcher's job, not this command's. The administrator theme surface is deliberately out of
 * reach here: replacing it can lock every operator out of the back office, so it demands step-up
 * authentication in the administrator application and is refused with an explanation rather than
 * silently ignored.
 *
 * @since  2.0.0
 */
final readonly class ActivateExtensionCommand implements Command
{
    /**
     * Wire the command to the manager that activates and the authorizer that vouches for the operator.
     *
     * @param  ExtensionManager   $extensions     Application service that records the activation under a
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
     * Name the operator types to invoke activation.
     *
     * @return  string  Always `extension:activate`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:activate';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the `--surface=site` option.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_activate.description';
    }

    /**
     * Activate the extension named by the first argument and confirm the identifier the manager reported.
     *
     * The confirmation line is followed by one line per declared contribution — read from the same
     * summary the Extensions screen shows — naming where each screen, listener, theme, or record
     * type now surfaces, so activating from a shell still tells the operator what they got.
     *
     * Authorization requires `extensions.manage` and runs before the surface is acted on. Nothing is
     * allowed to escape: every failure — a bad option, an unauthorized token, a refused administrator
     * surface, a manager error — is written to the output as a message and reported as exit status 1,
     * so the console never prints a stack trace at an operator.
     *
     * @param   list<string>  $arguments  Extension identifier first, then `--name=value` options:
     *          `--site` and `--token-file`, plus an optional `--surface`.
     * @param   Output        $output     Sink for the confirmation line, or for the failure message.
     *
     * @return  int  0 when the extension was activated, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $options = CommandInput::options(array_slice($arguments, 1));
            $surface = ThemeSurface::optional($options['surface'] ?? null);
            $context = $this->authorization->require($options, 'extensions.manage');

            if ($surface === ThemeSurface::Administrator) {
                throw new \InvalidArgumentException(
                    'Administrator themes require step-up authentication in the administrator application.',
                );
            }

            $extension = $this->extensions->activate(
                $identifier,
                $context,
                $surface,
            );
            $installedIdentifier = $extension['identifier'] ?? $identifier;

            if (!is_string($installedIdentifier)) {
                throw new \RuntimeException('The extension manager returned an invalid identifier.');
            }

            $output->message('core.console.extension_activate.activated', [
                'installedIdentifier' => $installedIdentifier,
            ]);
            foreach ($this->extensions->installed($context) as $row) {
                if (($row['identifier'] ?? null) !== $installedIdentifier) {
                    continue;
                }
                foreach (ExtensionContributionSummary::linesForRow($row) as $line) {
                    $output->line('  ' . $line);
                }
                break;
            }

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
