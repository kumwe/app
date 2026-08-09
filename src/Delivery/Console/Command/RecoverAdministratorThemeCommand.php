<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Presentation\Application\AdministratorThemeRecovery;
use Throwable;

/**
 * Break-glass console command that restores the protected built-in administrator theme.
 *
 * An administrator theme that fails to render locks every operator out of the back office, which is
 * the only surface where the activation would normally be reversed. This command is the way out, and
 * it lives on the console precisely so the escape hatch is not reachable through the thing that broke:
 * it takes no bearer token and is never exposed over HTTP, so the authority to use it is shell access
 * to the application account. The literal confirmation argument is what keeps a stray invocation from
 * silently resetting a working site's administrator theme.
 *
 * @since  2.0.0
 */
final readonly class RecoverAdministratorThemeCommand implements Command
{
    /**
     * Wire the recovery port this command exists to drive.
     *
     * @param  AdministratorThemeRecovery  $recovery  Clears the administrator theme activation atomically.
     *
     * @since  2.0.0
     */
    public function __construct(private AdministratorThemeRecovery $recovery)
    {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `theme:administrator:recover`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'theme:administrator:recover';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command restores.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Atomically restore the protected built-in administrator theme.';
    }

    /**
     * Clear the administrator theme activation once the exact confirmation argument is present.
     *
     * The argument vector has to be exactly the confirmation flag and nothing else, so a spelling that
     * merely contains it, or the flag with a stray extra argument beside it, is refused before the
     * recovery port is touched. Any failure is reported on stderr rather than raised, because the
     * operator running this is already dealing with a broken surface.
     *
     * @param   list<string>  $arguments  Must be exactly `['--confirm=restore-core-administrator']`.
     * @param   Output        $output     Sink for the confirmation line, or the failure on stderr.
     *
     * @return  int  `0` once the built-in theme renders again, `1` when unconfirmed or recovery failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if ($arguments !== ['--confirm=restore-core-administrator']) {
                throw new InvalidArgumentException(
                    'Recovery requires --confirm=restore-core-administrator.',
                );
            }

            $this->recovery->recover();
            $output->line('Restored the protected built-in administrator theme.');

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
