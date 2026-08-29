<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Contribution\ExtensionContributionSummary;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Package\PackageChecksum;
use Psr\Clock\ClockInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Installs the shipped example extensions into a demonstration through the real extension pipeline.
 *
 * Nothing here bypasses the trust model: each selected example directory is packaged into a
 * deterministic archive, signed with a freshly generated single-use key that is registered in the
 * trust store scoped to exactly that package, and then installed and activated through the same
 * manager every operator upload passes — so the demonstration exercises signing, trust, install,
 * and activation instead of sidestepping them. The ephemeral secret key is discarded after signing;
 * a later reinstall simply mints another. Installation is idempotent per example: an identifier the
 * registry already lists as enabled is confirmed rather than reinstalled, and a disabled one is
 * reactivated without repackaging.
 *
 * @since  2.0.0
 */
final readonly class DemoExampleExtensionInstaller
{
    /**
     * Repository directory holding one packagable example per subdirectory.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string EXAMPLES_DIRECTORY = 'examples/extensions';

    /**
     * Bind the installer to the repository root and the canonical extension services.
     *
     * @param  string            $root        Absolute repository root the examples are read from.
     * @param  ExtensionManager  $extensions  Canonical install and activation pipeline.
     * @param  TrustStore        $trust       Signing-key registry the ephemeral keys are added to.
     * @param  ClockInterface    $clock       Source of the ephemeral key validity window.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $root,
        private ExtensionManager $extensions,
        private TrustStore $trust,
        private ClockInterface $clock,
    ) {
        if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The example extension root must be absolute.');
        }
    }

    /**
     * Name every packagable example shipped with this release, in stable alphabetical order.
     *
     * @return  list<string>  Example directory names carrying a `kumwe.json` manifest.
     *
     * @since   2.0.0
     */
    public function available(): array
    {
        $paths = glob(sprintf('%s/%s/*/kumwe.json', $this->root, self::EXAMPLES_DIRECTORY));
        $names = [];
        foreach ($paths === false ? [] : $paths as $path) {
            $names[] = basename(dirname($path));
        }
        sort($names);

        return $names;
    }

    /**
     * Install and activate one shipped example, or confirm it where it is already active.
     *
     * Whichever path is taken, the result carries the extension's contribution lines — the same
     * summary the Extensions screen shows — so the console can say where the freshly installed
     * example actually surfaces instead of only that it installed.
     *
     * @param   ExecutionContext  $context  Authenticated administrator the install runs as.
     * @param   string            $example  Example directory name from `available()`.
     *
     * @return  array{identifier: string, installed: bool, activated: bool, contributions: list<string>}
     *          The extension identifier, whether this call installed the package, whether this call
     *          activated it, and one factual line per contribution naming where it surfaces.
     *
     * @throws  InvalidArgumentException  When the example name is unknown to this release.
     * @throws  RuntimeException  When packaging fails or the manifest carries no usable identifier.
     *
     * @since   2.0.0
     */
    public function install(ExecutionContext $context, string $example): array
    {
        if (!in_array($example, $this->available(), true)) {
            throw new InvalidArgumentException(sprintf('The %s example is not shipped.', $example));
        }
        $directory = sprintf('%s/%s/%s', $this->root, self::EXAMPLES_DIRECTORY, $example);
        [$identifier, $type] = $this->manifestIdentity($directory);
        $activatable = $type !== 'template';

        $status = null;
        $contributions = [];
        foreach ($this->extensions->installed($context) as $row) {
            if (($row['identifier'] ?? null) === $identifier) {
                $status = is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
                $contributions = ExtensionContributionSummary::linesForRow($row);
                break;
            }
        }
        if ($status === 'active' || ($status !== null && !$activatable)) {
            return [
                'identifier' => $identifier,
                'installed' => false,
                'activated' => false,
                'contributions' => $contributions,
            ];
        }
        if ($status !== null) {
            $this->extensions->activate($identifier, $context);

            return [
                'identifier' => $identifier,
                'installed' => false,
                'activated' => true,
                'contributions' => $this->contributionLines($context, $identifier),
            ];
        }

        $archive = $this->package($directory, $example);
        try {
            $bytes = file_get_contents($archive);
            if (!is_string($bytes)) {
                throw new RuntimeException('The packaged example could not be read back for signing.');
            }
            $keyPair = sodium_crypto_sign_keypair();
            $keyId = sprintf('demo.%s.%s', $example, bin2hex(random_bytes(4)));
            $this->trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'kumwe',
                sprintf('%s-example', $example),
                $this->clock->now()->modify('+1 year'),
            );
            $signature = sodium_crypto_sign_detached(
                (string) PackageChecksum::calculate($bytes),
                sodium_crypto_sign_secretkey($keyPair),
            );
            $this->extensions->install($archive, $context, $keyId, base64_encode($signature));
            if ($activatable) {
                $this->extensions->activate($identifier, $context);
            }
        } finally {
            @unlink($archive);
        }

        return [
            'identifier' => $identifier,
            'installed' => true,
            'activated' => $activatable,
            'contributions' => $this->contributionLines($context, $identifier),
        ];
    }

    /**
     * Re-read one identifier's contribution lines after its registry status changed.
     *
     * The manager stamps activation state onto every summary entry, so lines read before an
     * activation would still say inactive; listing again after the change keeps the console's
     * report consistent with what the Extensions screen now shows.
     *
     * @param   ExecutionContext  $context     Authenticated administrator the listing runs as.
     * @param   string            $identifier  `vendor/name` identifier to look up.
     *
     * @return  list<string>  One factual line per contribution; empty when the row is not visible.
     *
     * @since   2.0.0
     */
    private function contributionLines(ExecutionContext $context, string $identifier): array
    {
        foreach ($this->extensions->installed($context) as $row) {
            if (($row['identifier'] ?? null) === $identifier) {
                return ExtensionContributionSummary::linesForRow($row);
            }
        }

        return [];
    }

    /**
     * Read the `vendor/name` identifier and package type out of an example's manifest.
     *
     * The type decides the activation posture: a `template` example installs so it becomes
     * selectable, but activating it onto the site surface stays an operator decision because it
     * would restyle the whole public site.
     *
     * @param   string  $directory  Absolute example directory holding `kumwe.json`.
     *
     * @return  array{string, string}  The manifest's declared identifier and its package type.
     *
     * @throws  RuntimeException  When the manifest is unreadable or declares no identifier.
     *
     * @since   2.0.0
     */
    private function manifestIdentity(string $directory): array
    {
        $raw = @file_get_contents($directory . '/kumwe.json');
        if (!is_string($raw)) {
            throw new RuntimeException('The example manifest could not be read.');
        }
        $manifest = json_decode($raw, true);
        $name = is_array($manifest) ? ($manifest['name'] ?? null) : null;
        if (!is_string($name) || preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#D', $name) !== 1) {
            throw new RuntimeException('The example manifest declares no usable identifier.');
        }
        $type = is_array($manifest) && is_string($manifest['type'] ?? null) ? $manifest['type'] : 'component';

        return [$name, $type];
    }

    /**
     * Package one example directory into a temporary extension archive.
     *
     * @param   string  $directory  Absolute example directory to package.
     * @param   string  $example    Example name, used only to label the temporary file.
     *
     * @return  string  Path of the temporary archive; the caller removes it.
     *
     * @throws  RuntimeException  When the archive cannot be created or a file cannot be added.
     *
     * @since   2.0.0
     */
    private function package(string $directory, string $example): string
    {
        $archive = tempnam(sys_get_temp_dir(), sprintf('kumwe-demo-%s-', $example));
        if (!is_string($archive)) {
            throw new RuntimeException('The example package could not be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The example package could not be opened.');
        }
        try {
            $files = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[substr($file->getPathname(), strlen($directory) + 1)] = $file->getPathname();
                }
            }
            ksort($files);
            foreach ($files as $relative => $absolute) {
                $contents = file_get_contents($absolute);
                if (!is_string($contents) || !$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('The example package could not include a file.');
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }
}
