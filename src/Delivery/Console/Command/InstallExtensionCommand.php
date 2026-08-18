<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionSummary;
use Throwable;

/**
 * Console command that verifies an extension archive and records it in the registry.
 *
 * Installation is the one extension step that reads a file from the host, which is why it is driven
 * from a shell rather than the browser: the operator names an absolute ZIP path and, where the
 * installation demands signed packages, the key identifier and detached signature to check it
 * against. A first install lands disabled and contributes nothing to the compiled runtime map until
 * `extension:activate` is run, so installing and enabling stay separately authorized decisions; an
 * in-place upgrade of an already-active extension keeps the status it had, which is why the success
 * line prints the status the registry actually recorded rather than assuming one. Every failure is
 * reduced to one message on stderr and exit status `1`, so a deployment script can branch on the
 * status rather than parse output.
 *
 * @since  2.0.0
 */
final readonly class InstallExtensionCommand implements Command
{
    /**
     * Wire the installer and the gate that turns console options into an authorized actor.
     *
     * @param  ExtensionManager   $extensions     Application service that verifies the archive, holds
     *         the registry lease and writes the registry row.
     * @param  ConsoleAuthorizer  $authorization  Turns `--site` and `--token-file` into an execution
     *         context carrying `extensions.manage`.
     *
     * @since  2.0.0
     */
    public function __construct(private ExtensionManager $extensions, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `extension:install`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'extension:install';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command installs and in which state.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.extension_install.description';
    }

    /**
     * Install one package and print the identifier, version and status it was recorded under.
     *
     * The success line is followed by one line per declared contribution — read from the same
     * summary the Extensions screen shows — naming where each screen, listener, theme, or record
     * type will surface, so the operator learns where to look without opening the administrator.
     *
     * The archive path is positional and is rejected when it is empty or looks like an option, so a
     * forgotten path cannot be silently read as a flag and installed as nothing. Everything after it
     * is `--name=value`: `--site` and `--token-file` are consumed by the authorizer, and the optional
     * `--key-id` and `--signature` pair carries the detached signature the archive is verified
     * against. Every failure — malformed usage, refused capability, rejected signature, a lease held
     * by another process — is caught here and reported as a message rather than a stack trace.
     *
     * @param   list<string>  $arguments  Absolute archive path first, then `--name=value` options.
     * @param   Output        $output     Sink for the success line, or for the failure message.
     *
     * @return  int  `0` once the package is recorded, `1` when any step refused or failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $archive = $arguments[0] ?? '';

            if ($archive === '' || str_starts_with($archive, '--')) {
                throw new InvalidArgumentException(
                    'Usage: extension:install /absolute/package.zip [--key-id=ID --signature=BASE64]',
                );
            }

            $options = $this->options(array_slice($arguments, 1));
            $context = $this->authorization->require($options, 'extensions.manage');
            $installed = $this->extensions->install(
                $archive,
                $context,
                $options['key-id'] ?? null,
                $options['signature'] ?? null,
            );
            $identifier = $this->resultString($installed, 'identifier');
            $version = $this->resultString($installed, 'installed_version');
            $status = $this->resultString($installed, 'status');
            $output->message('core.console.extension_install.installed', [
                'identifier' => $identifier,
                'version' => $version,
                'status' => $status,
            ]);
            foreach ($this->extensions->installed($context) as $row) {
                if (($row['identifier'] ?? null) !== $identifier) {
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

    /**
     * Parse the arguments that follow the archive path into a name-to-value map.
     *
     * This is deliberately stricter than `CommandInput::options()`, which the sibling commands use:
     * a value is mandatory, so `--signature=` cannot pass for a signature the operator believed they
     * supplied, and an unparseable argument fails the whole install instead of being ignored.
     *
     * @param   list<string>  $arguments  Arguments after the archive path, each spelled `--name=value`.
     *
     * @return  array<string, string>  Values keyed by option name, without the leading dashes.
     *
     * @throws  InvalidArgumentException  When an argument is not a `--name=value` pair with a
     *          non-empty value.
     *
     * @since   2.0.0
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Extension options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /**
     * Read one required string field out of the installer's result row.
     *
     * The manager reports the outcome as a loosely typed row, so each field the success line quotes
     * is checked rather than coerced: a missing or blank field means the install did not report what
     * it claims to have done, and printing a half-empty confirmation would be worse than failing.
     *
     * @param   array<string, mixed>  $result  Row returned by `ExtensionManager::install()`.
     * @param   string                $field   Key to read, such as `identifier` or `installed_version`.
     *
     * @return  string  The field's non-empty string value.
     *
     * @throws  InvalidArgumentException  When the field is absent, not a string, or empty.
     *
     * @since   2.0.0
     */
    private function resultString(array $result, string $field): string
    {
        $value = $result[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The installed extension %s is invalid.', $field));
        }

        return $value;
    }
}
