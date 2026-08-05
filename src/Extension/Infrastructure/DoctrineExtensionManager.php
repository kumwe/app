<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use InvalidArgumentException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigration;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigrationRunner;
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
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class DoctrineExtensionManager implements ExtensionManager
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private string $extensionRoot,
        private string $publicAssetRoot,
        private ArchiveReader $archives,
        private PackageSafetyPolicy $safety,
        private ExtensionMigrationRunner $migrations,
        private ExtensionRuntimeMapCompiler $compiler,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private DispatcherInterface $events,
        private bool $allowUnsignedLocalPackages,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
    ) {
    }

    public function installed(ExecutionContext $context): array
    {
        return array_values(array_filter($this->database->fetchAllAssociative(sprintf(
            'SELECT identifier, extension_type, installed_version, status, service_provider, registry_version, '
            . 'runtime_path, installed_at, updated_at FROM %s ORDER BY identifier',
            $this->tables->quoted('extensions'),
        )), fn (array $row): bool => is_string($row['identifier'] ?? null)
            && $this->authorization->decide(
                $context,
                Capability::fromString('extensions.manage'),
                AuthorizationResource::item('extension', $row['identifier']),
            )->allowed));
    }

    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->authorize($context, AuthorizationResource::collection('extension'));
        $actorId = $context->actorId();
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
        $this->dispatch('onKumweExtensionBeforeInstall', $manifest, $actorId);
        $moved = false;
        /** @var list<ExtensionMigration> $appliedMigrations */
        $appliedMigrations = [];

        try {
            $this->ensureDirectory(dirname($finalDirectory));

            if (!rename($stagingDirectory, $finalDirectory)) {
                throw new RuntimeException('The staged extension could not be activated atomically.');
            }

            $moved = true;
            $this->publishAssets($manifest, $finalDirectory);
            $result = $this->transactions->transactional(function () use (
                $manifest,
                $manifestJson,
                $checksum,
                $signature,
                $relativeRuntime,
                $actorId,
                $finalDirectory,
                &$appliedMigrations,
            ): array {
                $appliedMigrations = $this->migrations->apply($manifest, $finalDirectory);
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
                $this->removeTree($this->publicAssetRoot . '/' . $previousRuntime, $this->publicAssetRoot);
            }

            $this->dispatch('onKumweExtensionAfterInstall', $manifest, $actorId, $result);

            return $result;
        } catch (Throwable $exception) {
            if (
                $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                && $appliedMigrations !== []
            ) {
                try {
                    $this->migrations->compensate($manifest, array_reverse($appliedMigrations));
                } catch (Throwable $rollbackFailure) {
                    throw new RuntimeException(
                        'The extension failed and its database compensation also failed; restore from backup.',
                        0,
                        $rollbackFailure,
                    );
                }
            }
            if ($moved && is_dir($finalDirectory)) {
                $this->removeTree($finalDirectory);
                $this->removeTree($this->publicAssetRoot . '/' . $relativeRuntime, $this->publicAssetRoot);
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

    public function activate(string $identifier, ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        ExtensionIdentifier::fromString($identifier);
        $manifest = $this->installedManifest($identifier);
        $this->dispatch('onKumweExtensionBeforeActivate', $manifest, $actorId);
        $result = $this->changeStatus($identifier, 'active', 'extension.activate', $actorId);
        $this->dispatch('onKumweExtensionAfterActivate', $manifest, $actorId, $result);

        return $result;
    }

    public function disable(string $identifier, ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        ExtensionIdentifier::fromString($identifier);

        $manifest = $this->installedManifest($identifier);
        $this->dispatch('onKumweExtensionBeforeDisable', $manifest, $actorId);
        $result = $this->changeStatus($identifier, 'disabled', 'extension.disable', $actorId);
        $this->dispatch('onKumweExtensionAfterDisable', $manifest, $actorId, $result);

        return $result;
    }

    public function uninstall(string $identifier, ExecutionContext $context): void
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        ExtensionIdentifier::fromString($identifier);
        $installed = $this->findInstalled($identifier);
        $manifest = $this->installedManifest($identifier);
        $this->dispatch('onKumweExtensionBeforeUninstall', $manifest, $actorId);
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
                $affected = $this->database->delete(
                    $this->tables->raw('extensions'),
                    ['identifier' => $identifier],
                );
                if ((string) $affected !== '1') {
                    throw new InvalidArgumentException('The requested extension is not installed.');
                }
                $this->ownership->remove(
                    AuthorizationResource::item('extension', $identifier),
                    SiteContext::default(),
                );
                $this->compiler->rebuild();
                $this->audit($actorId, 'extension.uninstall', $identifier);
            });
            $this->removeTree($trash);
            $this->removeTree($this->publicAssetRoot . '/' . $relativePath, $this->publicAssetRoot);
            $this->dispatch('onKumweExtensionAfterUninstall', $manifest, $actorId);
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

            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET status = ?, registry_version = registry_version + 1, updated_at = ? '
                . 'WHERE identifier = ?',
                $this->tables->quoted('extensions'),
            ), [$status, $this->clock->now(), $identifier], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING,
            ]);

            if ($affected !== 1) {
                throw new InvalidArgumentException('The requested extension is not installed.');
            }

            $this->compiler->rebuild();
            $this->audit($actorId, $action, $identifier);

            return $this->findInstalled($identifier);
        });

        return $result;
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            $resource,
        );
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
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('extensions'), [
                'id' => $extensionId,
                'identifier' => $identifier,
                'extension_type' => $manifest->type()->value,
                'installed_version' => (string) $manifest->version(),
                'status' => 'active',
                'service_provider' => $manifest->serviceProvider(),
                'registry_version' => 1,
                'runtime_path' => $relativeRuntime,
                'installed_at' => $now,
                'updated_at' => $now,
            ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(
                AuthorizationResource::item('extension', $identifier),
                SiteContext::default(),
            );
        } else {
            $installedVersion = SemanticVersion::fromString($this->requiredString($existing, 'installed_version'));

            if ($manifest->version()->compare($installedVersion) <= 0) {
                throw new InvalidArgumentException('An installed extension can only be replaced by a newer version.');
            }

            $this->database->executeStatement(sprintf(
                "UPDATE %s SET installed_version = ?, status = 'active', service_provider = ?, runtime_path = ?, "
                . 'registry_version = registry_version + 1, updated_at = ? WHERE id = ?',
                $this->tables->quoted('extensions'),
            ), [
                (string) $manifest->version(),
                $manifest->serviceProvider(),
                $relativeRuntime,
                $this->clock->now(),
                $extensionId,
            ], [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID]);
        }

        $releaseId = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $manifestData = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
        $this->database->insert($this->tables->raw('extension_releases'), [
            'id' => $releaseId,
            'extension_id' => $extensionId,
            'version' => (string) $manifest->version(),
            'manifest' => $manifestData,
            'package_sha256' => (string) $checksum,
            'signature_algorithm' => $signature?->algorithm(),
            'signing_key_id' => $signature?->keyId(),
            'signature_base64' => $signature?->asBase64(),
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);

        foreach ($manifest->dependencies() as $dependency) {
            $this->database->insert($this->tables->raw('extension_dependencies'), [
                'release_id' => $releaseId,
                'required_identifier' => $dependency->extension()->value(),
                'version_constraint' => (string) $dependency->constraint(),
                'optional' => $dependency->isOptional(),
            ], ['optional' => Types::BOOLEAN]);
        }
    }

    private function disableTemplatesExcept(string $identifier): void
    {
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'disabled', registry_version = registry_version + 1, updated_at = ? "
            . "WHERE extension_type = 'template' AND status = 'active' AND identifier <> ?",
            $this->tables->quoted('extensions'),
        ), [$this->clock->now(), $identifier], [Types::DATETIME_IMMUTABLE, Types::STRING]);
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

        $publicKey = $this->database->fetchOne(sprintf(
            'SELECT public_key_base64 FROM %s WHERE key_id = ? AND enabled = ? AND revoked_at IS NULL',
            $this->tables->quoted('extension_trust_keys'),
        ), [$signature->keyId(), true], [Types::STRING, Types::BOOLEAN]);

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

    private function publishAssets(ExtensionManifest $manifest, string $sourceRoot): void
    {
        foreach ($manifest->assets() as $asset) {
            $source = $sourceRoot . '/' . $asset;
            if (!is_file($source) || is_link($source)) {
                throw new InvalidArgumentException(sprintf('Declared extension asset %s is missing.', $asset));
            }
            $destination = $this->publicAssetRoot . '/' . $this->runtimePath($manifest) . '/' . $asset;
            $this->ensurePublicDirectory(dirname($destination));
            if (!copy($source, $destination)) {
                throw new RuntimeException(sprintf('Extension asset %s could not be published.', $asset));
            }
            chmod($destination, 0644);
        }
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
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), [$identifier]);

        if ($row === false) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    private function installedManifest(string $identifier): ExtensionManifest
    {
        $manifest = $this->database->fetchOne(sprintf(
            'SELECT r.manifest FROM %s e INNER JOIN %s r ON r.extension_id = e.id '
            . 'AND r.version = e.installed_version WHERE e.identifier = ?',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$identifier]);

        if (is_array($manifest)) {
            $manifest = json_encode($manifest, JSON_THROW_ON_ERROR);
        }
        if (!is_string($manifest)) {
            throw new RuntimeException('The installed extension manifest is unavailable.');
        }

        return ExtensionManifest::fromJson($manifest);
    }

    /** @param array<string, mixed> $result */
    private function dispatch(
        string $name,
        ExtensionManifest $manifest,
        string $actorId,
        array $result = [],
    ): void {
        $this->events->dispatch($name, new Event($name, [
            'identifier' => $manifest->identifier()->value(),
            'version' => (string) $manifest->version(),
            'actor_id' => $actorId,
            'result' => $result,
        ]));
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

    private function ensurePublicDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Public extension directory %s could not be created.', $directory));
        }

        chmod($directory, 0755);
    }

    private function removeTree(string $directory, ?string $allowedRoot = null): void
    {
        if (!file_exists($directory)) {
            return;
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Refusing to remove an invalid extension directory.');
        }

        $resolvedRoot = realpath($allowedRoot ?? $this->extensionRoot);
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

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Installed extension field %s is invalid.', $field));
        }

        return $value;
    }
}
