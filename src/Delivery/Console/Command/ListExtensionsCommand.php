<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Application\ExtensionManager;

/**
 * Console command that prints the installed extension set as a fixed-width table.
 *
 * This is the read-only companion to the install, activate, disable and uninstall commands, and it
 * reads the same registry the administrator screens do, so an operator can confirm from a shell what
 * the installation carries and which of those extensions the compiled runtime map will pick up.
 * Unlike its sibling commands it traps nothing: a malformed option, a token file that is not
 * owner-only readable, or a token without `extensions.manage` leaves the command as the exception
 * itself rather than as a printed line, because a listing that quietly returns nothing would be
 * indistinguishable from a site with no extensions.
 *
 * @since  2.0.0
 */
final readonly class ListExtensionsCommand implements Command
{
    /**
     * Wire the registry reader and the gate that turns console options into an authorized actor.
     *
     * @param  ExtensionManager   $extensions     Application service the registry rows are read from.
     * @param  ConsoleAuthorizer  $authorization  Turns `--site` and `--token-file` into an execution
     *         context carrying `extensions.manage`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionManager $extensions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `extension:list`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:list';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the listing shows.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_list.description';
    }

    /**
     * Print one padded line per installed extension.
     *
     * Columns are left-aligned to fixed widths — identifier, type, installed version, status — so the
     * output stays readable in a terminal and predictable for `awk` or `grep`, and the registry orders
     * rows by identifier, which makes two runs diffable. `--site` is required to authenticate the
     * token, not to narrow the result: extensions are installed for the whole installation, so the
     * listing is the same from every site and the status column is what says who is running what.
     *
     * @param   list<string>  $arguments  `--name=value` options; `--site` and `--token-file` are both
     *          required.
     * @param   Output        $output     Sink the padded table lines are written to.
     *
     * @return  int  Always `0`; every failure leaves through an exception instead.
     *
     * @throws  \InvalidArgumentException  When an argument is not a `--name=value` pair, a required
     *          option is missing, or the token file is not an absolute, non-symlinked, mode-0600 file.
     * @throws  \Kumwe\App\Identity\Application\Authorization\InsufficientCapability  When the verified
     *          token does not carry `extensions.manage`.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $context = $this->authorization->require(CommandInput::options($arguments), 'extensions.manage');
        foreach ($this->extensions->installed($context) as $extension) {
            $output->line(sprintf(
                '%-40s %-12s %-12s %s',
                $this->value($extension, 'identifier'),
                $this->value($extension, 'extension_type'),
                $this->value($extension, 'installed_version'),
                $this->value($extension, 'status'),
            ));
        }

        return 0;
    }

    /**
     * Read one column out of a registry row for display.
     *
     * Registry rows are loosely typed, and a listing must not abort because a single column is null
     * or absent, so anything that is not a string renders as an empty cell and the remaining columns
     * still reach the operator.
     *
     * @param   array<string, mixed>  $extension  One row as returned by `ExtensionManager::installed()`.
     * @param   string                $field      Column to read, such as `identifier` or `status`.
     *
     * @return  string  The column value, or an empty string when it is absent or not a string.
     *
     * @since   2.0.0
     */
    private function value(array $extension, string $field): string
    {
        $value = $extension[$field] ?? null;

        return is_string($value) ? $value : '';
    }
}
