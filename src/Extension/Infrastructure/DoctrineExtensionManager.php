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

/**
 * Doctrine-backed extension registry: where installing, activating and removing an extension happens.
 *
 * Delivery code does not depend on this class. Every mutating method takes an `ExtensionRegistryLease`
 * because the registry never acquires one itself: `RedisLockedExtensionManager` is what the container
 * binds to `ExtensionManager`, and it is the caller that holds the extension lifecycle lock, the
 * cross-process registry lease and the database fence handed inward here. Each write re-reads that
 * fence, so an operation whose lease was superseded is refused instead of overwriting newer work.
 * Authorization is asserted again on this side of the decorator, and `installed()` filters rows against
 * the actor's grants rather than refusing the read outright.
 *
 * Installing is a durable saga rather than a transaction, because it spans a filesystem publication and
 * a database commit that cannot be made atomic together. An operation ID derived from the identifier,
 * version and package digest is recorded in `extension_install_operations` before anything is staged,
 * its outcome stays `Unknown` until a commit is observed, and `reconcileInstallOperations()` is the only
 * authority allowed to finalize or retire what an interrupted attempt left behind. The filesystem side
 * belongs here too: a locked private snapshot of the archive, extraction into `.staging`, an atomic
 * rename into the runtime path, asset publication under the public root, and retirement of superseded
 * trees through `ExtensionRuntimeMapCompiler`.
 *
 * @since  2.0.0
 */
