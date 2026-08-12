<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\CMS\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Throwable;

/**
 * Console entry point that exports the running system into an installable demo-profile package.
 *
 * Export is deliberately a host-only operation: it exists on the console alone — never on the HTTP API
 * or the MCP surface — and it still demands a real administrator whose password arrives in a protected
 * file, so producing a portable copy of the site requires both host access and administrator standing.
 * The package mirrors the repository's `resources/demo` layout, is validated through the same catalog
 * that guards release manifests before the command reports success, and ships an `export.json` integrity
 * index of canonical checksums so recipients can verify what they received. Profiles never carry
 * credential material; accounts on a target installation are provisioned separately with freshly
 * generated passwords through `demo:provision-access`.
 *
 * @since  2.0.0
 */
final readonly class DemoExportCommand implements Command
{
    /**
     * Wire the export pipeline to configuration, authentication, and the manifest projector.
     *
     * @param  ApplicationConfiguration      $configuration  Validated process profile selectors.
     * @param  AdministratorIdentityGateway  $identities     Password verifier for the acting administrator.
     * @param  DemoProfileExporter           $exporter       Live-system-to-manifest projector.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private AdministratorIdentityGateway $identities,
        private DemoProfileExporter $exporter,
    ) {
    }

    /**
     * Name the operators type to export the running system.
     *
     * @return  string  Always `demo:export-profile`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'demo:export-profile';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the package output.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'Export the running site into an installable demo-profile package with integrity checksums.';
    }

    /**
     * Export the site-content dataset into a validated package below the requested directory.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--admin-email`,
     *          `--admin-password-file`, `--profile`, and `--output`.
     * @param   Output        $output     Sink for the export summary or the failure message.
     *
     * @return  int  0 when the package was written and re-validated, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $profile = $this->required($options, 'profile');
            $directory = $this->required($options, 'output');
            $password = $this->passwordFromFile($this->required($options, 'admin-password-file'));
            $principal = $this->identities->authenticate(
                $this->required($options, 'admin-email'),
                $password,
                'demo-profile-export',
            );
            if ($principal === null) {
                throw new InvalidArgumentException('The administrator could not be authenticated.');
            }
            $context = $principal->context(
                SiteContext::fromString($this->configuration->publicSite),
                AuthenticationStrength::Password,
                'demo-export-' . bin2hex(random_bytes(16)),
            );
            $manifest = $this->exporter->contentManifest($context, $profile);
            $checksums = $this->exporter->writePackage($directory, $profile, [
                sprintf('content/%s.json', $profile) => $manifest,
            ]);
            $verified = new FilesystemDemoManifestCatalog($directory)->content($profile);
            $content = $manifest['content'];
            $menus = $manifest['menus'];
            $output->line(sprintf(
                'Exported %d content entries and %d menus as profile %s.',
                is_array($content) ? count($content) : 0,
                is_array($menus) ? count($menus) : 0,
                $profile,
            ));
            foreach ($checksums as $relative => $checksum) {
                $output->line(sprintf('%s %s', $checksum, $relative));
            }
            $output->line(sprintf('Catalog re-validation checksum %s.', $verified['checksum']));
            $output->line(sprintf(
                'Copy %s/resources/demo over an installation\'s resources/demo to make it selectable.',
                $directory,
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Parse this command's argument list into an option map keyed by option name.
     *
     * @param   list<string>  $arguments  Arguments the console passed after the command name.
     *
     * @return  array<string, string>  Values keyed by option name without the leading `--`.
     *
     * @throws  InvalidArgumentException  When an argument is not a `--name=value` pair carrying a value.
     *
     * @since   2.0.0
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /**
     * Read an option the command cannot export without.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  string  The value with surrounding whitespace removed, never empty.
     *
     * @throws  InvalidArgumentException  When the option is absent or trims to an empty string.
     *
     * @since   2.0.0
     */
    private function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    /**
     * Read the acting administrator's password out of a file the operator has protected.
     *
     * The same protections as `demo:provision-access` apply: the file must be named by an absolute
     * path, be a readable regular file rather than a symlink, and carry no group or other permission
     * bits.
     *
     * @param   string  $path  Absolute filesystem path of the file holding the password.
     *
     * @return  string  The password with trailing carriage returns and newlines removed.
     *
     * @throws  InvalidArgumentException  When the path is relative, is a symlink, names no readable
     *          regular file, is readable or writable by group or others, or cannot be read.
     *
     * @since   2.0.0
     */
    private function passwordFromFile(string $path): string
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The password file must be an absolute, readable regular file.');
        }

        $permissions = fileperms($path);

        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new InvalidArgumentException(
                'The password file must not be readable or writable by group or others.',
            );
        }

        $password = file_get_contents($path);

        if (!is_string($password)) {
            throw new InvalidArgumentException('The password file could not be read.');
        }

        return rtrim($password, "\r\n");
    }
}
