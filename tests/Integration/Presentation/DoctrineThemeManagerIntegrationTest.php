<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Presentation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Joomla\Event\DispatcherInterface;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\CMS\Extension\Application\ExtensionRegistryLease;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Package\ExtensionActivationAdmission;
use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\CMS\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\CMS\Extension\Runtime\RuntimeIdentity;
use Kumwe\CMS\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Presentation\Application\ThemeActivationGuard;
use Kumwe\CMS\Tests\Support\CapabilityThemeAuthorizer;
use Kumwe\CMS\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\Infrastructure\DoctrineAdministratorThemeRecovery;
use Kumwe\CMS\Presentation\ThemeSurface;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use ZipArchive;

#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(DoctrineAdministratorThemeRecovery::class)]
final class DoctrineThemeManagerIntegrationTest extends TestCase
{
    private static ?Connection $matrixDatabase = null;
    private static ?TableNames $matrixTables = null;
    private Connection $database;
    private TableNames $tables;
    private string $root;
    private string $map;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-theme-manager-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/extensions/acme/corporate/1.0.0/templates/site', 0700, true));
        self::assertTrue(mkdir(
            $this->root . '/extensions/acme/corporate/1.0.0/templates/administrator',
            0700,
            true,
        ));
        [$this->database, $this->tables] = $this->database();
        $schema = $this->database->createSchemaManager();
        if (!$schema->tablesExist([$this->tables->raw('extensions')])) {
            (new CoreSchemaMigration($this->tables))->up($this->database);
            $schema = $this->database->createSchemaManager();
        }
        if (!$schema->introspectTable($this->tables->raw('extension_releases'))->hasColumn('trust_state')) {
            (new TokenAndTrustLifecycleMigration($this->tables, $this->root . '/extensions'))->up(
                $this->database,
            );
            $schema = $this->database->createSchemaManager();
        }
        if (!$schema->tablesExist([$this->tables->raw('site_theme_activations')])) {
            (new IsolateThemeSurfacesMigration($this->tables))->up($this->database);
        }
        $this->resetRegistry();
        self::assertTrue(mkdir($this->root . '/public', 0700, true));
        self::assertTrue(mkdir($this->root . '/core/site', 0700, true));
        self::assertTrue(mkdir($this->root . '/core/administrator', 0700, true));
        file_put_contents($this->root . '/core/site/home.twig', 'core home');
        file_put_contents($this->root . '/core/site/page.twig', 'core page');
        file_put_contents($this->root . '/core/administrator/layout.twig', 'core admin');
        $this->map = $this->root . '/runtime/extensions.json';
        self::assertTrue(mkdir(dirname($this->map), 0700, true));
        file_put_contents($this->map, '{"generation":0,"extensions":[]}');
        $this->persistTheme();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testInvalidThemeIsRejectedBeforeDoctrineActivationChanges(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        $manager = $this->manager(new RecordingAuditRecorder());

        try {
            $manager->activate(
                'acme/corporate',
                self::context(),
                surface: ThemeSurface::Site,
                lease: $this->lease(),
            );
            self::fail('An incomplete theme package was activated.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('page.twig', $exception->getMessage());
        }

        $this->assertActivationWasRolledBack();
    }

    public function testForwardMigrationCanResumeOnTheSupportedDatabase(): void
    {
        $migration = new IsolateThemeSurfacesMigration($this->tables);
        $migration->up($this->database);
        $migration->up($this->database);

        self::assertSame(2, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('theme_activations'),
        )));
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['default']));
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        )));
    }

    public function testDatabaseFenceRemainsMonotonicIndependentOfRedisLifetime(): void
    {
        $allocator = new ExtensionRegistryFenceAllocator(
            $this->database,
            $this->tables,
            new DoctrineThemeClock(),
        );

        self::assertSame(2, $allocator->allocate());
        self::assertSame(3, $allocator->allocate());
    }

    /**
     * Proves contract admission failure rolls activation back before runtime publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractAdmissionFailureRollsBackActivationBeforeRuntimePublication(): void
    {
        $admission = new class implements ExtensionActivationAdmission {
            /**
             * Reject the candidate as though its generated contract collided.
             *
             * @param   ExtensionManifest        $candidate        Candidate being admitted.
             * @param   SiteContext              $site             Site receiving the candidate.
             * @param   list<ExtensionManifest>  $activeManifests  Active manifests checked alongside it.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function admit(
                ExtensionManifest $candidate,
                SiteContext $site,
                array $activeManifests,
            ): void {
                throw new InvalidArgumentException('candidate OpenAPI collision');
            }
        };
        $manager = $this->manager(new RecordingAuditRecorder(), activationAdmission: $admission);
        $manager->install($this->pluginArchive('1.0.0'), self::context(), lease: $this->lease());
        $generation = (int) $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        ));
        $publications = (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('extension_runtime_publications'),
        ));

        try {
            $manager->activate('acme/plugin', self::context(), lease: $this->lease());
            self::fail('A contract-colliding extension was activated.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('candidate OpenAPI collision', $exception->getMessage());
        }

        self::assertSame('disabled', $this->database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/plugin']));
        self::assertSame($generation, (int) $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        )));
        self::assertSame($publications, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('extension_runtime_publications'),
        )));
    }

    public function testAuditFailureRollsBackDoctrineBeforeRuntimePublication(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $manager = $this->manager(new FailingAuditRecorder());

        try {
            $manager->activate(
                'acme/corporate',
                self::context(),
                surface: ThemeSurface::Site,
                lease: $this->lease(),
            );
            self::fail('An activation with a failed audit record was committed.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }

        $this->assertActivationWasRolledBack();
    }

    public function testCommittedActivationSurvivesLocalMaterializationFailure(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        unlink($this->map);
        self::assertTrue(mkdir($this->map));

        $result = $this->manager(new RecordingAuditRecorder())->activate(
            'acme/corporate',
            self::context(),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );

        self::assertSame('active', $result['status']);
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        )));
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE generation = 1',
            $this->tables->quoted('extension_runtime_publications'),
        )));
    }

    public function testActivationAndDisablePublishDurableGenerations(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->activate(
            'acme/corporate',
            self::context(),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );
        $manager->disable(
            'acme/corporate',
            self::context(),
            lease: $this->lease(),
        );

        self::assertSame('disabled', $this->database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/corporate']));
        self::assertNull($this->database->fetchOne(sprintf(
            'SELECT extension_id FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['default']));
        self::assertSame(2, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('extension_runtime_publications'),
        )));
    }

    public function testUninstallRetainsRuntimeUntilReplicaConvergence(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->activate(
            'acme/corporate',
            self::context(),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );
        $manager->uninstall(
            'acme/corporate',
            self::context(),
            lease: $this->lease(),
        );

        self::assertFalse($this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/corporate']));
        self::assertDirectoryExists($this->root . '/extensions/acme/corporate/1.0.0');
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE runtime_path = ?',
            $this->tables->quoted('extension_runtime_retirements'),
        ), ['acme/corporate/1.0.0']));
    }

    public function testAdministratorRecoveryPublishesCoreSelection(): void
    {
        file_put_contents(
            $this->administratorThemePath() . '/layout.twig',
            $this->administratorThemeFixture(),
        );
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->activate(
            'acme/corporate',
            self::context(['extensions.manage', 'themes.administrator.manage']),
            surface: ThemeSurface::Administrator,
            lease: $this->lease(),
        );
        file_put_contents($this->administratorThemePath() . '/layout.twig', '{% broken', FILE_APPEND);
        $compiler = new ExtensionRuntimeMapCompiler(
            $this->database,
            $this->tables,
            $this->map,
            $this->root . '/extensions',
            $this->root . '/public',
            new DoctrineThemeClock(),
            new RuntimeIdentity('testing-deployment', 'replica-recovery-test', 'test-process'),
            new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4)),
            new RuntimeArtifactDigester(),
        );
        $capability = new \stdClass();
        (new DoctrineAdministratorThemeRecovery(
            $this->database,
            $this->tables,
            new DoctrineTransactionManager($this->database),
            new RecordingAuditRecorder(),
            new DoctrineThemeClock(),
            $compiler,
            $capability,
        ))->recover($capability, new FixedExtensionRegistryLease(1));

        self::assertNull($this->database->fetchOne(sprintf(
            "SELECT extension_id FROM %s WHERE surface = 'administrator'",
            $this->tables->quoted('theme_activations'),
        )));
        self::assertSame(2, (int) $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        )));
        self::assertTrue($compiler->isCurrent($compiler->materializeLatest(true)));
    }

    public function testAdministratorRecoveryRejectsUnrelatedRuntimeDrift(): void
    {
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->install($this->pluginArchive('1.0.0'), self::context(), lease: $this->lease());
        $manager->activate('acme/plugin', self::context(), lease: $this->lease());
        file_put_contents(
            $this->administratorThemePath() . '/layout.twig',
            $this->administratorThemeFixture(),
        );
        $manager->activate(
            'acme/corporate',
            self::context(['extensions.manage', 'themes.administrator.manage']),
            $this->lease(),
            ThemeSurface::Administrator,
        );
        file_put_contents(
            $this->root . '/extensions/acme/plugin/1.0.0/src/Provider.php',
            "\n// unrelated drift",
            FILE_APPEND,
        );
        $compiler = new ExtensionRuntimeMapCompiler(
            $this->database,
            $this->tables,
            $this->map,
            $this->root . '/extensions',
            $this->root . '/public',
            new DoctrineThemeClock(),
            new RuntimeIdentity('testing-deployment', 'replica-recovery-drift', 'test-process'),
            new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4)),
            new RuntimeArtifactDigester(),
        );
        $capability = new \stdClass();
        $recovery = new DoctrineAdministratorThemeRecovery(
            $this->database,
            $this->tables,
            new DoctrineTransactionManager($this->database),
            new RecordingAuditRecorder(),
            new DoctrineThemeClock(),
            $compiler,
            $capability,
        );
        $this->expectException(RuntimeException::class);
        try {
            $recovery->recover($capability, $this->lease());
        } finally {
            self::assertIsString($this->database->fetchOne(sprintf(
                "SELECT extension_id FROM %s WHERE surface = 'administrator'",
                $this->tables->quoted('theme_activations'),
            )));
        }
    }

    public function testActivePluginUpgradeRetainsOldRootUntilReplicaConvergence(): void
    {
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->install($this->pluginArchive('1.0.0'), self::context(), lease: $this->lease());
        $manager->activate('acme/plugin', self::context(), lease: $this->lease());
        $manager->install($this->pluginArchive('2.0.0'), self::context(), lease: $this->lease());

        $installed = $this->database->fetchAssociative(sprintf(
            'SELECT status, installed_version, runtime_path FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/plugin']);
        self::assertIsArray($installed);
        self::assertSame('active', $installed['status']);
        self::assertSame('2.0.0', $installed['installed_version']);
        self::assertSame('acme/plugin/2.0.0', $installed['runtime_path']);
        self::assertDirectoryExists($this->root . '/extensions/acme/plugin/1.0.0');
        self::assertDirectoryExists($this->root . '/extensions/acme/plugin/2.0.0');
        self::assertSame(1, (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE runtime_path = ?',
            $this->tables->quoted('extension_runtime_retirements'),
        ), ['acme/plugin/1.0.0']));
        $payload = $this->database->fetchOne(sprintf(
            'SELECT payload FROM %s WHERE generation = 3',
            $this->tables->quoted('extension_runtime_publications'),
        ));
        $map = is_string($payload) ? json_decode($payload, true, 32, JSON_THROW_ON_ERROR) : $payload;
        self::assertIsArray($map);
        self::assertSame('acme/plugin/2.0.0', $map['extensions'][0]['root'] ?? null);
    }

    public function testAmbiguousInstallFailureRetainsAndReconcilesByOperationId(): void
    {
        $archive = $this->pluginArchive('1.0.0');
        try {
            $this->manager(new FailingAuditRecorder())->install(
                $archive,
                self::context(),
                lease: $this->lease(),
            );
            self::fail('The failing install audit was not propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }
        $runtime = $this->root . '/extensions/acme/plugin/1.0.0';
        $assets = $this->root . '/public/acme/plugin/1.0.0';
        self::assertDirectoryExists($runtime);
        self::assertDirectoryExists($assets);
        self::assertFalse($this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/plugin']));
        self::assertSame('unknown', $this->database->fetchOne(sprintf(
            'SELECT transaction_outcome FROM %s',
            $this->tables->quoted('extension_install_operations'),
        )));

        self::assertSame(1, $this->manager(new RecordingAuditRecorder())->reconcileInstallOperations(
            $this->lease(),
        ));
        self::assertSame('committed', $this->database->fetchOne(sprintf(
            'SELECT transaction_outcome FROM %s',
            $this->tables->quoted('extension_install_operations'),
        )));
        self::assertDirectoryExists($runtime);
        self::assertDirectoryExists($assets);
        self::assertIsString($this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/plugin']));
    }

    public function testExecutedRuntimeByteTamperingFailsClosed(): void
    {
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->install($this->pluginArchive('1.0.0'), self::context(), lease: $this->lease());
        $manager->activate('acme/plugin', self::context(), lease: $this->lease());
        $compiler = new ExtensionRuntimeMapCompiler(
            $this->database,
            $this->tables,
            $this->map,
            $this->root . '/extensions',
            $this->root . '/public',
            new DoctrineThemeClock(),
            new RuntimeIdentity('testing-deployment', 'replica-artifact-test', 'test-process'),
            new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4)),
            new RuntimeArtifactDigester(),
        );
        $state = $compiler->materializeLatest(true);
        self::assertTrue($compiler->isCurrent($state));
        self::assertTrue($compiler->isCurrent($state));
        $asset = $this->root . '/public/acme/plugin/1.0.0/assets/plugin.css';
        file_put_contents($asset, '/* tampered */', FILE_APPEND);
        self::assertFalse($compiler->isCurrent($state));
        file_put_contents($asset, 'body{}');
        self::assertTrue($compiler->isCurrent($state));
        file_put_contents(
            $this->root . '/extensions/acme/plugin/1.0.0/src/Provider.php',
            "\n// tampered",
            FILE_APPEND,
        );

        self::assertFalse($compiler->isCurrent($state));
        self::assertTrue($compiler->inspectLocal()->trusted);
    }

    public function testCapabilityRevocationAtMutationBoundaryRollsBackRegistry(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $manager = $this->manager(new RecordingAuditRecorder(), new RevokedThemeAuthorizer());
        $this->expectException(InsufficientCapability::class);

        try {
            $manager->activate(
                'acme/corporate',
                self::context(),
                surface: ThemeSurface::Site,
                lease: $this->lease(),
            );
        } finally {
            $this->assertActivationWasRolledBack();
        }
    }

    public function testExecutionContextActorIsTheOnlyMutationIdentity(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $result = $this->manager(new RecordingAuditRecorder())->activate(
            'acme/corporate',
            self::context(
                ['extensions.manage', 'themes.site.manage'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            ),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );

        self::assertSame('active', $result['status']);
    }

    public function testDisablingOneSiteThemeRetainsAssignmentsForOtherSites(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $manager = $this->manager(new RecordingAuditRecorder());
        $manager->activate(
            'acme/corporate',
            self::context(),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );
        $manager->activate(
            'acme/corporate',
            self::context(site: 'regional'),
            surface: ThemeSurface::Site,
            lease: $this->lease(),
        );

        $result = $manager->disable('acme/corporate', self::context(), lease: $this->lease());

        self::assertSame('active', $result['status']);
        self::assertNull($this->database->fetchOne(sprintf(
            'SELECT extension_id FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['default']));
        self::assertIsString($this->database->fetchOne(sprintf(
            'SELECT extension_id FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['regional']));
    }

    public function testDirectThemeMutationWithoutAuthorityIsDenied(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $this->expectException(InsufficientCapability::class);

        try {
            $this->manager(new RecordingAuditRecorder())->activate(
                'acme/corporate',
                self::context(['extensions.manage']),
                surface: ThemeSurface::Site,
                lease: $this->lease(),
            );
        } finally {
            $this->assertActivationWasRolledBack();
        }
    }

    public function testExpiredRegistryHolderIsFencedByTheNewerLease(): void
    {
        file_put_contents($this->themePath() . '/home.twig', 'home');
        file_put_contents($this->themePath() . '/page.twig', 'page');
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET fence = 2 WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        ));
        $this->expectException(RuntimeException::class);

        try {
            $this->manager(new RecordingAuditRecorder())->activate(
                'acme/corporate',
                self::context(),
                surface: ThemeSurface::Site,
                lease: new FixedExtensionRegistryLease(1),
            );
        } finally {
            $this->assertActivationWasRolledBack();
        }
    }

    private function manager(
        AuditRecorder $audit,
        ?ThemeMutationAuthorizer $themeAuthorization = null,
        ?ExtensionActivationAdmission $activationAdmission = null,
    ): DoctrineExtensionManager {
        $clock = new DoctrineThemeClock();
        $transactions = new DoctrineTransactionManager($this->database);
        $authorization = AuthorizationContext::gateway();
        $compiler = new ExtensionRuntimeMapCompiler(
            $this->database,
            $this->tables,
            $this->map,
            $this->root . '/extensions',
            $this->root . '/public',
            $clock,
            new RuntimeIdentity('testing-deployment', 'replica-manager-test', 'test-process'),
            new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4)),
            new RuntimeArtifactDigester(),
        );
        $trust = new TrustStore(
            $this->createStub(TrustStoreRepository::class),
            $this->createStub(TrustKeySignatureVerifier::class),
            $this->createStub(ExtensionArtifactVerifier::class),
            $compiler,
            $transactions,
            $audit,
            $clock,
            $authorization,
            true,
        );

        return new DoctrineExtensionManager(
            $this->database,
            $this->tables,
            $this->root . '/extensions',
            $this->root . '/public',
            new ZipArchiveReader(),
            new PackageSafetyPolicy(),
            new ExtensionMigrationRunner($this->database, $this->tables, $clock),
            $compiler,
            $transactions,
            $audit,
            $clock,
            $this->createStub(DispatcherInterface::class),
            new AllowThemeActivationGuard(),
            new ThemePackageValidator($this->root . '/core'),
            $themeAuthorization ?? new CapabilityThemeAuthorizer(),
            $trust,
            $authorization,
            AuthorizationContext::ownershipWriter(),
            null,
            $activationAdmission,
        );
    }

    private function lease(): FixedExtensionRegistryLease
    {
        return new FixedExtensionRegistryLease(1);
    }

    /** @return array{Connection, TableNames} */
    private function database(): array
    {
        $driver = getenv('DB_DRIVER');
        if (!is_string($driver) || !in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

            return [$database, new TableNames($database, 'kumwe_')];
        }

        if (self::$matrixDatabase !== null && self::$matrixTables !== null) {
            return [self::$matrixDatabase, self::$matrixTables];
        }

        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        self::$matrixDatabase = (new DoctrineConnectionFactory($configuration->database))->create();
        self::$matrixTables = new TableNames(self::$matrixDatabase, $configuration->database->tablePrefix);
        return [self::$matrixDatabase, self::$matrixTables];
    }

    private function resetRegistry(): void
    {
        foreach (
            [
            'extension_install_operations',
            'extension_runtime_materializations',
            'extension_runtime_retirements',
            'extension_dependencies',
            'extension_releases',
            'extensions',
            'extension_runtime_publications',
            'audit_events',
            ] as $table
        ) {
            $this->database->executeStatement(sprintf('DELETE FROM %s', $this->tables->quoted($table)));
        }
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET extension_id = NULL, version = 1, activated_by = NULL',
            $this->tables->quoted('theme_activations'),
        ));
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s',
            $this->tables->quoted('site_theme_activations'),
        ));
        $this->database->insert($this->tables->raw('site_theme_activations'), [
            'site_identifier' => 'default',
            'extension_id' => null,
            'version' => 1,
            'activated_by' => null,
            'activated_at' => new DateTimeImmutable('2026-08-05T12:00:00+00:00'),
        ], ['activated_at' => Types::DATETIME_IMMUTABLE]);
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET generation = 0, rebuilt_at = CURRENT_TIMESTAMP WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        ));
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET fence = 1 WHERE singleton_key = 1',
            $this->tables->quoted('extension_registry_fence'),
        ));
    }

    private function persistTheme(): void
    {
        $now = new DateTimeImmutable('2026-08-05T12:00:00+00:00');
        $id = '00000000-0000-7000-8000-000000000902';
        $manifest = <<<'JSON'
{
  "schema": 1,
  "name": "acme/corporate",
  "type": "template",
  "version": "1.0.0",
  "provider": "Acme\\Corporate\\Provider",
  "autoload": {"psr-4": {"Acme\\Corporate\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"},
  "template": {
    "contract": 1,
    "standard": "kis-1.0",
    "components": {"minimum": "1.0.0", "maximum": "1.0.0"},
    "tokens": {"minimum": "1.0.0", "maximum": "1.0.0"}
  }
}
JSON;
        $this->database->insert($this->tables->raw('extensions'), [
            'id' => $id,
            'identifier' => 'acme/corporate',
            'extension_type' => 'template',
            'installed_version' => '1.0.0',
            'status' => 'disabled',
            'service_provider' => 'Acme\\Corporate\\Provider',
            'runtime_path' => 'acme/corporate/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $this->database->insert($this->tables->raw('extension_releases'), [
            'id' => '00000000-0000-7000-8000-000000000903',
            'extension_id' => $id,
            'version' => '1.0.0',
            'manifest' => json_decode($manifest, true, 16, JSON_THROW_ON_ERROR),
            'package_sha256' => str_repeat('0', 64),
            'artifact_sha256' => str_repeat('0', 64),
            'deployed_tree_sha256' => FilesystemExtensionArtifactVerifier::treeDigest(
                $this->root . '/extensions/acme/corporate/1.0.0',
            ),
            'trust_state' => 'verified',
            'signature_algorithm' => null,
            'signing_key_id' => null,
            'signature_base64' => null,
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function assertActivationWasRolledBack(): void
    {
        self::assertNull($this->database->fetchOne(sprintf(
            'SELECT extension_id FROM %s WHERE site_identifier = ?',
            $this->tables->quoted('site_theme_activations'),
        ), ['default']));
        self::assertSame('disabled', $this->database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $this->tables->quoted('extensions'),
        ), ['acme/corporate']));
        self::assertSame(0, (int) $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        )));
        self::assertSame('{"generation":0,"extensions":[]}', file_get_contents($this->map));
    }

    private function themePath(): string
    {
        return $this->root . '/extensions/acme/corporate/1.0.0/templates/site';
    }

    private function administratorThemePath(): string
    {
        return $this->root . '/extensions/acme/corporate/1.0.0/templates/administrator';
    }

    /**
     * Read the shipped administrator-template shell used as the valid activation fixture.
     *
     * @return  string  Complete KIS 1.0 administrator layout.
     *
     * @since   2.0.0
     */
    private function administratorThemeFixture(): string
    {
        $layout = file_get_contents(
            dirname(__DIR__, 3)
            . '/examples/extensions/minimal-administrator-template/templates/administrator/layout.twig',
        );
        self::assertIsString($layout);

        return $layout;
    }

    private function pluginArchive(string $version): string
    {
        $path = $this->root . '/plugin-' . $version . '.zip';
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'acme/plugin',
            'type' => 'plugin',
            'version' => $version,
            'provider' => 'Acme\\Plugin\\Provider',
            'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'assets' => ['assets/plugin.css'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($archive->addFromString('kumwe.json', $manifest));
        self::assertTrue($archive->addFromString(
            'src/Provider.php',
            '<?php final class Provider {}',
        ));
        self::assertTrue($archive->addFromString('assets/plugin.css', 'body{}'));
        self::assertTrue($archive->close());

        return $path;
    }

    private static function actor(): string
    {
        return '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    }

    /** @param list<string> $capabilities */
    private static function context(
        array $capabilities = ['extensions.manage', 'themes.site.manage'],
        ?string $actor = null,
        string $site = 'default',
    ): ExecutionContext {
        return AuthorizationContext::human($capabilities, $actor ?? self::actor(), $site);
    }
}

final readonly class DoctrineThemeClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-05T12:00:00+00:00');
    }
}

final class MutableDoctrineThemeClock implements ClockInterface
{
    private DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2030-08-05T12:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify(sprintf('+%d seconds', $seconds));
    }
}

final readonly class AllowThemeActivationGuard implements ThemeActivationGuard
{
    public function assertAllowed(
        ThemeSurface $surface,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void {
    }
}

final class RecordingAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final readonly class FailingAuditRecorder implements AuditRecorder
{
    public function record(AuditEvent $event): void
    {
        throw new RuntimeException('audit failure');
    }
}

final class RevokedThemeAuthorizer implements ThemeMutationAuthorizer
{
    private int $checks = 0;

    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void
    {
        ++$this->checks;
        if ($this->checks > 1) {
            throw new InsufficientCapability('themes.' . $surface->value . '.manage');
        }
    }
}

final readonly class FixedExtensionRegistryLease implements ExtensionRegistryLease
{
    public function __construct(private int $value)
    {
    }

    public function fence(): int
    {
        return $this->value;
    }

    public function renew(): void
    {
    }
}