final readonly class DoctrineExtensionManager
{
    /**
     * Wire the registry to its database, its two storage roots and the collaborators a lifecycle change needs.
     *
     * @param  Connection                      $database              Connection every extension table read
     *         and write goes through.
     * @param  TableNames                      $tables                Prefix-aware resolver for the extension
     *         tables this class names in SQL.
     * @param  string                          $extensionRoot         Absolute path of the private tree
     *         deployed packages, staging directories and archive snapshots live under.
     * @param  string                          $publicAssetRoot       Absolute path of the web-readable tree
     *         declared package assets are published into.
     * @param  ArchiveReader                   $archives              Reader that lists an archive's entry
     *         table without extracting it.
     * @param  PackageSafetyPolicy             $safety                Policy that must accept that entry table
     *         before anything is unpacked.
     * @param  ExtensionMigrationRunner        $migrations            Runner applying the manifest's declared
     *         migrations against the deployed tree.
     * @param  ExtensionRuntimeMapCompiler     $compiler              Compiler staged on every registry
     *         change, and the owner of runtime path retirement.
     * @param  TransactionManager              $transactions          Boundary each registry mutation runs
     *         inside, and the source of after-rollback hooks.
     * @param  AuditRecorder                   $audit                 Sink every lifecycle change is recorded
     *         to.
     * @param  ClockInterface                  $clock                 Clock stamping install, update and
     *         activation timestamps.
     * @param  DispatcherInterface             $events                Dispatcher the before and after
     *         lifecycle events are published on.
     * @param  ThemeActivationGuard            $themeActivationGuard  Guard demanding step-up authentication
     *         before a protected surface changes theme.
     * @param  ThemePackageValidator           $themeValidator        Validator asserting a template package
     *         supplies the files its surface needs.
     * @param  ThemeMutationAuthorizer         $themeAuthorization    Authorizer of the per-surface theme
     *         management capability.
     * @param  TrustStore                      $trust                 Trust store deciding whether a package's
     *         digest and signature may be installed.
     * @param  AuthorizationGateway            $authorization         Gateway asked for `extensions.manage` on
     *         the extension collection or one extension.
     * @param  ResourceSiteOwnershipWriter     $ownership             Writer recording and removing the site
     *         that owns an extension resource.
     * @param  ?PackageDefinitionSynchronizer  $businessDefinitions   Synchronizer for the field types and
     *         business definitions a package contributes; null when the installation ships none.
     *
     * @since  2.0.0
     */
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

    /**
     * List the installed extensions the actor may manage, enriched with what an operator screen shows.
     *
     * Each row is the registry record joined to the release matching its installed version, with the raw
     * manifest column replaced by the two derived fields a screen needs: `manifest_schema` and a
     * `contributions` diagnostic whose entries are stamped with whether the extension is currently active.
     * `theme_surfaces` names every surface a template is activated on, with a per-site assignment spelled
     * `site:<identifier>`. Filtering is by omission rather than refusal, so an empty list means either
     * nothing is installed or nothing is visible to this actor.
     *
     * @param   ExecutionContext  $context  Actor and site each row's `extensions.manage` decision is taken
     *          for.
     *
     * @return  list<array<string, mixed>>  Rows ordered by extension identifier, renumbered from zero after
     *          the authorization filter.
     *
     * @throws  InvalidArgumentException  When a stored release manifest can no longer be parsed.
     *
     * @since   2.0.0
     */
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

    /**
     * Settle a batch of install operations whose outcome was never observed.
     *
     * This is the only authority allowed to finish or retire what an interrupted install left behind, and
     * it takes the oldest twenty-five unresolved rows per pass. Each row is claimed by stamping the
     * caller's fence onto it; a staging directory that never reached its runtime path is renamed into
     * place, and the retained `.kumwe-package.zip` decides the rest. When that archive is present the
     * install is simply replayed from it. When it is gone the operation is recorded as committed if the
     * registry already carries that exact version and runtime path, and otherwise rolled back with both
     * trees and the published assets removed. A replay that throws is deliberately swallowed so the row
     * stays unresolved: readiness keeps failing closed and the next pass retries it.
     *
     * @param   ExtensionRegistryLease  $lease  Held registry lease whose fence claims each operation row and
     *          gates every write made while settling it.
     *
     * @return  int  How many operations this pass moved to a settled outcome; 0 when none were pending.
     *
     * @throws  RuntimeException  When the lease has been fenced by a newer one, a stored operation field is
     *          unusable, or an artifact outside extension storage would have to be removed.
     * @throws  InvalidArgumentException  When a recorded runtime path is not a safe path to recreate.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether any install operation is still waiting to be settled.
     *
     * Answered straight from the durable operation ledger without a lease or a fence, so callers may poll
     * it cheaply to decide whether reconciliation is worth taking the lifecycle lock for.
     *
     * @return  bool  True while at least one operation's recorded outcome is still `unknown`.
     *
     * @throws  RuntimeException  When the count cannot be read back as an integer.
     *
     * @since   2.0.0
     */
    public function hasPendingInstallOperations(): bool
    {
        return $this->databaseInteger($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE transaction_outcome = 'unknown'",
            $this->tables->quoted('extension_install_operations'),
        )), 'pending extension install count') > 0;
    }

    /**
     * Install an extension package from an archive on disk, under a lease the caller already holds.
     *
     * The capability check runs against the extension collection before the archive is touched; from
     * there this is the same install path a replay drives, so an operator-driven install and a
     * reconciled one converge on one durable operation row. The caller keeps ownership of the archive
     * file, which is snapshotted rather than consumed.
     *
     * @param   string                  $archiveFile      Path to the extension ZIP to install; must name a
     *          regular file rather than a symbolic link.
     * @param   ExecutionContext        $context          Actor and site the install is authorized against,
     *          audited to, and whose site takes ownership of the new extension resource.
     * @param   ExtensionRegistryLease  $lease            Held registry lease whose fence gates every write
     *          this install makes.
     * @param   ?string                 $signingKeyId     Trust-store key vouching for the package, or null
     *          when the package is offered unsigned.
     * @param   ?string                 $base64Signature  Base64 detached signature over the package bytes,
     *          supplied together with `$signingKeyId`.
     *
     * @return  array<string, mixed>  Registry row for the extension as it now stands, carrying the version
     *          just installed and the runtime path its files were published to.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions.
     * @throws  \Kumwe\CMS\Extension\Application\Trust\UntrustedPackage  When the trust store refuses the
     *          package's digest or signature.
     * @throws  InvalidArgumentException  When the archive path, its manifest, its dependencies or its
     *          version ordering is rejected.
     * @throws  RuntimeException  When the lease has been fenced, or a staging, publication or database step
     *          of the install fails.
     *
     * @since   2.0.0
     */
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

    /**
     * Take a private snapshot of the caller's archive and install from that copy.
     *
     * The archive lives wherever the caller put it, which means it can still be rewritten while the
     * install reads it. Copying it into a fresh `0700` directory under `.staging/.incoming` first is what
     * removes that window, and the snapshot is deleted again on both the success and the failure path so
     * an abandoned install leaves no package bytes behind.
     *
     * @param   string                  $archiveFile      Caller-supplied path to the extension ZIP; must
     *          resolve to a regular file rather than a symbolic link.
     * @param   string                  $actorId          Identifier recorded as the actor on the operation
     *          row, the audit entry and the dispatched events.
     * @param   SiteContext             $site             Site recorded on the operation row and given
     *          ownership of the extension resource.
     * @param   ExtensionRegistryLease  $lease            Held registry lease whose fence gates every write
     *          this install makes.
     * @param   ?string                 $signingKeyId     Trust-store key vouching for the package, or null
     *          when the package is offered unsigned.
     * @param   ?string                 $base64Signature  Base64 detached signature over the package bytes,
     *          supplied together with `$signingKeyId`.
     *
     * @return  array<string, mixed>  Registry row for the extension as it now stands.
     *
     * @throws  \Kumwe\CMS\Extension\Application\Trust\UntrustedPackage  When the trust store refuses the
     *          package's digest or signature.
     * @throws  InvalidArgumentException  When the path does not identify a regular file, or the package
     *          itself is rejected.
     * @throws  RuntimeException  When the snapshot cannot be taken, or the install fails after it.
     *
     * @since   2.0.0
     */
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

    /**
     * Run the install saga against a snapshot that cannot change underneath it.
     *
     * Ordering carries the guarantees. Archive safety, package trust, Kumwe and PHP compatibility and
     * declared dependencies are all decided before anything is extracted, and upgrading an extension that
     * still holds a theme surface is refused outright. The durable operation row is opened next, keyed by
     * an ID derived from the identifier, version and package digest, so a retry of the same package
     * re-enters the same operation instead of starting a second one; an operation already marked
     * committed short-circuits to the stored registry row when it matches, and is reopened when it does
     * not. Only then is the package extracted into `.staging`, renamed into its runtime path atomically,
     * digested as a deployed tree and published as assets. Inside the transaction the archive digest and
     * the trust decision are taken again, so a package swapped between staging and commit is refused.
     *
     * The failure path is what makes the saga recoverable. Failing while extracting or retaining the
     * package rolls the operation back and removes the staging tree, because nothing can have taken
     * effect yet. Failing after that records `Committed` only when the commit had been attempted and the
     * registry can still be read to prove the release landed, and records `Unknown` in every other case —
     * and either way the staged and published bytes are deliberately kept for
     * `reconcileInstallOperations()` to judge. A failure after a successful commit is simply rethrown.
     * On MySQL and MariaDB the migrations run before the transaction opens, because their DDL commits
     * implicitly; every other platform runs them inside it.
     *
     * @param   string                  $archiveFile      Path of the private archive snapshot to install
     *          from; it must still digest to the same bytes at commit time.
     * @param   string                  $actorId          Identifier recorded as the actor on the operation
     *          row, the audit entry and the dispatched events.
     * @param   SiteContext             $site             Site recorded on the operation row and given
     *          ownership of the extension resource.
     * @param   ExtensionRegistryLease  $lease            Held registry lease whose fence is re-read before
     *          each write and again immediately before publication.
     * @param   ?string                 $signingKeyId     Trust-store key vouching for the package, or null
     *          when the package is offered unsigned.
     * @param   ?string                 $base64Signature  Base64 detached signature over the package bytes,
     *          supplied together with `$signingKeyId`.
     *
     * @return  array<string, mixed>  Registry row for the extension after the release is persisted, or the
     *          row an earlier committed attempt already left when this call is a replay of it.
     *
     * @throws  \Kumwe\CMS\Extension\Application\Trust\UntrustedPackage  When the trust store refuses the
     *          package's digest or signature, before staging or again at commit time.
     * @throws  InvalidArgumentException  When the archive, its manifest, its dependencies or its version
     *          ordering is rejected, or an active theme would be upgraded in place.
     * @throws  RuntimeException  When the lease has been fenced, the package digest cannot be taken, the
     *          staged tree cannot be published atomically, or the archive changed before finalization.
     *
     * @since   2.0.0
     */
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

    /**
     * Activate an installed extension so the next compiled runtime map carries it.
     *
     * A template is bound to exactly one presentation surface, so `$surface` is required for a template
     * and refused for every other kind of extension. Claiming a surface runs three checks the plain
     * status change does not: the actor must hold that surface's theme-management capability, the
     * activation guard may demand step-up authentication, and the package's templates for that surface
     * must compile. The theme previously bound to the surface is disabled when nothing else still uses
     * it. Before and after events are dispatched around the change and carry the lease's fence.
     *
     * @param   string                  $identifier        `vendor/name` identifier of the installed
     *          extension.
     * @param   ExecutionContext        $context           Actor and site the activation is authorized
     *          against.
     * @param   ExtensionRegistryLease  $lease             Held registry lease whose fence gates the status
     *          change.
     * @param   ?ThemeSurface           $surface           Presentation surface a template is being
     *          activated on; null for every non-template extension.
     * @param   ?string                 $stepUpCredential  The actor's current password, re-supplied when
     *          the surface demands step-up authentication; null when none is being offered.
     *
     * @return  array<string, mixed>  Registry row for the extension after the status change.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage this
     *          extension, or may not manage the requested surface.
     * @throws  \Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired  When the surface demands a
     *          step-up the supplied credential does not satisfy.
     * @throws  InvalidArgumentException  When the identifier is malformed, a template names no surface, a
     *          non-template names one, or the theme package fails validation.
     * @throws  RuntimeException  When the lease has been fenced, the installed manifest is unavailable, or
     *          the activation cannot be persisted.
     *
     * @since   2.0.0
     */
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

    /**
     * Disable an installed extension so it stops contributing to the compiled runtime map.
     *
     * The files and the release record stay where they are, which makes this the reversible half of
     * removal. Disabling a template releases it from the administrator surface and from this actor's own
     * site, and demands step-up authentication when it is the administrator theme, because the back
     * office renders with whatever is bound there. A template still assigned to another site is released
     * from this one but deliberately left recorded as active, so the sites still using it keep rendering.
     *
     * @param   string                  $identifier        `vendor/name` identifier of the installed
     *          extension.
     * @param   ExecutionContext        $context           Actor and site the change is authorized against,
     *          and whose site's theme binding is released.
     * @param   ExtensionRegistryLease  $lease             Held registry lease whose fence gates the status
     *          change.
     * @param   ?string                 $stepUpCredential  The actor's current password, re-supplied when
     *          the administrator theme is being disabled; null when none is being offered.
     *
     * @return  array<string, mixed>  Registry row for the extension after the change; its status is still
     *          `active` when another site's binding survives.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage this
     *          extension, or may not manage a surface it is bound to.
     * @throws  \Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired  When the administrator
     *          surface demands a step-up the supplied credential does not satisfy.
     * @throws  InvalidArgumentException  When the identifier is malformed or no such extension is installed.
     * @throws  RuntimeException  When the lease has been fenced, the installed manifest is unavailable, or
     *          a theme binding cannot be released.
     *
     * @since   2.0.0
     */
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

    /**
     * Remove an extension from the registry and retire the tree it was serving from.
     *
     * This is the one lifecycle change the registry cannot undo: the extension row, the capabilities the
     * package contributed and the resource ownership of the grants those capabilities backed all go with
     * it. A template still bound to another site is refused rather than silently unbound, so removal
     * cannot break a site the actor is not working on. The runtime directory is scheduled for retirement
     * behind the compiler's generation counter instead of being deleted, so processes still serving an
     * older compiled map keep reading the files they hold until they drain.
     *
     * @param   string                  $identifier        `vendor/name` identifier of the installed
     *          extension.
     * @param   ExecutionContext        $context           Actor and site the removal is authorized
     *          against, and whose site must own every surviving theme binding.
     * @param   ExtensionRegistryLease  $lease             Held registry lease whose fence gates the
     *          removal transaction.
     * @param   ?string                 $stepUpCredential  The actor's current password, re-supplied when
     *          the administrator theme is being removed; null when none is being offered.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage this
     *          extension, or may not manage a surface it is bound to.
     * @throws  \Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired  When the administrator
     *          surface demands a step-up the supplied credential does not satisfy.
     * @throws  InvalidArgumentException  When the identifier is malformed or no such extension is installed.
     * @throws  RuntimeException  When the lease has been fenced, the installed extension records no runtime
     *          path, or the theme is still assigned to another site.
     *
     * @since   2.0.0
     */
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

    /**
     * Apply a status change and the theme bookkeeping that goes with it, in one registry transaction.
     *
     * Everything the change touches has to move together: the surface binding, the status column and its
     * registry version, the business-definition activation flag, the audit entry, and the staging of a
     * new runtime map generation. Binding a surface displaces whatever theme held it, and that theme is
     * disabled when no other surface or site still uses it. A template being disabled while another
     * binding survives is written back as `active`, so the stored status stays truthful about what is
     * actually being served. The fence is re-read as the transaction opens, so a superseded operation
     * lands none of it.
     *
     * @param   string                  $identifier  `vendor/name` identifier of the installed extension.
     * @param   string                  $status      Status the caller is asking for: `active` or
     *          `disabled`.
     * @param   string                  $action      Label used for the audit entry and the compiler
     *          staging reason, such as `extension.activate`.
     * @param   string                  $actorId     Identifier recorded as the actor on the audit entry
     *          and on any theme binding written.
     * @param   ExtensionRegistryLease  $lease       Held registry lease whose fence gates the transaction.
     * @param   ?ThemeSurface           $surface     Surface to bind this extension to, or null when no
     *          binding is being claimed.
     * @param   ?ExecutionContext       $context     Actor and site; required whenever a theme binding is
     *          created or released, and null only for a change that touches none.
     *
     * @return  array<string, mixed>  Registry row read back inside the same transaction, so it already
     *          reflects the status actually persisted.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage a
     *          surface this change binds or releases.
     * @throws  InvalidArgumentException  When no such extension is installed.
     * @throws  RuntimeException  When the lease has been fenced, a theme change arrives without an
     *          execution context, or a theme binding cannot be persisted.
     *
     * @since   2.0.0
     */
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

    /**
     * Write the registry and release rows that make this version the installed one.
     *
     * A first install creates the extension row as `disabled` and records the site that owns the
     * resource. An upgrade must keep the extension type it was first installed with and must move
     * strictly forwards, and a template upgrade is forced back to `disabled` with its bindings released,
     * so an operator re-activates it deliberately rather than having new templates appear mid-request.
     * The release row keeps the manifest, both package digests, the deployed tree digest later trust
     * checks compare against, and the signature when one was offered. Business-definition
     * synchronization runs last and its rejection is audited from an after-rollback hook, because the
     * transaction that would otherwise carry the audit entry is the one being rolled back.
     *
     * @param   ExtensionManifest  $manifest            Parsed manifest of the version being installed.
     * @param   string             $manifestJson        The manifest exactly as read from the package,
     *          stored verbatim on the release row.
     * @param   PackageChecksum    $checksum            SHA-256 of the package bytes, recorded as both the
     *          package and the artifact digest.
     * @param   ?PackageSignature  $signature           Signature the trust store accepted, or null when
     *          the package was accepted unsigned.
     * @param   string             $relativeRuntime     Path below the extension root the files were
     *          published to.
     * @param   SiteContext        $site                Site that takes ownership of the resource and that
     *          contributed definitions are synchronized for.
     * @param   string             $deployedTreeDigest  Digest of the published tree, stored so a later
     *          integrity check can detect tampering on disk.
     * @param   string             $actorId             Identifier recorded as the actor on definition
     *          synchronization and on its rejection audit.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an upgrade changes the extension type, is not strictly newer
     *          than the installed version, or contributes a capability that is already owned.
     * @throws  RuntimeException  When a stored field of the existing registry row is unusable.
     *
     * @since   2.0.0
     */
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

    /**
     * Reconcile the capability codes a package contributes with what the registry already holds.
     *
     * Contributed codes are exclusive. A code another extension owns, or one that already exists as a
     * core capability, is refused rather than taken over, so an extension cannot quietly widen its own
     * authority by claiming a name. Codes this extension still declares have their descriptions
     * refreshed in both the capability catalogue and the contribution table, and codes it has stopped
     * declaring are deleted — which is what keeps an upgrade from leaving behind grants for capabilities
     * the package no longer defines.
     *
     * @param   ExtensionManifest  $manifest     Manifest whose contributed capability definitions are
     *          authoritative for this extension.
     * @param   string             $extensionId  Registry UUID the contributed codes are owned by.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a contributed code is owned by another extension or collides
     *          with a core capability.
     *
     * @since   2.0.0
     */
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

    /**
     * Retire a contributed capability together with everything that referenced it.
     *
     * Deleting the catalogue row cascades the role grants that named the code, but nothing cascades the
     * resource-ownership record each of those grants carries. Those are removed first, so no ownership
     * row is left describing a grant that no longer exists.
     *
     * @param   string  $capability  Capability code being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Describe a manifest's contributions with each entry marked live or dormant.
     *
     * What a package declares lives in its manifest and whether it is switched on lives in the registry,
     * so an operator screen needs both to explain why a declared route or field type is not reachable.
     * The flag is stamped on every contributed capability, on every administrator workspace, navigation
     * entry, route and view, on every business field type and definition, and on the set as a whole.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose declared contribution set is described.
     * @param   bool               $active    Whether the extension's registry status is currently `active`.
     *
     * @return  array<string, mixed>  The contribution set as declared, with an `active` flag added at the
     *          top level and on every individual entry.
     *
     * @since   2.0.0
     */
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

    /**
     * Locate the template directory a package supplies for one presentation surface.
     *
     * The path is built from the runtime path recorded on the registry row, so validation always looks
     * at the copy that is actually deployed rather than at a staging tree.
     *
     * @param   string        $identifier  `vendor/name` identifier of the installed extension.
     * @param   ThemeSurface  $surface     Surface whose template directory is wanted.
     *
     * @return  string  Absolute path to `templates/<surface>` inside the deployed package.
     *
     * @throws  InvalidArgumentException  When no such extension is installed.
     * @throws  RuntimeException  When the installed row records no usable runtime path.
     *
     * @since   2.0.0
     */
    private function themeSurfacePath(string $identifier, ThemeSurface $surface): string
    {
        $installed = $this->findInstalled($identifier);
        $runtime = $this->requiredString($installed, 'runtime_path');
        return $this->extensionRoot . '/' . $runtime . '/templates/' . $surface->value;
    }

    /**
     * Read the activation record that says which extension currently holds a surface.
     *
     * The two surfaces are stored differently and treated differently here. The administrator binding is
     * a single global row that must already exist, so a missing one is a broken installation rather than
     * an empty state. A site binding is per site and is created empty on first use, then returned as if
     * it had always been there, which is what lets a newly added site be themed without a provisioning
     * step of its own.
     *
     * @param   ThemeSurface  $surface  Surface whose current binding is read.
     * @param   SiteContext   $site     Site the binding is read for; ignored for the administrator
     *          surface.
     *
     * @return  array<string, mixed>  The activation row, whose `extension_id` is null when the surface
     *          currently has no theme bound.
     *
     * @throws  RuntimeException  When the administrator activation row is missing.
     *
     * @since   2.0.0
     */
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

    /**
     * Point a surface at an extension, or at nothing, and bump the binding's version.
     *
     * The version column is how a rendering process notices that a theme changed under it, so it is
     * incremented on every write rather than only when the extension differs. Exactly one row must
     * change; anything else means the binding was not persisted and is reported instead of assumed.
     *
     * @param   ThemeSurface  $surface      Surface whose binding is being written.
     * @param   ?string       $extensionId  Registry UUID to bind, or null to leave the surface unthemed.
     * @param   string        $actorId      Identifier recorded as the actor on the binding.
     * @param   SiteContext   $site         Site the binding is written for; ignored for the administrator
     *          surface.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the update did not affect exactly one activation row.
     *
     * @since   2.0.0
     */
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

    /**
     * Release this extension from both surfaces, leaving bindings that point elsewhere untouched.
     *
     * Only the caller's own site is considered, so a theme another site still binds keeps that binding
     * and keeps being served there.
     *
     * @param   array<string, mixed>  $installed  Registry row of the extension being released.
     * @param   string                $actorId    Identifier recorded as the actor on each binding cleared.
     * @param   SiteContext           $site       Site whose binding is examined; other sites keep theirs.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the row carries no usable id, or a binding cannot be persisted.
     *
     * @since   2.0.0
     */
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

    /**
     * Disable a template that no surface and no site still binds.
     *
     * Called once a surface has been handed to a different theme. The count spans both the administrator
     * and the per-site activation tables, so a theme that is still serving another site keeps its
     * `active` status and its place in the compiled runtime map.
     *
     * @param   string  $extensionId  Registry UUID of the template that just lost a binding.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the binding count cannot be read back as an integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Demand step-up authentication before a change touches the theme the back office renders with.
     *
     * The guard is consulted only when the extension really is the template currently bound to the
     * administrator surface, so disabling or removing an ordinary extension never makes an operator
     * re-enter a password for no reason.
     *
     * @param   string            $identifier        `vendor/name` identifier of the extension being
     *          changed.
     * @param   ExecutionContext  $context           Actor and site the step-up is evaluated for.
     * @param   ?string           $stepUpCredential  The actor's current password, or null when none was
     *          offered.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired  When the administrator
     *          theme is affected and the supplied credential does not satisfy the guard.
     * @throws  InvalidArgumentException  When no such extension is installed.
     * @throws  RuntimeException  When the registry row carries no usable id, or the binding count cannot be
     *          read back as an integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Require every theme capability the extension's live bindings imply, across all sites.
     *
     * This is the check for changes that reach every surface at once — disabling or removing a template
     * — so it walks each administrator and per-site binding the extension holds. A binding owned by a
     * different site is refused rather than authorized, because an actor working in one site cannot be
     * asked for a capability in another. An extension that is not a template needs none of this and
     * returns immediately.
     *
     * @param   array<string, mixed>  $installed  Registry row of the extension about to change.
     * @param   ExecutionContext      $context    Actor and site the surface capabilities are checked
     *          against.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage a
     *          surface this extension is bound to.
     * @throws  RuntimeException  When the row carries no usable id, a stored surface or site assignment is
     *          invalid, or the theme is still bound by another site.
     *
     * @since   2.0.0
     */
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

    /**
     * Require the theme capabilities for just the bindings this request would release.
     *
     * Narrower than the all-sites check on purpose: only this actor's own site binding and the global
     * administrator binding are considered, which is exactly the set a status change clears. An
     * extension that is not a template returns immediately.
     *
     * @param   array<string, mixed>  $installed  Registry row of the extension about to change.
     * @param   ExecutionContext      $context    Actor and site whose bindings are examined.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage a
     *          surface this change would release.
     * @throws  RuntimeException  When the row carries no usable id, or a binding count cannot be read back
     *          as an integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Require the theme-management capability that governs one presentation surface.
     *
     * @param   ExecutionContext  $context  Actor and site the capability is resolved for.
     * @param   ThemeSurface      $surface  Surface whose theme-management capability is demanded.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When policy refuses the actor this
     *          surface.
     *
     * @since   2.0.0
     */
    private function assertThemeCapability(ExecutionContext $context, ThemeSurface $surface): void
    {
        $this->themeAuthorization->assertSurface($context, $surface);
    }

    /**
     * Require `extensions.manage` on the extension collection or on one extension.
     *
     * The decorator that acquires the locks asks the same question before it takes any of them. Asking
     * it again here means a caller that already holds a lease of its own still cannot reach a registry
     * mutation unauthorized.
     *
     * @param   ExecutionContext       $context   Actor, site and provenance the decision is taken for.
     * @param   AuthorizationResource  $resource  Extension collection, or the single extension the call
     *          targets.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When policy refuses the actor this
     *          action on this resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            $resource,
        );
    }

    /**
     * Refuse the operation when a newer lease has taken the registry over.
     *
     * A distributed lock alone cannot prove its holder still owns the registry: a lock can expire while
     * the holder carries on believing it is alone. So the lease is renewed first — which raises if the
     * lock has already moved on — and then the fence stored in the singleton registry row is compared
     * with the fence this operation was issued. A stored fence that has moved past it means another
     * operation was admitted in the meantime and this one must not write.
     *
     * @param   ExtensionRegistryLease  $lease  Lease the operation is running under.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the lock has been lost, the stored fence cannot be read as an
     *          integer, or it no longer matches this lease.
     *
     * @since   2.0.0
     */
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

    /**
     * Re-read the fence immediately before a publication step.
     *
     * The check itself is the one `assertFence()` makes. It carries a separate name because it marks the
     * late re-check points — just before an operation row is marked, and just before a release is
     * persisted — where what matters is how little happens between the check and the write.
     *
     * @param   ExtensionRegistryLease  $lease  Lease the operation is running under.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the lock has been lost, the stored fence cannot be read as an
     *          integer, or it no longer matches this lease.
     *
     * @since   2.0.0
     */
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

    /**
     * Open, or re-enter, the durable row that records how this install ends.
     *
     * The row exists before any bytes are staged, so an interruption always leaves something to
     * reconcile against. Re-entering it is the normal case for a retry, and the stored row is compared
     * field by field against the request first: two different installs that derived the same operation
     * ID are refused rather than merged. An operation that has not committed has the caller's fence
     * stamped onto it, so ownership of the saga follows the current lease.
     *
     * @param   string                  $operationId       Deterministic ID derived from the identifier,
     *          version and package digest.
     * @param   ExtensionManifest       $manifest          Manifest whose identifier and version the
     *          operation is recorded under.
     * @param   string                  $packageSha256     SHA-256 of the package bytes, part of the
     *          operation's identity.
     * @param   string                  $runtimePath       Path below the extension root the install
     *          intends to publish to.
     * @param   string                  $stagingPath       Path below the extension root the package is
     *          extracted into first.
     * @param   string                  $actorId           Identifier recorded as the actor on the
     *          operation.
     * @param   SiteContext             $site              Site recorded on the operation, so a replay
     *          ends up owned by the same site.
     * @param   ?string                 $signingKeyId      Trust-store key offered with the package, or
     *          null when it was offered unsigned.
     * @param   ?string                 $packageSignature  Base64 detached signature offered with the
     *          package, or null when it was offered unsigned.
     * @param   ExtensionRegistryLease  $lease             Held registry lease whose fence is claimed on
     *          the row.
     *
     * @return  array<string, mixed>  The stored row when an existing operation is re-entered, otherwise a
     *          minimal row carrying the `unknown` outcome a fresh operation starts at.
     *
     * @throws  RuntimeException  When the lease has been fenced, or a stored operation with this ID
     *          describes a different install.
     *
     * @since   2.0.0
     */
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

    /**
     * Advance an operation's recorded state, refusing to write under a fence that has moved.
     *
     * The update matches on the fence as well as on the operation ID, so a saga fenced out between the
     * check and the write changes nothing and is told so rather than reporting success. That is what
     * stops a superseded install from recording itself as committed.
     *
     * @param   string                   $operationId  ID of the operation row being advanced.
     * @param   string                   $state        Saga step recorded on the row, such as `migrating`
     *          or `committed`.
     * @param   ExtensionInstallOutcome  $outcome      Durable outcome the row now claims.
     * @param   ExtensionRegistryLease   $lease        Held registry lease whose fence the row must still
     *          carry.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the lease has been fenced, or the update matched no row.
     *
     * @since   2.0.0
     */
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

    /**
     * Record why an install stopped and what that leaves behind.
     *
     * The state written follows the outcome: `committed`, `rolled_back`, or `reconcile` for the ambiguous
     * case only reconciliation may settle. Unlike the success path this makes no assertion about rows
     * affected, because it runs from failure handling where a fenced-out operation must not turn one
     * error into two. The failure message is truncated so an unbounded exception text cannot be written
     * into the ledger.
     *
     * @param   string                   $operationId  ID of the operation row being closed out.
     * @param   ExtensionInstallOutcome  $outcome      Durable outcome to record, which also chooses the
     *          state label.
     * @param   Throwable                $failure      Failure whose message is stored, truncated to 4096
     *          characters.
     * @param   ExtensionRegistryLease   $lease        Held registry lease whose fence the row must still
     *          carry for the write to match.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether a commit that was attempted actually landed.
     *
     * The question is answered only from the registry: the outcome is `Committed` when the extension row
     * already carries this exact version and runtime path. Everything else — a row that disagrees, or a
     * connection too broken to ask at all — is reported as `Unknown`, which retains the staged and
     * published bytes for reconciliation rather than discarding work that may have succeeded.
     *
     * @param   ExtensionManifest  $manifest     Manifest of the version whose commit is in question.
     * @param   string             $runtimePath  Path below the extension root the install expected to
     *          publish to.
     *
     * @return  ExtensionInstallOutcome  `Committed` only when the registry proves the commit landed;
     *          `Unknown` in every other case, including an unreadable registry.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether one extension currently holds a given surface.
     *
     * @param   string        $extensionId  Registry UUID of the template being asked about.
     * @param   ThemeSurface  $surface      Surface whose binding is tested.
     * @param   ?SiteContext  $site         Site to test the site surface for; the default site when
     *          omitted, and ignored for the administrator surface.
     *
     * @return  bool  True when exactly one binding for that surface names this extension.
     *
     * @throws  RuntimeException  When the binding count cannot be read back as an integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether an extension still holds any surface, on any site.
     *
     * This is the question behind two refusals: a template bound anywhere may not be upgraded in place,
     * and disabling one that is still bound elsewhere leaves the stored status at `active`.
     *
     * @param   string  $extensionId  Registry UUID of the template being asked about.
     *
     * @return  bool  True while at least one administrator or per-site binding names it.
     *
     * @throws  RuntimeException  When the binding count cannot be read back as an integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Read a driver-supplied count or fence back as an integer, or refuse it.
     *
     * Aggregates arrive as an int on some drivers and as a decimal string on others, so both are
     * accepted and nothing else is. A plain cast would turn `false` from a missing row into a
     * plausible-looking zero, and every caller here treats zero as a decision — no bindings left, or no
     * pending operations — so the wrong answer would be acted on rather than noticed.
     *
     * @param   mixed   $value        Value the driver returned for a count or fence column.
     * @param   string  $description  Noun phrase naming the value, interpolated into the failure message.
     *
     * @return  int  The value as an integer.
     *
     * @throws  RuntimeException  When the value is neither an integer nor a string of digits.
     *
     * @since   2.0.0
     */
    private function databaseInteger(mixed $value, string $description): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('The ' . $description . ' is invalid.');
        }

        return (int) $value;
    }

    /**
     * Refuse a package that does not declare support for this Kumwe and PHP version.
     *
     * The running PHP version is trimmed to its numeric part first, because a distribution suffix such
     * as `-dev` is not semantic versioning; a runtime that still cannot be read as three components is
     * reported rather than guessed around. Kumwe's side of the comparison is the 2.0.0 platform baseline,
     * which the manifest's declared Kumwe constraint has to accept.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose declared Kumwe and PHP constraints are
     *          evaluated.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the running PHP version cannot be read as semantic versioning.
     * @throws  InvalidArgumentException  When either declared constraint rejects its version.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuse a package whose required extensions are missing or too old.
     *
     * Only what is already installed counts; nothing is fetched to satisfy a dependency. An optional
     * dependency may be absent, but it may not be present and unsatisfied, so an optional integration is
     * either switched off or known to be a version this package understands.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose declared dependencies are checked.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a required dependency is not installed, or an installed one
     *          does not satisfy its declared constraint.
     *
     * @since   2.0.0
     */
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

    /**
     * Pair a key identifier with a detached signature, or establish that the package is unsigned.
     *
     * The two halves travel together. One without the other is a caller mistake rather than a partially
     * signed package, so it is refused here instead of quietly degrading to an unsigned install that the
     * trust store might still accept.
     *
     * @param   ?string  $keyId            Trust-store key identifier offered with the package, or null.
     * @param   ?string  $base64Signature  Base64 detached signature offered with the package, or null.
     *
     * @return  ?PackageSignature  The Ed25519 signature to verify, or null when the package is offered
     *          unsigned.
     *
     * @throws  InvalidArgumentException  When exactly one of the two halves was supplied.
     *
     * @since   2.0.0
     */
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

    /**
     * Copy the caller's archive into a private, read-only snapshot the install can trust.
     *
     * An archive at a path the caller owns can be replaced between the safety check and the extraction,
     * and taking a copy is what closes that window. The source is opened and locked shared, then the
     * open descriptor's file type, device and inode are compared with the path's own stat, which catches
     * a file swapped underneath between the two calls. The copy must be byte-complete and flushed — and
     * fsynced where the runtime offers it — before it is made read-only and renamed, so the snapshot
     * only ever appears at its final name fully written.
     *
     * @param   string  $source         Absolute path of the caller-owned archive to snapshot.
     * @param   string  $operationRoot  Private directory the temporary copy and the finished snapshot are
     *          written into.
     *
     * @return  string  Path of the finished snapshot, left read-only inside the private operation
     *          directory rather than at the caller's path.
     *
     * @throws  RuntimeException  When the source cannot be locked, was swapped while being opened, could
     *          not be copied completely, could not be synchronized, or could not be renamed into place.
     *
     * @since   2.0.0
     */
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

    /**
     * Read `kumwe.json` out of the archive without unpacking anything.
     *
     * The read is bounded at one byte over one mebibyte, so an oversized manifest is caught by reading
     * one byte too many rather than by trusting the size the entry table declares. The entry is read as
     * stored, unaffected by any pending modification held on the archive handle.
     *
     * @param   string  $archiveFile  Path of the archive to read the manifest from.
     *
     * @return  string  The manifest document exactly as stored in the package, still unparsed.
     *
     * @throws  InvalidArgumentException  When the archive cannot be opened, or the manifest is absent or
     *          larger than one mebibyte.
     *
     * @since   2.0.0
     */
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

    /**
     * Unpack the archive into a private staging directory.
     *
     * Staging is forced to `0700`, so a partially unpacked tree is never readable by the web server or
     * by other accounts on the host. Nothing here re-examines the entry table: the archive must already
     * have been accepted by the safety policy, which is the only thing that makes extraction safe.
     *
     * @param   string  $archiveFile       Path of the archive to unpack.
     * @param   string  $stagingDirectory  Directory to unpack into, created when it does not yet exist.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the archive cannot be opened for extraction.
     * @throws  RuntimeException  When staging cannot be created, or extraction fails part way.
     *
     * @since   2.0.0
     */
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

    /**
     * Prove the declared service provider really exists in the tree being published.
     *
     * The class name is resolved through the manifest's own PSR-4 prefixes rather than through the
     * application autoloader, so the answer is about this package's files and nothing already loaded.
     * A symbolic link at the resolved path does not count, which stops a package from pointing its
     * provider at code outside its own tree. The check runs twice — against staging, and again against
     * the published tree inside the install transaction.
     *
     * @param   ExtensionManifest  $manifest  Manifest declaring the provider class and the autoload
     *          prefixes it is resolved through.
     * @param   string             $root      Package directory the PSR-4 paths are resolved against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no declared prefix resolves the provider to a regular file
     *          below the root.
     *
     * @since   2.0.0
     */
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

    /**
     * Copy each declared asset into the web-readable tree under the extension's runtime path.
     *
     * Every file is written to a randomly named neighbour and renamed over its destination, so a request
     * arriving mid-publication reads either the old file or the new one and never a half-written one;
     * the temporary is cleaned up on both the success and the failure path. Directories are created
     * `0755` and files left `0644`, which is what makes them servable. A declared asset that is missing
     * or is a symbolic link stops the install rather than being skipped, so the published set always
     * matches what the manifest promised.
     *
     * @param   ExtensionManifest  $manifest    Manifest whose declared asset list is published.
     * @param   string             $sourceRoot  Published package directory the declared paths are read
     *          from.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a declared asset is missing or is a symbolic link, or its
     *          destination path contains an unusable segment.
     * @throws  RuntimeException  When an asset cannot be copied or activated, or its destination would
     *          escape the public asset root.
     *
     * @since   2.0.0
     */
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

    /**
     * Compose the path a release's files live at, relative to both storage roots.
     *
     * The version is part of the path, so two releases of one extension never share a directory: an
     * upgrade publishes beside the old tree rather than over it, which is what lets the superseded one
     * be retired only once no process is still reading it. The same relative path is used below the
     * private extension root and below the public asset root.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose identifier and version compose the path.
     *
     * @return  string  `<vendor>/<name>/<version>`, with no leading or trailing separator.
     *
     * @since   2.0.0
     */
    private function runtimePath(ExtensionManifest $manifest): string
    {
        return $manifest->identifier()->value() . '/' . (string) $manifest->version();
    }

    /**
     * Read the registry row for an extension that must be installed.
     *
     * The strict counterpart of `findInstalledOrNull()`, for the paths where absence is a caller error
     * rather than a state to branch on.
     *
     * @param   string  $identifier  `vendor/name` identifier to look up.
     *
     * @return  array<string, mixed>  The registry row exactly as stored.
     *
     * @throws  InvalidArgumentException  When no extension with that identifier is installed.
     *
     * @since   2.0.0
     */
    private function findInstalled(string $identifier): array
    {
        return $this->findInstalledOrNull($identifier)
            ?? throw new InvalidArgumentException('The requested extension is not installed.');
    }

    /**
     * Read the registry row for an extension that may or may not be installed.
     *
     * This is the lookup the install path uses, where "not installed" is the ordinary first-install case
     * and has to be told apart from an upgrade.
     *
     * @param   string  $identifier  `vendor/name` identifier to look up.
     *
     * @return  array<string, mixed>|null  The registry row, or null when nothing is installed under that
     *          identifier.
     *
     * @since   2.0.0
     */
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

    /**
     * Parse the manifest of the release an extension currently has installed.
     *
     * Read from the release row matching the registry's `installed_version` rather than from disk, so a
     * lifecycle change reasons about the manifest that was accepted at install time and not about
     * whatever the deployed tree happens to contain now. Drivers that hand a JSON column back already
     * decoded are handled by re-encoding it before parsing.
     *
     * @param   string  $identifier  `vendor/name` identifier of the installed extension.
     *
     * @return  ExtensionManifest  Manifest of the release currently recorded as installed.
     *
     * @throws  RuntimeException  When no stored manifest can be read for that identifier.
     * @throws  InvalidArgumentException  When the stored manifest no longer parses.
     *
     * @since   2.0.0
     */
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

    /**
     * Publish a lifecycle event describing the extension a change concerns.
     *
     * A before-event carries an empty result and an after-event the registry row, so a listener can tell
     * from the payload alone which side of the change it is on. The lease's fence travels with the
     * event, which is what lets a listener that writes anything of its own check whether it is still
     * running under the operation that raised it.
     *
     * @param   string                   $name      Event name, such as `onKumweExtensionAfterInstall`.
     * @param   ExtensionManifest        $manifest  Manifest supplying the identifier and version carried
     *          in the payload.
     * @param   string                   $actorId   Identifier of the actor whose change raised the event.
     * @param   array<string, mixed>     $result    Registry row for an after-event; empty for a
     *          before-event.
     * @param   ?ExtensionRegistryLease  $lease     Lease whose fence is published with the event, or null
     *          when the caller holds none.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Record a successful lifecycle change in the audit log.
     *
     * The outcome is fixed at `success`, so this records changes that took effect and nothing else; a
     * failed or ambiguous install is accounted for by the durable operation ledger instead. The
     * identifier's separator is rewritten to a colon so the audit subject reads as a single token rather
     * than as a path.
     *
     * @param   string                $actorId     Identifier of the actor the change is attributed to.
     * @param   string                $action      Action recorded, such as `extension.install`.
     * @param   string                $identifier  `vendor/name` identifier of the extension concerned.
     * @param   array<string, mixed>  $metadata    Extra detail stored with the entry, such as the version
     *          and package digest, or the surface a theme was bound to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Create a private directory, tolerating a concurrent creator.
     *
     * A failed `mkdir` is re-checked rather than trusted, because two installs racing on the same parent
     * both legitimately want it to exist. The `0700` mode is what keeps staging trees and archive
     * snapshots out of reach of the web server.
     *
     * @param   string  $directory  Absolute path to create, including any missing parents.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the directory neither existed nor could be created.
     *
     * @since   2.0.0
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory %s could not be created.', $directory));
        }
    }

    /**
     * Create a web-readable directory and hold it at `0755`.
     *
     * The mode is reapplied on every call rather than only at creation, so a directory left behind by an
     * earlier release cannot keep a mode that would make newly published assets unreachable.
     *
     * @param   string  $directory  Absolute path below the public asset root to create.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the directory neither existed nor could be created.
     *
     * @since   2.0.0
     */
    private function ensurePublicDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Public extension directory %s could not be created.', $directory));
        }

        chmod($directory, 0755);
    }

    /**
     * Create a relative path below a storage root, one segment at a time, refusing to leave it.
     *
     * Extension paths are composed from manifest-supplied identifiers and versions, so the walk treats
     * every segment as untrusted. Empty, `.` and `..` segments are rejected outright, a symbolic link at
     * the root or at any level stops the walk, and each level is resolved and compared against the
     * resolved root after it is created — which is what catches a link swapped in mid-walk rather than
     * only one that was already there. The mode selects which creator runs, and so whether the resulting
     * path is private or servable.
     *
     * @param   string  $root      Storage root the path must stay inside.
     * @param   string  $relative  Path relative to that root, split on `/`.
     * @param   int     $mode      `0755` to build a web-readable path; any other value builds a private
     *          `0700` one.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a segment is empty, `.` or `..`.
     * @throws  RuntimeException  When the root or a segment is a symbolic link, cannot be created or
     *          resolved, or resolves outside the root.
     *
     * @since   2.0.0
     */
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

    /**
     * Delete a directory tree, but only one that provably sits inside extension storage.
     *
     * Both the target and the root it must fall under are resolved before anything is unlinked, so a
     * symbolic link cannot aim the deletion elsewhere; a target that is itself a link, or is not a
     * directory, is refused rather than followed. Links found inside the tree are unlinked instead of
     * being descended into. A path that does not exist is a no-op, which is what makes cleanup safe to
     * run again after a partial failure.
     *
     * @param   string   $directory    Directory to delete, together with everything below it.
     * @param   ?string  $allowedRoot  Root the directory must resolve inside; the private extension root
     *          when omitted, and the public asset root when published assets are being retired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the target is not a plain directory, resolves outside the allowed
     *          root, or the tree yields an entry that cannot be read as a filesystem item.
     *
     * @since   2.0.0
     */
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

    /**
     * Read a field a stored row must carry as a non-empty string.
     *
     * Driver rows arrive as `mixed`, and an absent or empty value would otherwise flow into a filesystem
     * path or a query as an empty string. Failing here instead names the offending column, so an
     * operator is told which part of the row is wrong.
     *
     * @param   array<string, mixed>  $row    Registry or install-operation row to read from.
     * @param   string                $field  Column name to read.
     *
     * @return  string  The stored value, guaranteed to be a non-empty string.
     *
     * @throws  RuntimeException  When the field is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Installed extension field %s is invalid.', $field));
        }

        return $value;
    }
}
