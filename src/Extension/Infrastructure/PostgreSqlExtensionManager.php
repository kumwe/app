<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Trust\UntrustedPackage;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionType;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumEd25519Verifier;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class PostgreSqlExtensionManager implements ExtensionManager
{
    public function __construct(
        private DatabaseInterface $database,
        private string $schema,
        private string $extensionRoot,
        private ArchiveReader $archives,
        private PackageSafetyPolicy $safety,
        private ExtensionRuntimeMapCompiler $compiler,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private bool $allowUnsignedLocalPackages,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function installed(): array
    {
        $query = $this->database->getQuery(true)
            ->select($this->quoteNames([
                'identifier',
                'extension_type',
                'installed_version',
                'status',
                'service_provider',
                'registry_version',
                'runtime_path',
                'installed_at',
                'updated_at',
            ]))
            ->from($this->quoteName($this->schema . '.extensions'))
            ->order($this->quoteName('identifier'));
        $rows = $this->database->setQuery($query)->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The extension registry query returned an invalid result set.');
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function install(
        string $archiveFile,
        string $actorId,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $resolvedArchive = realpath($archiveFile);

        if (!is_string($resolvedArchive) || !is_file($resolvedArchive) || is_link($resolvedArchive)) {
            throw new InvalidArgumentException('The extension archive path must identify a regular file.');
        }

        $package = $this->archives->inspect($resolvedArchive);
        $this->safety->assertSafe($package);
        $checksumValue = hash_file('sha256', $resolvedArchive);

        if (!is_string($checksumValue)) {
            throw new RuntimeException('The extension package checksum could not be calculated.');
        }

        $checksum = PackageChecksum::sha256($checksumValue);
        $manifestJson = $this->manifestJson($resolvedArchive);
        $manifest = ExtensionManifest::fromJson($manifestJson);
        $this->assertCompatible($manifest);
        $signature = $this->signature($signingKeyId, $base64Signature);
        $this->assertTrusted($checksum, $signature);
        $this->assertDependencies($manifest);

        $relativeRuntime = $this->runtimePath($manifest);
        $finalDirectory = $this->extensionRoot . '/' . $relativeRuntime;
        $stagingDirectory = $this->extensionRoot . '/.staging/' . Uuid::uuid7()->toString();
        $previous = $this->findInstalledOrNull($manifest->identifier()->value());

        if (file_exists($finalDirectory)) {
            throw new InvalidArgumentException('The requested extension version is already present on disk.');
        }

        $this->extract($resolvedArchive, $stagingDirectory);
        $this->assertProviderFileExists($manifest, $stagingDirectory);
        $moved = false;

        try {
            $this->ensureDirectory(dirname($finalDirectory));

            if (!rename($stagingDirectory, $finalDirectory)) {
                throw new RuntimeException('The staged extension could not be activated atomically.');
            }

            $moved = true;
            $result = $this->transactions->transactional(function () use (
                $manifest,
                $manifestJson,
                $checksum,
                $signature,
                $relativeRuntime,
                $actorId,
            ): array {
                $this->persistRelease($manifest, $manifestJson, $checksum, $signature, $relativeRuntime);
                $this->compiler->rebuild();
                $this->audit($actorId, 'extension.install', $manifest->identifier()->value(), [
                    'version' => (string) $manifest->version(),
                    'checksum' => (string) $checksum,
                ]);

                return $this->findInstalled($manifest->identifier()->value());
            });

            $previousRuntime = $previous['runtime_path'] ?? null;

            if (is_string($previousRuntime) && $previousRuntime !== $relativeRuntime) {
                $this->removeTree($this->extensionRoot . '/' . $previousRuntime);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($moved && is_dir($finalDirectory)) {
                $this->removeTree($finalDirectory);
            } elseif (is_dir($stagingDirectory)) {
                $this->removeTree($stagingDirectory);
            }

            try {
                $this->compiler->rebuild();
            } catch (Throwable) {
                // The original exception is authoritative; readiness will expose a stale map.
            }

            throw $exception;
        }
    }

    public function activate(string $identifier, string $actorId): array
    {
        ExtensionIdentifier::fromString($identifier);

        return $this->changeStatus($identifier, 'active', 'extension.activate', $actorId);
    }

    public function disable(string $identifier, string $actorId): array
    {
        ExtensionIdentifier::fromString($identifier);

        return $this->changeStatus($identifier, 'disabled', 'extension.disable', $actorId);
    }

    public function uninstall(string $identifier, string $actorId): void
    {
        ExtensionIdentifier::fromString($identifier);
        $installed = $this->findInstalled($identifier);
        $relativePath = $installed['runtime_path'] ?? null;

        if (!is_string($relativePath)) {
            throw new RuntimeException('The installed extension has no runtime path.');
        }

        $source = $this->extensionRoot . '/' . $relativePath;
        $trash = $this->extensionRoot . '/.trash/' . Uuid::uuid7()->toString();
        $this->ensureDirectory(dirname($trash));

        if (!rename($source, $trash)) {
            throw new RuntimeException('The extension files could not be moved into the removal staging area.');
        }

        try {
            $this->transactions->transactional(function () use ($identifier, $actorId): void {
                $boundIdentifier = $identifier;
                $query = $this->database->getQuery(true)
                    ->delete($this->quoteName($this->schema . '.extensions'))
                    ->where($this->quoteName('identifier') . ' = :identifier')
                    ->bind(':identifier', $boundIdentifier, ParameterType::STRING);
                $this->database->setQuery($query)->execute();
                $this->compiler->rebuild();
                $this->audit($actorId, 'extension.uninstall', $identifier);
            });
            $this->removeTree($trash);
        } catch (Throwable $exception) {
            rename($trash, $source);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function changeStatus(string $identifier, string $status, string $action, string $actorId): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->transactional(function () use (
            $identifier,
            $status,
            $action,
            $actorId,
        ): array {
            $installed = $this->findInstalled($identifier);

            if ($status === 'active' && ($installed['extension_type'] ?? null) === ExtensionType::Template->value) {
                $this->disableTemplatesExcept($identifier);
            }

            $boundStatus = $status;
            $boundIdentifier = $identifier;
            $query = $this->database->getQuery(true)
                ->update($this->quoteName($this->schema . '.extensions'))
                ->set($this->quoteName('status') . ' = :status')
                ->set($this->quoteName('registry_version') . ' = ' . $this->quoteName('registry_version') . ' + 1')
                ->set($this->quoteName('updated_at') . ' = CURRENT_TIMESTAMP')
                ->where($this->quoteName('identifier') . ' = :identifier')
                ->bind(':status', $boundStatus, ParameterType::STRING)
                ->bind(':identifier', $boundIdentifier, ParameterType::STRING);
            $this->database->setQuery($query)->execute();

            if ($this->database->getAffectedRows() !== 1) {
                throw new InvalidArgumentException('The requested extension is not installed.');
            }

            $this->compiler->rebuild();
            $this->audit($actorId, $action, $identifier);

            return $this->findInstalled($identifier);
        });

        return $result;
    }

    private function persistRelease(
        ExtensionManifest $manifest,
        string $manifestJson,
        PackageChecksum $checksum,
        ?PackageSignature $signature,
        string $relativeRuntime,
    ): void {
        $identifier = $manifest->identifier()->value();
        $existing = $this->findInstalledOrNull($identifier);
        $extensionId = is_array($existing) && is_string($existing['id'] ?? null)
            ? $existing['id']
            : Uuid::uuid7()->toString();

        if ($manifest->type() === ExtensionType::Template) {
            $this->disableTemplatesExcept($identifier);
        }

        if ($existing === null) {
            $boundExtensionId = $extensionId;
            $boundIdentifier = $identifier;
            $type = $manifest->type()->value;
            $version = (string) $manifest->version();
            $status = 'active';
            $provider = $manifest->serviceProvider();
            $boundRuntimePath = $relativeRuntime;
            $query = $this->database->getQuery(true)
                ->insert($this->quoteName($this->schema . '.extensions'))
                ->columns($this->quoteNames([
                    'id', 'identifier', 'extension_type', 'installed_version', 'status', 'service_provider',
                    'registry_version', 'runtime_path', 'installed_at', 'updated_at',
                ]))
                ->values(
                    ':id, :identifier, :type, :version, :status, :provider, 1, :runtime_path, '
                    . 'CURRENT_TIMESTAMP, CURRENT_TIMESTAMP',
                )
                ->bind(':id', $boundExtensionId, ParameterType::STRING)
                ->bind(':identifier', $boundIdentifier, ParameterType::STRING)
                ->bind(':type', $type, ParameterType::STRING)
                ->bind(':version', $version, ParameterType::STRING)
                ->bind(':status', $status, ParameterType::STRING)
                ->bind(':provider', $provider, ParameterType::STRING)
                ->bind(':runtime_path', $boundRuntimePath, ParameterType::STRING);
        } else {
            $installedVersion = SemanticVersion::fromString($this->requiredString($existing, 'installed_version'));

            if ($manifest->version()->compare($installedVersion) <= 0) {
                throw new InvalidArgumentException('An installed extension can only be replaced by a newer version.');
            }

            $version = (string) $manifest->version();
            $provider = $manifest->serviceProvider();
            $boundRuntimePath = $relativeRuntime;
            $boundExtensionId = $extensionId;
            $query = $this->database->getQuery(true)
                ->update($this->quoteName($this->schema . '.extensions'))
                ->set($this->quoteName('installed_version') . ' = :version')
                ->set($this->quoteName('status') . " = 'active'")
                ->set($this->quoteName('service_provider') . ' = :provider')
                ->set($this->quoteName('runtime_path') . ' = :runtime_path')
                ->set($this->quoteName('registry_version') . ' = ' . $this->quoteName('registry_version') . ' + 1')
                ->set($this->quoteName('updated_at') . ' = CURRENT_TIMESTAMP')
                ->where($this->quoteName('id') . ' = :id')
                ->bind(':version', $version, ParameterType::STRING)
                ->bind(':provider', $provider, ParameterType::STRING)
                ->bind(':runtime_path', $boundRuntimePath, ParameterType::STRING)
                ->bind(':id', $boundExtensionId, ParameterType::STRING);
        }

        $this->database->setQuery($query)->execute();
        $releaseId = Uuid::uuid7()->toString();
        $boundReleaseId = $releaseId;
        $boundExtensionId = $extensionId;
        $releaseVersion = (string) $manifest->version();
        $checksumValue = (string) $checksum;
        $algorithm = $signature?->algorithm();
        $signingKeyId = $signature?->keyId();
        $signatureBase64 = $signature?->asBase64();
        $release = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.extension_releases'))
            ->columns($this->quoteNames([
                'id', 'extension_id', 'version', 'manifest', 'package_sha256', 'signature_algorithm',
                'signing_key_id', 'signature_base64', 'released_at', 'installed_at',
            ]))
            ->values(
                ':id, :extension_id, :version, CAST(:manifest AS jsonb), :checksum, :algorithm, '
                . ':key_id, :signature, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP',
            )
            ->bind(':id', $boundReleaseId, ParameterType::STRING)
            ->bind(':extension_id', $boundExtensionId, ParameterType::STRING)
            ->bind(':version', $releaseVersion, ParameterType::STRING)
            ->bind(':manifest', $manifestJson, ParameterType::STRING)
            ->bind(':checksum', $checksumValue, ParameterType::STRING)
            ->bind(
                ':algorithm',
                $algorithm,
                $signature === null ? ParameterType::NULL : ParameterType::STRING,
            )
            ->bind(':key_id', $signingKeyId, $signature === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(
                ':signature',
                $signatureBase64,
                $signature === null ? ParameterType::NULL : ParameterType::STRING,
            );
        $this->database->setQuery($release)->execute();

        foreach ($manifest->dependencies() as $dependency) {
            $boundReleaseId = $releaseId;
            $dependencyIdentifier = $dependency->extension()->value();
            $dependencyConstraint = (string) $dependency->constraint();
            $optional = $dependency->isOptional();
            $query = $this->database->getQuery(true)
                ->insert($this->quoteName($this->schema . '.extension_dependencies'))
                ->columns($this->quoteNames(['release_id', 'required_identifier', 'version_constraint', 'optional']))
                ->values(':release_id, :identifier, :constraint, :optional')
                ->bind(':release_id', $boundReleaseId, ParameterType::STRING)
                ->bind(':identifier', $dependencyIdentifier, ParameterType::STRING)
                ->bind(':constraint', $dependencyConstraint, ParameterType::STRING)
                ->bind(':optional', $optional, ParameterType::BOOLEAN);
            $this->database->setQuery($query)->execute();
        }
    }

    private function disableTemplatesExcept(string $identifier): void
    {
        $boundIdentifier = $identifier;
        $query = $this->database->getQuery(true)
            ->update($this->quoteName($this->schema . '.extensions'))
            ->set($this->quoteName('status') . " = 'disabled'")
            ->set($this->quoteName('registry_version') . ' = ' . $this->quoteName('registry_version') . ' + 1')
            ->set($this->quoteName('updated_at') . ' = CURRENT_TIMESTAMP')
            ->where($this->quoteName('extension_type') . " = 'template'")
            ->where($this->quoteName('status') . " = 'active'")
            ->where($this->quoteName('identifier') . ' <> :identifier')
            ->bind(':identifier', $boundIdentifier, ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    private function assertCompatible(ExtensionManifest $manifest): void
    {
        $php = preg_replace('/[^0-9.].*$/', '', PHP_VERSION);

        if (!is_string($php) || substr_count($php, '.') < 2) {
            throw new RuntimeException('The current PHP version cannot be evaluated as semantic versioning.');
        }

        if (!$manifest->supports(SemanticVersion::fromString('2.0.0'), SemanticVersion::fromString($php))) {
            throw new InvalidArgumentException('The extension is incompatible with this Kumwe or PHP version.');
        }
    }

    private function assertDependencies(ExtensionManifest $manifest): void
    {
        foreach ($manifest->dependencies() as $dependency) {
            $installed = $this->findInstalledOrNull($dependency->extension()->value());

            if ($installed === null) {
                if (!$dependency->isOptional()) {
                    throw new InvalidArgumentException(sprintf(
                        'Required extension %s is not installed.',
                        $dependency->extension()->value(),
                    ));
                }

                continue;
            }

            $version = $installed['installed_version'] ?? null;

            if (!is_string($version) || !$dependency->constraint()->accepts(SemanticVersion::fromString($version))) {
                throw new InvalidArgumentException(sprintf(
                    'Installed extension %s does not satisfy %s.',
                    $dependency->extension()->value(),
                    (string) $dependency->constraint(),
                ));
            }
        }
    }

    private function signature(?string $keyId, ?string $base64Signature): ?PackageSignature
    {
        if ($keyId === null && $base64Signature === null) {
            return null;
        }

        if ($keyId === null || $base64Signature === null) {
            throw new InvalidArgumentException('Both signing key ID and package signature are required together.');
        }

        return PackageSignature::ed25519($keyId, $base64Signature);
    }

    private function assertTrusted(PackageChecksum $checksum, ?PackageSignature $signature): void
    {
        if ($signature === null) {
            if (!$this->allowUnsignedLocalPackages) {
                throw new UntrustedPackage('Unsigned extension packages are disabled for this installation.');
            }

            return;
        }

        $keyId = $signature->keyId();
        $query = $this->database->getQuery(true)
            ->select($this->quoteName('public_key_base64'))
            ->from($this->quoteName($this->schema . '.extension_trust_keys'))
            ->where($this->quoteName('key_id') . ' = :key_id')
            ->where($this->quoteName('enabled') . ' = true')
            ->where($this->quoteName('revoked_at') . ' IS NULL')
            ->bind(':key_id', $keyId, ParameterType::STRING);
        $publicKey = $this->database->setQuery($query)->loadResult();

        if (!is_string($publicKey)) {
            throw new UntrustedPackage('The extension signing key is not trusted.');
        }

        $verifier = new SodiumEd25519Verifier([$signature->keyId() => $publicKey]);

        if (!$verifier->verify($checksum, $signature)) {
            throw new UntrustedPackage('The extension package signature is invalid.');
        }
    }

    private function manifestJson(string $archiveFile): string
    {
        $zip = new ZipArchive();

        if ($zip->open($archiveFile, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The extension archive cannot be opened.');
        }

        try {
            $manifest = $zip->getFromName('kumwe.json', 1_048_577, ZipArchive::FL_UNCHANGED);

            if (!is_string($manifest) || strlen($manifest) > 1_048_576) {
                throw new InvalidArgumentException('The extension manifest is missing or too large.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function extract(string $archiveFile, string $stagingDirectory): void
    {
        $this->ensureDirectory($stagingDirectory);
        chmod($stagingDirectory, 0700);
        $zip = new ZipArchive();

        if ($zip->open($archiveFile, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The extension archive cannot be opened for extraction.');
        }

        try {
            if (!$zip->extractTo($stagingDirectory)) {
                throw new RuntimeException('The extension archive could not be extracted into staging.');
            }
        } finally {
            $zip->close();
        }
    }

    private function assertProviderFileExists(ExtensionManifest $manifest, string $root): void
    {
        foreach ($manifest->autoload() as $prefix => $path) {
            if (!str_starts_with($manifest->serviceProvider(), $prefix)) {
                continue;
            }

            $relativeClass = substr($manifest->serviceProvider(), strlen($prefix));
            $file = $root . '/' . $path . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file) && !is_link($file)) {
                return;
            }
        }

        throw new InvalidArgumentException('The manifest provider is not present in the declared PSR-4 source tree.');
    }

    private function runtimePath(ExtensionManifest $manifest): string
    {
        return $manifest->identifier()->value() . '/' . (string) $manifest->version();
    }

    /** @return array<string, mixed> */
    private function findInstalled(string $identifier): array
    {
        return $this->findInstalledOrNull($identifier)
            ?? throw new InvalidArgumentException('The requested extension is not installed.');
    }

    /** @return array<string, mixed>|null */
    private function findInstalledOrNull(string $identifier): ?array
    {
        $boundIdentifier = $identifier;
        $query = $this->database->getQuery(true)
            ->select('*')
            ->from($this->quoteName($this->schema . '.extensions'))
            ->where($this->quoteName('identifier') . ' = :identifier')
            ->bind(':identifier', $boundIdentifier, ParameterType::STRING);
        $row = $this->database->setQuery($query)->loadAssoc();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $actorId, string $action, string $identifier, array $metadata = []): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            'extension',
            str_replace('/', ':', $identifier),
            'success',
            $metadata,
        ));
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory %s could not be created.', $directory));
        }
    }

    private function removeTree(string $directory): void
    {
        $resolvedRoot = realpath($this->extensionRoot);
        $resolved = realpath($directory);

        if (
            !is_string($resolvedRoot)
            || !is_string($resolved)
            || !str_starts_with($resolved . '/', $resolvedRoot . '/')
        ) {
            throw new RuntimeException('Refusing to remove an extension path outside extension storage.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new RuntimeException('Extension storage returned an invalid filesystem entry.');
            }

            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($resolved);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function quoteNames(array $names): array
    {
        return array_map(fn (string $name): string => $this->quoteName($name), $names);
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Installed extension field %s is invalid.', $field));
        }

        return $value;
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
