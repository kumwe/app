<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use InvalidArgumentException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\CMS\Extension\Application\ExtensionRegistryLease;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallOutcome;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionType;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use Kumwe\CMS\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Presentation\Application\ThemeActivationGuard;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\CMS\Presentation\ThemeSurface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class DoctrineExtensionManager
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
        private ThemeActivationGuard $themeActivationGuard,
        private ThemePackageValidator $themeValidator,
        private ThemeMutationAuthorizer $themeAuthorization,
        private TrustStore $trust,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private ?PackageDefinitionSynchronizer $businessDefinitions = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function installed(ExecutionContext $context): array
    {
        $installed = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, e.extension_type, e.installed_version, e.status, e.service_provider, '
            . 'e.registry_version, e.runtime_path, e.installed_at, e.updated_at, r.manifest '
            . 'FROM %s e INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . 'ORDER BY e.identifier',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ));
        $surfaces = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, a.surface FROM %s a INNER JOIN %s e ON e.id = a.extension_id '
            . 'ORDER BY e.identifier, a.surface',
            $this->tables->quoted('theme_activations'),
            $this->tables->quoted('extensions'),
        ));
        /** @var array<string, list<string>> $byIdentifier */
        $byIdentifier = [];

        foreach ($surfaces as $surface) {
            $identifier = $surface['identifier'] ?? null;
            $surfaceName = $surface['surface'] ?? null;
            if (is_string($identifier) && is_string($surfaceName)) {
                $byIdentifier[$identifier][] = $surfaceName;
            }
        }
        $siteSurfaces = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, a.site_identifier FROM %s a INNER JOIN %s e ON e.id = a.extension_id '
            . 'ORDER BY e.identifier, a.site_identifier',
            $this->tables->quoted('site_theme_activations'),
            $this->tables->quoted('extensions'),
        ));
        foreach ($siteSurfaces as $surface) {
            $identifier = $surface['identifier'] ?? null;
            $siteIdentifier = $surface['site_identifier'] ?? null;
            if (is_string($identifier) && is_string($siteIdentifier)) {
                $byIdentifier[$identifier][] = 'site:' . $siteIdentifier;
            }
        }

        foreach ($installed as &$extension) {
            $identifier = $extension['identifier'] ?? null;
            $extension['theme_surfaces'] = is_string($identifier) ? ($byIdentifier[$identifier] ?? []) : [];
            $manifestValue = $extension['manifest'] ?? null;
            if (is_string($identifier) && (is_string($manifestValue) || is_array($manifestValue))) {
                $manifest = ExtensionManifest::fromJson(is_string($manifestValue)
                    ? $manifestValue
                    : json_encode($manifestValue, JSON_THROW_ON_ERROR));
                $extension['manifest_schema'] = $manifest->schemaVersion();
                $extension['contributions'] = $this->contributionDiagnostics(
                    $manifest,
                    ($extension['status'] ?? null) === 'active',
                );
            } else {
                $extension['manifest_schema'] = null;
                $extension['contributions'] = [];
            }
            unset($extension['manifest']);
        }
        unset($extension);

        return array_values(array_filter(
            $installed,
            fn (array $row): bool => is_string($row['identifier'] ?? null)
                && $this->authorization->decide(
                    $context,
                    Capability::fromString('extensions.manage'),
                    AuthorizationResource::item('extension', $row['identifier']),
                )->allowed,
        ));
    }

    public function reconcileInstallOperations(ExtensionRegistryLease $lease): int
    {
        $this->assertFence($lease);
        $rows = $this->database->fetchAllAssociative(sprintf(
            "SELECT * FROM %s WHERE transaction_outcome = 'unknown' ORDER BY created_at LIMIT 25",
            $this->tables->quoted('extension_install_operations'),
        ));
        $reconciled = 0;
        foreach ($rows as $row) {
            $operationId = $this->requiredString($row, 'operation_id');
            $runtimePath = $this->requiredString($row, 'runtime_path');
            $stagingPath = $this->requiredString($row, 'staging_path');
            $this->database->update($this->tables->raw('extension_install_operations'), [
                'fence' => $lease->fence(),
                'updated_at' => $this->clock->now(),
            ], ['operation_id' => $operationId], [
                'fence' => Types::BIGINT, 'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $final = $this->extensionRoot . '/' . $runtimePath;
            $staged = $this->extensionRoot . '/' . $stagingPath;
            if (!is_dir($final) && is_dir($staged) && !is_link($staged)) {
                $this->ensureBoundedDirectory($this->extensionRoot, dirname($runtimePath), 0700);
                if (!rename($staged, $final)) {
                    continue;
                }
            }
            $archive = $final . '/.kumwe-package.zip';
            if (!is_file($archive) || is_link($archive)) {
                $installed = $this->findInstalledOrNull($this->requiredString($row, 'identifier'));
                if (
                    $installed !== null
                    && ($installed['installed_version'] ?? null) === $this->requiredString($row, 'version')
                    && ($installed['runtime_path'] ?? null) === $runtimePath
                ) {
                    $this->markInstallOperation(
                        $operationId,
                        'committed',
                        ExtensionInstallOutcome::Committed,
                        $lease,
                    );
                    ++$reconciled;
                    continue;
                }
                foreach ([$staged, $final] as $orphan) {
                    if (is_dir($orphan) && !is_link($orphan)) {
                        $this->removeTree($orphan);
                    }
                }
                $assets = $this->publicAssetRoot . '/' . $runtimePath;
                if (is_dir($assets) && !is_link($assets)) {
                    $this->removeTree($assets, $this->publicAssetRoot);
                }
                $this->markInstallFailure(
                    $operationId,
                    ExtensionInstallOutcome::RolledBack,
                    new RuntimeException(
                        'The retained package was unavailable; incomplete install artifacts were retired.',
                    ),
                    $lease,
                );
                ++$reconciled;
                continue;
            }
            try {
                $this->installOperation(
                    $archive,
                    $this->requiredString($row, 'actor_id'),
                    SiteContext::fromString($this->requiredString($row, 'site_identifier')),
                    $lease,
                    is_string($row['signing_key_id'] ?? null) ? $row['signing_key_id'] : null,
                    is_string($row['package_signature'] ?? null) ? $row['package_signature'] : null,
                );
                ++$reconciled;
            } catch (Throwable) {
                // The durable operation remains retryable and readiness will continue to fail closed.
            }
        }

        return $reconciled;
    }

    public function hasPendingInstallOperations(): bool
    {
        return $this->databaseInteger($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE transaction_outcome = 'unknown'",
            $this->tables->quoted('extension_install_operations'),
        )), 'pending extension install count') > 0;
    }

    /** @return array<string, mixed> */
    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ExtensionRegistryLease $lease,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->authorize($context, AuthorizationResource::collection('extension'));

        return $this->installOperation(
            $archiveFile,
            $context->actorId(),
            $context->site(),
            $lease,
            $signingKeyId,
            $base64Signature,
        );
    }

    /** @return array<string, mixed> */
    private function installOperation(
        string $archiveFile,
        string $actorId,
        SiteContext $site,
        ExtensionRegistryLease $lease,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $resolvedArchive = realpath($archiveFile);
        if (!is_string($resolvedArchive) || !is_file($resolvedArchive) || is_link($resolvedArchive)) {
            throw new InvalidArgumentException('The extension archive path must identify a regular file.');
        }

        $snapshotRoot = $this->extensionRoot . '/.staging/.incoming/' . Uuid::uuid7()->toString();
        $this->ensureDirectory($snapshotRoot);
        chmod($snapshotRoot, 0700);
        try {
            $snapshot = $this->snapshotArchive($resolvedArchive, $snapshotRoot);

            return $this->installSnapshot(
                $snapshot,
                $actorId,
                $site,
                $lease,
                $signingKeyId,
                $base64Signature,
            );
        } finally {
            if (is_dir($snapshotRoot) && !is_link($snapshotRoot)) {
                $this->removeTree($snapshotRoot);
            }
        }
    }

    /** @return array<string, mixed> */
    private function installSnapshot(
        string $archiveFile,
        string $actorId,
        SiteContext $site,
        ExtensionRegistryLease $lease,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->assertFence($lease);

        $package = $this->archives->inspect($archiveFile);
        $this->safety->assertSafe($package);
        $checksumValue = hash_file('sha256', $archiveFile);

        if (!is_string($checksumValue)) {
            throw new RuntimeException('The extension package checksum could not be calculated.');
        }

        $checksum = PackageChecksum::sha256($checksumValue);
        $manifestJson = $this->manifestJson($archiveFile);
        $manifest = ExtensionManifest::fromJson($manifestJson);
        $this->assertCompatible($manifest);
        $signature = $this->signature($signingKeyId, $base64Signature);
        $this->trust->assertTrusted($checksum, $signature, $manifest->identifier());
        $this->assertDependencies($manifest);

        $relativeRuntime = $this->runtimePath($manifest);
        $this->ensureBoundedDirectory($this->extensionRoot, dirname($relativeRuntime), 0700);
        $finalDirectory = $this->extensionRoot . '/' . $relativeRuntime;
        $operationId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'kumwe-install:' . $manifest->identifier()->value() . ':' . $manifest->version() . ':' . $checksum,
        )->toString();
        $stagingRelative = '.staging/' . $operationId;
        $stagingDirectory = $this->extensionRoot . '/' . $stagingRelative;
        $previous = $this->findInstalledOrNull($manifest->identifier()->value());

        if (
            $previous !== null
            && $this->isAnyThemeActive($this->requiredString($previous, 'id'))
        ) {
            throw new InvalidArgumentException(
                'Disable an active theme on every presentation surface before upgrading it.',
            );
        }

        $operation = $this->beginInstallOperation(
            $operationId,
            $manifest,
            (string) $checksum,
            $relativeRuntime,
            $stagingRelative,
            $actorId,
            $site,
            $signingKeyId,
            $base64Signature,
            $lease,
        );
        if (($operation['transaction_outcome'] ?? null) === ExtensionInstallOutcome::Committed->value) {
            $installed = $this->findInstalledOrNull($manifest->identifier()->value());
            if (
                $installed !== null
                && ($installed['installed_version'] ?? null) === (string) $manifest->version()
                && ($installed['runtime_path'] ?? null) === $relativeRuntime
            ) {
                return $installed;
            }
            $this->database->delete($this->tables->raw('extension_install_operations'), [
                'operation_id' => $operationId,
            ]);
            $operation = $this->beginInstallOperation(
                $operationId,
                $manifest,
                (string) $checksum,
                $relativeRuntime,
                $stagingRelative,
                $actorId,
                $site,
                $signingKeyId,
                $base64Signature,
                $lease,
            );
        }
        try {
            if (!is_dir($finalDirectory)) {
                if (file_exists($stagingDirectory) || is_link($stagingDirectory)) {
                    $this->removeTree($stagingDirectory);
                }
                $this->extract($archiveFile, $stagingDirectory);
                $this->assertProviderFileExists($manifest, $stagingDirectory);
                if (!copy($archiveFile, $stagingDirectory . '/.kumwe-package.zip')) {
                    throw new RuntimeException('The extension package could not be retained for crash recovery.');
                }
                chmod($stagingDirectory . '/.kumwe-package.zip', 0600);
            } elseif (is_link($finalDirectory)) {
                throw new RuntimeException('The staged extension runtime root is unsafe.');
            }
        } catch (Throwable $exception) {
            $this->markInstallFailure(
                $operationId,
                ExtensionInstallOutcome::RolledBack,
                $exception,
                $lease,
            );
            if (is_dir($stagingDirectory) && !is_link($stagingDirectory)) {
                $this->removeTree($stagingDirectory);
            }
            throw $exception;
        }
        $lease->renew();
        $this->dispatch('onKumweExtensionBeforeInstall', $manifest, $actorId, lease: $lease);
        $committed = false;
        $commitAttempted = false;

        try {
            $this->ensureBoundedDirectory($this->extensionRoot, dirname($relativeRuntime), 0700);
            if (!is_dir($finalDirectory) && !rename($stagingDirectory, $finalDirectory)) {
                throw new RuntimeException('The staged extension could not be activated atomically.');
            }
            $deployedTreeDigest = FilesystemExtensionArtifactVerifier::treeDigest($finalDirectory);
            $this->trust->assertArtifactIntegrity([
                'runtime_path' => $relativeRuntime,
                'package_sha256' => (string) $checksum,
                'artifact_sha256' => (string) $checksum,
                'deployed_tree_sha256' => $deployedTreeDigest,
            ]);
            $this->markInstallOperation($operationId, 'migrating', ExtensionInstallOutcome::Unknown, $lease);
            $this->publishAssets($manifest, $finalDirectory);
            $mysql = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
            if ($mysql) {
                // MySQL/MariaDB DDL may commit implicitly. Extension migrations are required to be
                // idempotent and are ledgered; their durable saga is retried rather than compensated.
                $this->migrations->apply($manifest, $finalDirectory);
            }
            $commitAttempted = true;
            $result = $this->transactions->transactional(function () use (
                $operationId,
                $manifest,
                $manifestJson,
                $checksum,
                $signature,
                $relativeRuntime,
                $actorId,
                $site,
                $finalDirectory,
                $deployedTreeDigest,
                $previous,
                $lease,
                $mysql,
                $archiveFile,
            ): array {
                $this->assertFence($lease);
                if (!$mysql) {
                    $this->migrations->apply($manifest, $finalDirectory);
                }
                if (!hash_equals((string) $checksum, (string) hash_file('sha256', $archiveFile))) {
                    throw new RuntimeException('The extension archive changed before install finalization.');
                }
                $this->trust->assertTrusted($checksum, $signature, $manifest->identifier(), true);
                $this->assertProviderFileExists($manifest, $finalDirectory);
                $this->assertCurrentFence($lease);
                $this->persistRelease(
                    $manifest,
                    $manifestJson,
                    $checksum,
                    $signature,
                    $relativeRuntime,
                    $site,
                    $deployedTreeDigest,
                    $actorId,
                );
                $this->compiler->cancelRetirement($relativeRuntime);
                $this->audit($actorId, 'extension.install', $manifest->identifier()->value(), [
                    'version' => (string) $manifest->version(),
                    'checksum' => (string) $checksum,
                ]);
                $generation = $this->compiler->stage('extension.install');
                $previousRuntime = $previous['runtime_path'] ?? null;
                if (is_string($previousRuntime) && $previousRuntime !== $relativeRuntime) {
                    $this->compiler->scheduleRetirement($previousRuntime, $generation);
                }
                $this->markInstallOperation(
                    $operationId,
                    'committed',
                    ExtensionInstallOutcome::Committed,
                    $lease,
                );

                return $this->findInstalled($manifest->identifier()->value());
            });
            $committed = true;

            $this->dispatch('onKumweExtensionAfterInstall', $manifest, $actorId, $result, $lease);

            return $result;
        } catch (Throwable $exception) {
            if ($committed) {
                throw $exception;
            }
            $outcome = $commitAttempted
                ? $this->resolveInstallOutcome($manifest, $relativeRuntime)
                : ExtensionInstallOutcome::Unknown;
            try {
                $this->markInstallFailure($operationId, $outcome, $exception, $lease);
            } catch (Throwable) {
                // Preserve the original error and the unknown durable operation for reconciliation.
            }
            // An unknown outcome retains both staged/final bytes. Reconciliation by operation ID is the
            // only authority allowed to finalize or retire them after a connection/DDL ambiguity.

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function activate(
        string $identifier,
        ExecutionContext $context,
        ExtensionRegistryLease $lease,
        ?ThemeSurface $surface = null,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        $lease->renew();
        ExtensionIdentifier::fromString($identifier);
        $manifest = $this->installedManifest($identifier);

        if ($manifest->type() === ExtensionType::Template) {
            if ($surface === null) {
                throw new InvalidArgumentException('Template activation requires a site or administrator surface.');
            }
            $this->assertThemeCapability($context, $surface);
            $this->themeActivationGuard->assertAllowed($surface, $context, $stepUpCredential);
            $this->themeValidator->validate($this->themeSurfacePath($identifier, $surface), $surface);
        } elseif ($surface !== null || $stepUpCredential !== null) {
            throw new InvalidArgumentException('Only template extensions may select a presentation surface.');
        }

        $this->dispatch('onKumweExtensionBeforeActivate', $manifest, $actorId, lease: $lease);
        $lease->renew();
        $result = $this->changeStatus(
            $identifier,
            'active',
            'extension.activate',
            $actorId,
            $lease,
            $surface,
            $context,
        );
        $this->dispatch('onKumweExtensionAfterActivate', $manifest, $actorId, $result, $lease);

        return $result;
    }

    /** @return array<string, mixed> */
    public function disable(
        string $identifier,
        ExecutionContext $context,
        ExtensionRegistryLease $lease,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        $lease->renew();
        ExtensionIdentifier::fromString($identifier);

        $manifest = $this->installedManifest($identifier);
        $this->assertCurrentThemeCapabilities($this->findInstalled($identifier), $context);
        $this->assertAdministratorThemeMutationAllowed($identifier, $context, $stepUpCredential);
        $this->dispatch('onKumweExtensionBeforeDisable', $manifest, $actorId, lease: $lease);
        $lease->renew();
        $result = $this->changeStatus(
            $identifier,
            'disabled',
            'extension.disable',
            $actorId,
            context: $context,
            lease: $lease,
        );
        $this->dispatch('onKumweExtensionAfterDisable', $manifest, $actorId, $result, $lease);

        return $result;
    }

    public function uninstall(
        string $identifier,
        ExecutionContext $context,
        ExtensionRegistryLease $lease,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $actorId = $context->actorId();
        $lease->renew();
        ExtensionIdentifier::fromString($identifier);
        $installed = $this->findInstalled($identifier);
        $this->assertThemeCapabilities($installed, $context);
        $this->assertAdministratorThemeMutationAllowed($identifier, $context, $stepUpCredential);
        $manifest = $this->installedManifest($identifier);
        $this->dispatch('onKumweExtensionBeforeUninstall', $manifest, $actorId, lease: $lease);
        $lease->renew();
        $relativePath = $installed['runtime_path'] ?? null;

        if (!is_string($relativePath)) {
            throw new RuntimeException('The installed extension has no runtime path.');
        }

        $this->transactions->transactional(function () use (
            $identifier,
            $actorId,
            $relativePath,
            $context,
            $lease,
        ): void {
            $this->assertFence($lease);
            $installed = $this->findInstalled($identifier);
            $capabilities = array_values(array_filter($this->database->fetchFirstColumn(sprintf(
                'SELECT capability_code FROM %s WHERE extension_id = ? ORDER BY capability_code',
                $this->tables->quoted('extension_contribution_capabilities'),
            ), [$installed['id']]), 'is_string'));
            $this->assertThemeCapabilities($installed, $context);
            $this->clearThemeActivations($installed, $actorId, $context->site());
            $this->businessDefinitions?->setActive($identifier, false, $actorId);
            $affected = $this->database->delete($this->tables->raw('extensions'), ['identifier' => $identifier]);
            if ((string) $affected !== '1') {
                throw new InvalidArgumentException('The requested extension is not installed.');
            }
            foreach ($capabilities as $capability) {
                $this->deleteContributionCapability($capability);
            }
            $this->ownership->remove(
                AuthorizationResource::item('extension', $identifier),
                $context->site(),
            );
            $this->audit($actorId, 'extension.uninstall', $identifier);
            $generation = $this->compiler->stage('extension.uninstall');
            $this->compiler->scheduleRetirement($relativePath, $generation);
        });
        $this->dispatch('onKumweExtensionAfterUninstall', $manifest, $actorId, lease: $lease);
    }

    /** @return array<string, mixed> */
    private function changeStatus(
        string $identifier,
        string $status,
        string $action,
        string $actorId,
        ExtensionRegistryLease $lease,
        ?ThemeSurface $surface = null,
        ?ExecutionContext $context = null,
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->transactional(function () use (
            $identifier,
            $status,
            $action,
            $actorId,
            $surface,
            $context,
            $lease,
        ): array {
            $this->assertFence($lease);
            $installed = $this->findInstalled($identifier);

            if ($surface !== null) {
                if (!$context instanceof ExecutionContext) {
                    throw new RuntimeException('Theme activation requires an authorized execution context.');
                }
                $this->assertThemeCapability($context, $surface);
                $previous = $this->themeActivation($surface, $context->site());
                $this->setThemeActivation(
                    $surface,
                    $this->requiredString($installed, 'id'),
                    $actorId,
                    $context->site(),
                );
                $previousId = $previous['extension_id'] ?? null;

                if (is_string($previousId) && $previousId !== $installed['id']) {
                    $this->disableThemeWhenUnused($previousId);
                }
            } elseif (
                $status === 'disabled'
                && ($installed['extension_type'] ?? null) === ExtensionType::Template->value
            ) {
                if (!$context instanceof ExecutionContext) {
                    throw new RuntimeException('Theme deactivation requires an authorized execution context.');
                }
                $this->assertCurrentThemeCapabilities($installed, $context);
                $this->clearThemeActivations($installed, $actorId, $context->site());
            }

            $persistedStatus = $status;
            if (
                $status === 'disabled'
                && ($installed['extension_type'] ?? null) === ExtensionType::Template->value
                && $this->isAnyThemeActive($this->requiredString($installed, 'id'))
            ) {
                $persistedStatus = 'active';
            }

            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET status = ?, registry_version = registry_version + 1, updated_at = ? '
                . 'WHERE identifier = ?',
                $this->tables->quoted('extensions'),
            ), [$persistedStatus, $this->clock->now(), $identifier], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING,
            ]);

            if ($affected !== 1) {
                throw new InvalidArgumentException('The requested extension is not installed.');
            }

            $this->businessDefinitions?->setActive($identifier, $persistedStatus === 'active', $actorId);

            $this->audit($actorId, $action, $identifier, $surface === null ? [] : [
                'surface' => $surface->value,
            ]);
            $this->compiler->stage($action);

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
        SiteContext $site,
        string $deployedTreeDigest,
        string $actorId,
    ): void {
        $identifier = $manifest->identifier()->value();
        $existing = $this->findInstalledOrNull($identifier);
        $extensionId = is_array($existing) && is_string($existing['id'] ?? null)
            ? $existing['id']
            : Uuid::uuid7()->toString();

        if ($existing === null) {
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('extensions'), [
                'id' => $extensionId,
                'identifier' => $identifier,
                'extension_type' => $manifest->type()->value,
                'installed_version' => (string) $manifest->version(),
                'status' => 'disabled',
                'service_provider' => $manifest->serviceProvider(),
                'registry_version' => 1,
                'runtime_path' => $relativeRuntime,
                'installed_at' => $now,
                'updated_at' => $now,
            ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership->record(
                AuthorizationResource::item('extension', $identifier),
                $site,
            );
        } else {
            $installedVersion = SemanticVersion::fromString($this->requiredString($existing, 'installed_version'));

            if (($existing['extension_type'] ?? null) !== $manifest->type()->value) {
                throw new InvalidArgumentException('An extension upgrade cannot change its extension type.');
            }

            if ($manifest->version()->compare($installedVersion) <= 0) {
                throw new InvalidArgumentException('An installed extension can only be replaced by a newer version.');
            }

            $status = $manifest->type() === ExtensionType::Template
                ? 'disabled'
                : $this->requiredString($existing, 'status');
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET installed_version = ?, status = ?, service_provider = ?, runtime_path = ?, '
                . 'registry_version = registry_version + 1, updated_at = ? WHERE id = ?',
                $this->tables->quoted('extensions'),
            ), [
                (string) $manifest->version(),
                $status,
                $manifest->serviceProvider(),
                $relativeRuntime,
                $this->clock->now(),
                $extensionId,
            ], [
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::GUID,
            ]);

            if ($manifest->type() === ExtensionType::Template) {
                $this->clearThemeActivations($existing, 'system:extension-upgrade', $site);
            }
        }

        $releaseId = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->synchronizeContributionCapabilities($manifest, $extensionId);
        $manifestData = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
        $this->database->insert($this->tables->raw('extension_releases'), [
            'id' => $releaseId,
            'extension_id' => $extensionId,
            'version' => (string) $manifest->version(),
            'manifest' => $manifestData,
            'package_sha256' => (string) $checksum,
            'artifact_sha256' => (string) $checksum,
            'deployed_tree_sha256' => $deployedTreeDigest,
            'trust_state' => 'verified',
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
        $installed = $this->findInstalled($identifier);
        try {
            $this->businessDefinitions?->synchronize(
                $identifier,
                (string) $manifest->version(),
                $site,
                $manifest->contributions()->fieldTypes(),
                $manifest->contributions()->businessDefinitions(),
                ($installed['status'] ?? null) === 'active',
                $actorId,
            );
        } catch (Throwable $failure) {
            $this->transactions->afterRollback(function () use ($actorId, $identifier, $failure): void {
                try {
                    $this->audit->record(new AuditEvent(
                        Uuid::uuid7()->toString(),
                        $this->clock->now(),
                        $actorId,
                        'business_definition.package.synchronize.reject',
                        'business_definition',
                        str_replace('/', ':', $identifier),
                        'rejected',
                        ['reason' => substr($failure->getMessage(), 0, 500)],
                    ));
                } catch (Throwable) {
                    // The synchronization failure remains authoritative if failure auditing is unavailable.
                }
            });
            throw $failure;
        }
    }

    private function synchronizeContributionCapabilities(ExtensionManifest $manifest, string $extensionId): void
    {
        $definitions = [];
        foreach ($manifest->contributions()->capabilities() as $definition) {
            $definitions[$definition->id] = $definition->description;
        }
        ksort($definitions, SORT_STRING);

        $existing = array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT capability_code FROM %s WHERE extension_id = ? ORDER BY capability_code',
            $this->tables->quoted('extension_contribution_capabilities'),
        ), [$extensionId]), 'is_string'));
        foreach ($definitions as $code => $description) {
            $owner = $this->database->fetchOne(sprintf(
                'SELECT extension_id FROM %s WHERE capability_code = ?',
                $this->tables->quoted('extension_contribution_capabilities'),
            ), [$code]);
            if ($owner !== false && $owner !== $extensionId) {
                throw new InvalidArgumentException(sprintf(
                    'Capability %s is already owned by another extension.',
                    $code,
                ));
            }
            $capabilityExists = $this->database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($capabilityExists !== false && $owner === false) {
                throw new InvalidArgumentException(sprintf('Capability %s collides with a core capability.', $code));
            }
            if ($capabilityExists === false) {
                $this->database->insert($this->tables->raw('capabilities'), [
                    'code' => $code,
                    'description' => $description,
                ]);
            }
            if ($owner === false) {
                $this->database->insert($this->tables->raw('extension_contribution_capabilities'), [
                    'extension_id' => $extensionId,
                    'capability_code' => $code,
                    'description' => $description,
                ]);
            } else {
                $this->database->update($this->tables->raw('extension_contribution_capabilities'), [
                    'description' => $description,
                ], ['capability_code' => $code]);
                $this->database->update($this->tables->raw('capabilities'), [
                    'description' => $description,
                ], ['code' => $code]);
            }
        }
        foreach (array_diff($existing, array_keys($definitions)) as $removed) {
            $this->deleteContributionCapability($removed);
        }
    }

    private function deleteContributionCapability(string $capability): void
    {
        $grants = array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE capability_code = ? ORDER BY id',
            $this->tables->quoted('role_capability_grants'),
        ), [$capability]), 'is_string'));
        foreach ($grants as $grant) {
            $this->ownership->remove(
                AuthorizationResource::item('grant', $grant),
                SiteContext::default(),
            );
        }
        $this->database->delete($this->tables->raw('capabilities'), ['code' => $capability]);
    }

    /** @return array<string, mixed> */
    private function contributionDiagnostics(ExtensionManifest $manifest, bool $active): array
    {
        $contributions = $manifest->contributions()->toArray();
        foreach ($contributions['capabilities'] as &$capability) {
            $capability['active'] = $active;
        }
        unset($capability);
        foreach (['workspaces', 'navigation', 'routes', 'views'] as $kind) {
            foreach ($contributions['administrator'][$kind] as &$item) {
                $item['active'] = $active;
            }
            unset($item);
        }
        foreach (['field_types', 'definitions'] as $kind) {
            foreach ($contributions['business'][$kind] as &$item) {
                $item['active'] = $active;
            }
            unset($item);
        }
        $contributions['active'] = $active;
        return $contributions;
    }

    private function themeSurfacePath(string $identifier, ThemeSurface $surface): string
    {
        $installed = $this->findInstalled($identifier);
        $runtime = $this->requiredString($installed, 'runtime_path');
        return $this->extensionRoot . '/' . $runtime . '/templates/' . $surface->value;
    }

    /** @return array<string, mixed> */
    private function themeActivation(ThemeSurface $surface, SiteContext $site): array
    {
        if ($surface === ThemeSurface::Site) {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT site_identifier, extension_id, version FROM %s WHERE site_identifier = ?',
                $this->tables->quoted('site_theme_activations'),
            ), [$site->identifier()]);
            if ($row === false) {
                $now = $this->clock->now();
                $this->database->insert($this->tables->raw('site_theme_activations'), [
                    'site_identifier' => $site->identifier(),
                    'extension_id' => null,
                    'version' => 1,
                    'activated_by' => null,
                    'activated_at' => $now,
                ], ['activated_at' => Types::DATETIME_IMMUTABLE]);

                return ['site_identifier' => $site->identifier(), 'extension_id' => null, 'version' => 1];
            }

            return $row;
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT surface, extension_id, version FROM %s WHERE surface = ?',
            $this->tables->quoted('theme_activations'),
        ), [$surface->value]);

        if ($row === false) {
            throw new RuntimeException(sprintf('The %s theme activation record is missing.', $surface->value));
        }

        return $row;
    }

    private function setThemeActivation(
        ThemeSurface $surface,
        ?string $extensionId,
        string $actorId,
        SiteContext $site,
    ): void {
        if ($surface === ThemeSurface::Site) {
            $this->themeActivation($surface, $site);
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET extension_id = ?, version = version + 1, activated_by = ?, activated_at = ? '
                . 'WHERE site_identifier = ?',
                $this->tables->quoted('site_theme_activations'),
            ), [$extensionId, $actorId, $this->clock->now(), $site->identifier()], [
                Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('The site theme activation could not be persisted.');
            }

            return;
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET extension_id = ?, version = version + 1, activated_by = ?, activated_at = ? '
            . 'WHERE surface = ?',
            $this->tables->quoted('theme_activations'),
        ), [$extensionId, $actorId, $this->clock->now(), $surface->value], [
            Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING,
        ]);

        if ($affected !== 1) {
            throw new RuntimeException(sprintf('The %s theme activation could not be persisted.', $surface->value));
        }
    }

    /** @param array<string, mixed> $installed */
    private function clearThemeActivations(array $installed, string $actorId, SiteContext $site): void
    {
        $extensionId = $this->requiredString($installed, 'id');
        foreach ([ThemeSurface::Site, ThemeSurface::Administrator] as $surface) {
            $activation = $this->themeActivation($surface, $site);
            if (($activation['extension_id'] ?? null) === $extensionId) {
                $this->setThemeActivation($surface, null, $actorId, $site);
            }
        }
    }

    private function disableThemeWhenUnused(string $extensionId): void
    {
        $count = $this->database->fetchOne(sprintf(
            'SELECT (SELECT COUNT(*) FROM %s WHERE extension_id = ?) '
            . '+ (SELECT COUNT(*) FROM %s WHERE extension_id = ?)',
            $this->tables->quoted('theme_activations'),
            $this->tables->quoted('site_theme_activations'),
        ), [$extensionId, $extensionId]);

        if ($this->databaseInteger($count, 'active theme assignment count') !== 0) {
            return;
        }

        $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'disabled', registry_version = registry_version + 1, updated_at = ? "
            . "WHERE id = ? AND extension_type = 'template'",
            $this->tables->quoted('extensions'),
        ), [$this->clock->now(), $extensionId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
    }

    private function assertAdministratorThemeMutationAllowed(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void {
        $installed = $this->findInstalled($identifier);
        if (
            ($installed['extension_type'] ?? null) === ExtensionType::Template->value
            && $this->isThemeActive($this->requiredString($installed, 'id'), ThemeSurface::Administrator)
        ) {
            $this->themeActivationGuard->assertAllowed(
                ThemeSurface::Administrator,
                $context,
                $stepUpCredential,
            );
        }
    }

    /** @param array<string, mixed> $installed */
    private function assertThemeCapabilities(
        array $installed,
        ExecutionContext $context,
    ): void {
        if (($installed['extension_type'] ?? null) !== ExtensionType::Template->value) {
            return;
        }

        $extensionId = $this->requiredString($installed, 'id');
        $surfaces = $this->database->fetchFirstColumn(sprintf(
            'SELECT surface FROM %s WHERE extension_id = ? ORDER BY surface',
            $this->tables->quoted('theme_activations'),
        ), [$extensionId]);
        foreach ($surfaces as $surface) {
            if (!is_string($surface) || ThemeSurface::tryFrom($surface) === null) {
                throw new RuntimeException('An active theme surface is invalid.');
            }
            $this->assertThemeCapability($context, ThemeSurface::from($surface));
        }
        $sites = $this->database->fetchFirstColumn(sprintf(
            'SELECT site_identifier FROM %s WHERE extension_id = ? ORDER BY site_identifier',
            $this->tables->quoted('site_theme_activations'),
        ), [$extensionId]);
        foreach ($sites as $siteIdentifier) {
            if (!is_string($siteIdentifier)) {
                throw new RuntimeException('An active site theme assignment is invalid.');
            }
            if ($siteIdentifier !== $context->site()->identifier()) {
                throw new RuntimeException(
                    'Manage the theme from each assigned site before disabling or uninstalling it.',
                );
            }
            $this->assertThemeCapability($context, ThemeSurface::Site);
        }
    }

    /** @param array<string, mixed> $installed */
    private function assertCurrentThemeCapabilities(
        array $installed,
        ExecutionContext $context,
    ): void {
        if (($installed['extension_type'] ?? null) !== ExtensionType::Template->value) {
            return;
        }

        $extensionId = $this->requiredString($installed, 'id');
        if ($this->isThemeActive($extensionId, ThemeSurface::Site, $context->site())) {
            $this->assertThemeCapability($context, ThemeSurface::Site);
        }
        if ($this->isThemeActive($extensionId, ThemeSurface::Administrator)) {
            $this->assertThemeCapability($context, ThemeSurface::Administrator);
        }
    }

    private function assertThemeCapability(ExecutionContext $context, ThemeSurface $surface): void
    {
        $this->themeAuthorization->assertSurface($context, $surface);
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            $resource,
        );
    }

    private function assertFence(ExtensionRegistryLease $lease): void
    {
        $lease->renew();
        $current = $this->database->fetchOne(sprintf(
            'SELECT fence FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        ));
        if ($this->databaseInteger($current, 'extension registry fence') !== $lease->fence()) {
            throw new RuntimeException('A newer extension registry lease fenced this operation.');
        }
    }

    private function assertCurrentFence(ExtensionRegistryLease $lease): void
    {
        $lease->renew();
        $current = $this->database->fetchOne(sprintf(
            'SELECT fence FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        ));
        if ($this->databaseInteger($current, 'extension registry fence') !== $lease->fence()) {
            throw new RuntimeException('A newer extension registry lease fenced this operation.');
        }
    }

    /** @return array<string, mixed> */
    private function beginInstallOperation(
        string $operationId,
        ExtensionManifest $manifest,
        string $packageSha256,
        string $runtimePath,
        string $stagingPath,
        string $actorId,
        SiteContext $site,
        ?string $signingKeyId,
        ?string $packageSignature,
        ExtensionRegistryLease $lease,
    ): array {
        return $this->transactions->transactional(function () use (
            $operationId,
            $manifest,
            $packageSha256,
            $runtimePath,
            $stagingPath,
            $actorId,
            $site,
            $signingKeyId,
            $packageSignature,
            $lease,
        ): array {
            $this->assertFence($lease);
            $existing = $this->database->fetchAssociative(sprintf(
                'SELECT * FROM %s WHERE operation_id = ?',
                $this->tables->quoted('extension_install_operations'),
            ), [$operationId]);
            if ($existing !== false) {
                foreach (
                    [
                    'identifier' => $manifest->identifier()->value(),
                    'version' => (string) $manifest->version(),
                    'package_sha256' => $packageSha256,
                    'runtime_path' => $runtimePath,
                    'staging_path' => $stagingPath,
                    'actor_id' => $actorId,
                    'site_identifier' => $site->identifier(),
                    'signing_key_id' => $signingKeyId,
                    'package_signature' => $packageSignature,
                    ] as $field => $expected
                ) {
                    if (($existing[$field] ?? null) !== $expected) {
                        throw new RuntimeException('An extension install operation ID collision was detected.');
                    }
                }
                if (($existing['transaction_outcome'] ?? null) !== ExtensionInstallOutcome::Committed->value) {
                    $this->database->update($this->tables->raw('extension_install_operations'), [
                        'fence' => $lease->fence(),
                        'updated_at' => $this->clock->now(),
                    ], ['operation_id' => $operationId], [
                        'fence' => Types::BIGINT, 'updated_at' => Types::DATETIME_IMMUTABLE,
                    ]);
                }

                return $existing;
            }
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('extension_install_operations'), [
                'operation_id' => $operationId,
                'identifier' => $manifest->identifier()->value(),
                'version' => (string) $manifest->version(),
                'package_sha256' => $packageSha256,
                'runtime_path' => $runtimePath,
                'staging_path' => $stagingPath,
                'actor_id' => $actorId,
                'site_identifier' => $site->identifier(),
                'signing_key_id' => $signingKeyId,
                'package_signature' => $packageSignature,
                'state' => 'staged',
                'transaction_outcome' => ExtensionInstallOutcome::Unknown->value,
                'fence' => $lease->fence(),
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'fence' => Types::BIGINT,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);

            return ['transaction_outcome' => ExtensionInstallOutcome::Unknown->value];
        });
    }

    private function markInstallOperation(
        string $operationId,
        string $state,
        ExtensionInstallOutcome $outcome,
        ExtensionRegistryLease $lease,
    ): void {
        $this->assertCurrentFence($lease);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET state = ?, transaction_outcome = ?, fence = ?, last_error = NULL, updated_at = ? '
            . 'WHERE operation_id = ? AND fence = ?',
            $this->tables->quoted('extension_install_operations'),
        ), [$state, $outcome->value, $lease->fence(), $this->clock->now(), $operationId, $lease->fence()], [
            Types::STRING, Types::STRING, Types::BIGINT, Types::DATETIME_IMMUTABLE, Types::GUID, Types::BIGINT,
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('The extension install saga was fenced before publication.');
        }
    }

    private function markInstallFailure(
        string $operationId,
        ExtensionInstallOutcome $outcome,
        Throwable $failure,
        ExtensionRegistryLease $lease,
    ): void {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET state = ?, transaction_outcome = ?, last_error = ?, updated_at = ? '
            . 'WHERE operation_id = ? AND fence = ?',
            $this->tables->quoted('extension_install_operations'),
        ), [
            match ($outcome) {
                ExtensionInstallOutcome::Committed => 'committed',
                ExtensionInstallOutcome::RolledBack => 'rolled_back',
                ExtensionInstallOutcome::Unknown => 'reconcile',
            },
            $outcome->value,
            substr($failure->getMessage(), 0, 4_096),
            $this->clock->now(),
            $operationId,
            $lease->fence(),
        ], [Types::STRING, Types::STRING, Types::TEXT, Types::DATETIME_IMMUTABLE, Types::GUID, Types::BIGINT]);
    }

    private function resolveInstallOutcome(
        ExtensionManifest $manifest,
        string $runtimePath,
    ): ExtensionInstallOutcome {
        try {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT installed_version, runtime_path FROM %s WHERE identifier = ?',
                $this->tables->quoted('extensions'),
            ), [$manifest->identifier()->value()]);
            if (
                $row !== false
                && ($row['installed_version'] ?? null) === (string) $manifest->version()
                && ($row['runtime_path'] ?? null) === $runtimePath
            ) {
                return ExtensionInstallOutcome::Committed;
            }
        } catch (Throwable) {
            // Connection failure means the commit outcome cannot be inferred safely.
        }

        return ExtensionInstallOutcome::Unknown;
    }

    private function isThemeActive(string $extensionId, ThemeSurface $surface, ?SiteContext $site = null): bool
    {
        if ($surface === ThemeSurface::Site) {
            $active = $this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE site_identifier = ? AND extension_id = ?',
                $this->tables->quoted('site_theme_activations'),
            ), [($site ?? SiteContext::default())->identifier(), $extensionId]);

            return $this->databaseInteger($active, 'site theme assignment count') === 1;
        }
        $active = $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE surface = ? AND extension_id = ?',
            $this->tables->quoted('theme_activations'),
        ), [$surface->value, $extensionId]);

        return $this->databaseInteger($active, 'administrator theme assignment count') === 1;
    }

    private function isAnyThemeActive(string $extensionId): bool
    {
        $active = $this->database->fetchOne(sprintf(
            'SELECT (SELECT COUNT(*) FROM %s WHERE extension_id = ?) '
            . '+ (SELECT COUNT(*) FROM %s WHERE extension_id = ?)',
            $this->tables->quoted('theme_activations'),
            $this->tables->quoted('site_theme_activations'),
        ), [$extensionId, $extensionId]);

        return $this->databaseInteger($active, 'theme assignment count') > 0;
    }

    private function databaseInteger(mixed $value, string $description): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('The ' . $description . ' is invalid.');
        }

        return (int) $value;
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

    private function snapshotArchive(string $source, string $operationRoot): string
    {
        $temporary = $operationRoot . '/package.copying';
        $snapshot = $operationRoot . '/package.zip';
        $pathStat = lstat($source);
        $input = fopen($source, 'rb');
        if ($input === false || !flock($input, LOCK_SH)) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('The extension archive could not be locked for snapshotting.');
        }
        $openStat = fstat($input);
        if (
            !is_array($pathStat) || !is_array($openStat)
            || ($openStat['mode'] & 0170000) !== 0100000
            || $pathStat['dev'] !== $openStat['dev']
            || $pathStat['ino'] !== $openStat['ino']
        ) {
            flock($input, LOCK_UN);
            fclose($input);
            throw new RuntimeException('The extension archive changed before its private snapshot was opened.');
        }
        $output = fopen($temporary, 'xb');
        if ($output === false) {
            flock($input, LOCK_UN);
            fclose($input);
            throw new RuntimeException('The private extension archive snapshot could not be created.');
        }
        try {
            $copied = stream_copy_to_stream($input, $output);
            if (!is_int($copied) || $copied !== $openStat['size'] || !fflush($output)) {
                throw new RuntimeException('The extension archive snapshot could not be copied completely.');
            }
            if (function_exists('fsync') && !fsync($output)) {
                throw new RuntimeException('The extension archive snapshot could not be synchronized.');
            }
        } finally {
            fclose($output);
            flock($input, LOCK_UN);
            fclose($input);
        }
        chmod($temporary, 0400);
        if (!rename($temporary, $snapshot)) {
            throw new RuntimeException('The extension archive snapshot could not be activated atomically.');
        }

        return $snapshot;
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
            $relativeDestination = $this->runtimePath($manifest) . '/' . $asset;
            $this->ensureBoundedDirectory($this->publicAssetRoot, dirname($relativeDestination), 0755);
            $destination = $this->publicAssetRoot . '/' . $relativeDestination;
            $temporary = $destination . '.tmp.' . bin2hex(random_bytes(8));
            try {
                if (!copy($source, $temporary)) {
                    throw new RuntimeException(sprintf('Extension asset %s could not be published.', $asset));
                }
                chmod($temporary, 0644);
                if (!rename($temporary, $destination)) {
                    throw new RuntimeException(sprintf('Extension asset %s could not be activated.', $asset));
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
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
        ?ExtensionRegistryLease $lease = null,
    ): void {
        $this->events->dispatch($name, new Event($name, [
            'identifier' => $manifest->identifier()->value(),
            'version' => (string) $manifest->version(),
            'actor_id' => $actorId,
            'registry_fence' => $lease?->fence(),
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

    private function ensureBoundedDirectory(string $root, string $relative, int $mode): void
    {
        if ($mode === 0755) {
            $this->ensurePublicDirectory($root);
        } else {
            $this->ensureDirectory($root);
        }
        if (is_link($root)) {
            throw new RuntimeException('Extension storage roots may not be symbolic links.');
        }
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new RuntimeException('The extension storage root cannot be resolved.');
        }
        $current = rtrim($root, '/');
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('An extension storage path is invalid.');
            }
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new RuntimeException('Extension storage paths may not contain symbolic links.');
            }
            if (!is_dir($current)) {
                if ($mode === 0755) {
                    $this->ensurePublicDirectory($current);
                } else {
                    $this->ensureDirectory($current);
                }
            }
            $resolved = realpath($current);
            if (!is_string($resolved) || !str_starts_with($resolved . '/', $resolvedRoot . '/')) {
                throw new RuntimeException('An extension storage path escapes its configured root.');
            }
        }
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
