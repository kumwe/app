<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

/**
 * Console command that removes an extension's registry entry and the files it deployed.
 *
 * `extension:uninstall` is the token-authenticated console route to the same `ExtensionManager` the
 * administrator application and the HTTP API drive, so removal takes the registry lease, retires the
 * runtime tree under the retention rule that keeps older replicas pointing at code that still exists,
 * and stages the next runtime publication exactly as it would from the back office. Authority is a
 * site-bound bearer token read from a protected file rather than shell access alone, so a caller whose
 * token lacks `extensions.manage` is refused here as everywhere else. Restart workers and schedulers
 * once it succeeds.
 *
 * @since  2.0.0
 */
final readonly class UninstallExtensionCommand implements Command
{
    /**
     * Wire the extension manager and the console token authorizer.
     *
     * @param  ExtensionManager   $extensions     Performs the registry removal and retires the files.
     * @param  ConsoleAuthorizer  $authorization  Verifies the token file and its `extensions.manage` reach.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionManager $extensions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `extension:uninstall`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:uninstall';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command removes.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_uninstall.description';
    }

    /**
     * Authorize the caller from its token file, then uninstall the named extension.
     *
     * The identifier is the first positional argument and everything after it is parsed as options, of
     * which the authorizer requires `--site` and `--token-file`. No step-up credential is passed, so
     * removing the template currently activated on the administrator surface is refused here on
     * purpose and has to be done from the administrator application. A missing identifier reaches the
     * manager as an empty string and is rejected there rather than in this method.
     *
     * @param   list<string>  $arguments  `vendor/name`, then `--site=` and `--token-file=` options.
     * @param   Output        $output     Sink for the confirmation line, or the failure on stderr.
     *
     * @return  int  `0` when the extension is gone, `1` when parsing, authorization or removal failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $options = CommandInput::options(array_slice($arguments, 1));
            $context = $this->authorization->require($options, 'extensions.manage');
            $this->extensions->uninstall($identifier, $context);
            $output->message('core.console.extension_uninstall.uninstalled', ['identifier' => $identifier]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
