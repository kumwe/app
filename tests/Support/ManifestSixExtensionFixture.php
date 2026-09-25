<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use FilesystemIterator;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Packages the extension SDK's manifest-six compatibility fixture under a caller-owned identity.
 *
 * The committed fixture contributes a signed Studio grid block and its preview renderer. Integration tests
 * install it under a unique identifier and PHP namespace so parallel and repeated runs never collide, sign
 * the archive with a per-test key, and drive its real install, activation, upgrade and disable lifecycle.
 *
 * @since  2.0.0
 */
final class ManifestSixExtensionFixture
{
    /**
     * Build one uniquely owned package from the committed manifest-six fixture.
     *
     * @param   string  $identifier      Per-test extension identifier, `vendor/name`.
     * @param   string  $version         Exact package runtime version.
     * @param   bool    $missingService  Whether the bound preview service deliberately resolves outside the
     *          SDK renderer contract.
     *
     * @return  string  Absolute archive path; the caller removes it.
     *
     * @throws  RuntimeException  When the fixture cannot be read, re-owned or packaged.
     *
     * @since   2.0.0
     */
    public static function package(string $identifier, string $version, bool $missingService = false): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-studio-preview-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The Studio preview fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Studio preview fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 2) . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6';
        $dotted = str_replace('/', '.', $identifier);
        $phpNamespace = 'IntegrationStudioPreview\\R' . substr(hash('sha256', $identifier), 0, 12);
        $jsonNamespace = str_replace('\\', '\\\\', $phpNamespace);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        try {
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $contents = self::reowned(
                    $relative,
                    self::read($file->getPathname()),
                    $identifier,
                    $dotted,
                    $phpNamespace,
                    $jsonNamespace,
                    $version,
                );
                if ($missingService && $relative === 'src/Provider.php') {
                    $contents = str_replace(
                        ': GridPreviewRenderer => new GridPreviewRenderer(),',
                        ': object => new \stdClass(),',
                        $contents,
                    );
                }
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('A Studio preview fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Sign one archive checksum with a fixture Ed25519 secret key.
     *
     * @param   string  $archive    Absolute package archive path.
     * @param   string  $secretKey  Sodium Ed25519 secret key bytes.
     *
     * @return  string  Base64 detached signature.
     *
     * @throws  RuntimeException  When the key is empty or the archive cannot be read.
     *
     * @since   2.0.0
     */
    public static function signature(string $archive, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new RuntimeException('The Studio preview fixture signing key is unavailable.');
        }

        return base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum(PackageChecksum::calculate(self::read($archive))),
            $secretKey,
        ));
    }

    /**
     * Re-own one fixture file under the caller's identifier, namespace and version.
     *
     * @param   string  $relative       Path inside the fixture.
     * @param   string  $contents       Original file bytes.
     * @param   string  $identifier     Per-test extension identifier.
     * @param   string  $dotted         Identifier in dotted contribution form.
     * @param   string  $phpNamespace   Per-test PHP namespace.
     * @param   string  $jsonNamespace  The same namespace escaped for JSON.
     * @param   string  $version        Exact package runtime version.
     *
     * @return  string  Re-owned file bytes.
     *
     * @throws  RuntimeException  When the definitions or the manifest cannot be rewritten.
     *
     * @since   2.0.0
     */
    private static function reowned(
        string $relative,
        string $contents,
        string $identifier,
        string $dotted,
        string $phpNamespace,
        string $jsonNamespace,
        string $version,
    ): string {
        if ($relative === 'src/Definitions.php') {
            // Canonical constants are source-wrapped across adjacent literals. Join them before re-owning so a
            // namespace split at a line boundary cannot survive inside signed bytes.
            $joined = preg_replace("/'\\s*\\.\\s*'/", '', $contents);
            if (!is_string($joined)) {
                throw new RuntimeException('The Studio preview fixture definitions cannot be joined.');
            }
            $contents = $joined;
        }
        $contents = str_replace(
            [
                'KumweContract\\\\ManifestSix',
                'KumweContract\\ManifestSix',
                'kumwe/contract-manifest-six',
                'kumwe.contract-manifest-six',
            ],
            [$jsonNamespace, $phpNamespace, $identifier, $dotted],
            $contents,
        );
        if (
            $relative === 'src/Definitions.php'
            && (str_contains($contents, 'kumwe.contract-manifest-six')
                || str_contains($contents, 'kumwe/contract-manifest-six'))
        ) {
            throw new RuntimeException('The Studio preview fixture definitions kept the committed owner.');
        }
        if ($relative !== 'kumwe.json') {
            return $contents;
        }
        $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        $requirements = is_array($manifest) ? ($manifest['requires'] ?? null) : null;
        if (!is_array($manifest) || !is_array($requirements)) {
            throw new RuntimeException('The Studio preview fixture manifest is invalid.');
        }
        $manifest['version'] = $version;
        $requirements['php'] = '^8.3.0';
        $manifest['requires'] = $requirements;

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Read one file the fixture needs.
     *
     * @param   string  $path  Absolute path.
     *
     * @return  string  File bytes.
     *
     * @throws  RuntimeException  When the file cannot be read.
     *
     * @since   2.0.0
     */
    private static function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('A Studio preview fixture file cannot be read.');
        }

        return $bytes;
    }
}
