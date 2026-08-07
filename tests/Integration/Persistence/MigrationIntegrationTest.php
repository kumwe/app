<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Infrastructure\Persistence\DoctrineContentModelRepository;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\CMS\Extension\Runtime\RuntimeIdentity;
use Kumwe\CMS\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ContentModelRuntimeMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DatabaseDrivenPresentationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DynamicSiteContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IdempotencyLeaseNullabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\SiteAutomationContextMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use ZipArchive;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineMigrationLock::class)]
#[CoversClass(DoctrineMigrationRepository::class)]
#[CoversClass(CoreSchemaMigration::class)]
#[CoversClass(ContentModelRuntimeMigration::class)]
#[CoversClass(DynamicSiteContentMigration::class)]
#[CoversClass(DatabaseDrivenPresentationMigration::class)]
#[CoversClass(JobRecoveryMigration::class)]
#[CoversClass(ApplicationAuthorizationMigration::class)]
#[CoversClass(IdempotencyLeaseNullabilityMigration::class)]
#[CoversClass(AuthorizationRecoveryIntegrationMigration::class)]
#[CoversClass(SiteAutomationContextMigration::class)]
#[CoversClass(InstallationGlobalAutomationMigration::class)]
#[CoversClass(TokenAndTrustLifecycleMigration::class)]
#[CoversClass(IsolateThemeSurfacesMigration::class)]
final class MigrationIntegrationTest extends TestCase
{
    public function testDatabaseMigrationIsIdempotentAndReady(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $command = $container->get(MigrateCommand::class);
        self::assertInstanceOf(MigrateCommand::class, $command);
        self::assertSame(0, $command->execute([], new class implements Output {
            public function line(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        }));
        self::assertTrue($container->get(ReadinessProbe::class)->ready());

        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertSame(
            '69741c8e3fc14a1a0e318a643deb3fa7901685ba8f534a1782917839ad1f0b57',
            (new CoreSchemaMigration($tables))->checksum(),
        );
        $schema = $database->createSchemaManager();
        self::assertTrue($schema->introspectTable($tables->raw('users'))->hasColumn('security_epoch'));
        $idempotency = $schema->introspectTable($tables->raw('idempotency'));
        foreach (
            ['authorization_fingerprint', 'lease_owner', 'lease_expires_at', 'owner_token', 'locked_until'] as $column
        ) {
            self::assertTrue($idempotency->hasColumn($column));
        }
        self::assertFalse($idempotency->getColumn('lease_owner')->getNotnull());
        self::assertFalse($idempotency->getColumn('lease_expires_at')->getNotnull());
        $jobs = $schema->introspectTable($tables->raw('jobs'));
        $schedules = $schema->introspectTable($tables->raw('schedules'));
        self::assertTrue($jobs->hasColumn('lease_token'));
        self::assertTrue($jobs->hasColumn('execution_scope'));
        self::assertTrue($schedules->hasColumn('execution_scope'));
        self::assertTrue($schema->tablesExist([
            $tables->raw('sites'),
            $tables->raw('resource_site_ownership'),
        ]));
        self::assertTrue($schema->introspectTable($tables->raw('sites'))->hasColumn('enabled'));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000801']));
        self::assertSame(ApplicationAuthorizationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ApplicationAuthorizationMigration::ID]));
        self::assertSame(JobRecoveryMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [JobRecoveryMigration::ID]));
        self::assertSame(IdempotencyLeaseNullabilityMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [IdempotencyLeaseNullabilityMigration::ID]));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000802']));
        $legacyAdministrator = $database->fetchAssociative(sprintf(
            'SELECT id, security_epoch FROM %s WHERE email_normalized = ?',
            $tables->quoted('users'),
        ), ['integration-administrator@example.test']);
        if ($legacyAdministrator !== false) {
            self::assertSame('2', (string) $legacyAdministrator['security_epoch']);
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s g INNER JOIN %s r ON r.id = g.role_id '
                . "WHERE r.code = 'administrator' AND g.capability_code IN (?, ?)",
                $tables->quoted('role_capability_grants'),
                $tables->quoted('roles'),
            ), ['themes.site.manage', 'themes.administrator.manage']));
        }
        $users = $schema->introspectTable($tables->raw('users'));
        $tokens = $schema->introspectTable($tables->raw('api_tokens'));
        $keys = $schema->introspectTable($tables->raw('extension_trust_keys'));
        $releases = $schema->introspectTable($tables->raw('extension_releases'));
        self::assertTrue($users->hasColumn('security_epoch'));
        foreach (['security_epoch', 'audience', 'purpose', 'site_identifier', 'rotated_from'] as $column) {
            self::assertTrue($tokens->hasColumn($column), sprintf('Token lifecycle column %s is missing.', $column));
        }
        self::assertTrue($tokens->getColumn('expires_at')->getNotnull());
        foreach (['vendor_namespace', 'extension_pattern', 'expires_at', 'revoked_by'] as $column) {
            self::assertTrue($keys->hasColumn($column), sprintf('Trust lifecycle column %s is missing.', $column));
        }
        self::assertTrue($keys->getColumn('expires_at')->getNotnull());
        foreach (['artifact_sha256', 'deployed_tree_sha256', 'trust_state'] as $column) {
            self::assertTrue($releases->hasColumn($column), sprintf('Release digest column %s is missing.', $column));
        }
        self::assertTrue($schema->tablesExist([$tables->raw('extension_trust_generation')]));
        self::assertTrue($schema->tablesExist([$tables->raw('extension_runtime_outbox')]));
        self::assertSame('ready', $database->fetchOne(sprintf(
            'SELECT lifecycle_state FROM %s WHERE singleton_key = 1',
            $tables->quoted('extension_trust_generation'),
        )));
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('extension_trust_generation'),
        )));
        $idempotency = $schema->introspectTable($tables->raw('idempotency'));
        foreach (['owner_token', 'lease_expires_at', 'attempt'] as $column) {
            self::assertTrue($idempotency->hasColumn($column));
        }
        self::assertFalse($idempotency->getColumn('owner_token')->getNotnull());
        self::assertSame(64, $idempotency->getColumn('owner_token')->getLength());
        self::assertFalse($idempotency->getColumn('lease_owner')->getNotnull());
        self::assertFalse($idempotency->getColumn('lease_expires_at')->getNotnull());
        self::assertInstanceOf(BigIntType::class, $users->getColumn('security_epoch')->getType());
        self::assertSame(AuthorizationRecoveryIntegrationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [AuthorizationRecoveryIntegrationMigration::ID]));
        self::assertSame(SiteAutomationContextMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [SiteAutomationContextMigration::ID]));
        self::assertSame(
            ['administrator', 'site'],
            $database->fetchFirstColumn(sprintf(
                'SELECT surface FROM %s ORDER BY surface',
                $tables->quoted('theme_activations'),
            )),
        );
        self::assertSame(
            ['default'],
            $database->fetchFirstColumn(sprintf(
                'SELECT site_identifier FROM %s ORDER BY site_identifier',
                $tables->quoted('site_theme_activations'),
            )),
        );
        self::assertSame(
            ['themes.administrator.manage', 'themes.site.manage'],
            $database->fetchFirstColumn(sprintf(
                "SELECT code FROM %s WHERE code LIKE 'themes.%%.manage' ORDER BY code",
                $tables->quoted('capabilities'),
            )),
        );
        self::assertTrue(
            $schema->introspectTable($tables->raw('extension_install_operations'))
                ->hasColumn('site_identifier'),
        );
        self::assertSame(InstallationGlobalAutomationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [InstallationGlobalAutomationMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('content_types'),
            $tables->raw('content_type_definition_versions'),
            $tables->raw('workflows'),
            $tables->raw('workflow_definition_versions'),
        ]));
        $content = $schema->introspectTable($tables->raw('content_entries'));
        $contentColumns = [
            'site_identifier',
            'content_type_id',
            'content_type_version',
            'workflow_id',
            'workflow_version',
        ];
        foreach ($contentColumns as $column) {
            self::assertTrue($content->hasColumn($column), sprintf('Content-model column %s is missing.', $column));
        }
        self::assertSame(ContentModelRuntimeMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ContentModelRuntimeMigration::ID]));
        self::assertSame(DynamicSiteContentMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [DynamicSiteContentMigration::ID]));
        self::assertSame(DatabaseDrivenPresentationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [DatabaseDrivenPresentationMigration::ID]));
        $navigationItems = $schema->introspectTable($tables->raw('navigation_items'));
        foreach (['target_type', 'content_id', 'target_url'] as $column) {
            self::assertTrue(
                $navigationItems->hasColumn($column),
                sprintf('Navigation target column %s is missing.', $column),
            );
        }
        $models = new DoctrineContentModelRepository($database, $tables);
        $page = $models->contentType(SiteContext::default(), 'page');
        self::assertNotNull($page);
        self::assertSame(3, $page->version);
        self::assertArrayNotHasKey('brand_logo', $page->schema()['properties']);
        $workflow = $models->workflow(SiteContext::default(), $page->workflowId, $page->workflowVersion);
        self::assertNotNull($workflow);
        self::assertSame('draft', $workflow->initialState());
        self::assertTrue($workflow->isPublic('published'));
        $homepageId = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE site_identifier = ? AND slug = ?',
            $tables->quoted('content_entries'),
        ), [SiteContext::DEFAULT, 'home']);
        self::assertIsString($homepageId);
        $homepageSetting = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), ['site.homepage_content_id']);
        self::assertIsString($homepageSetting);
        self::assertSame($homepageId, json_decode($homepageSetting, true, flags: JSON_THROW_ON_ERROR));
        $presentationSetting = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), ['site.presentation']);
        self::assertIsString($presentationSetting);
        $presentation = json_decode($presentationSetting, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($presentation);
        self::assertSame('corporate', $presentation['active_scheme']);
        self::assertSame('main', $presentation['primary_menu']);
        self::assertSame(
            '/media/00000000-0000-7000-8000-000000000901/kumwe-symbol.svg',
            $presentation['logo'],
        );
        $homepageData = $database->fetchAssociative(sprintf(
            'SELECT data, content_type_version FROM %s WHERE id = ?',
            $tables->quoted('content_entries'),
        ), [$homepageId]);
        self::assertIsArray($homepageData);
        self::assertSame('3', (string) $homepageData['content_type_version']);
        $homepageFields = json_decode((string) $homepageData['data'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($homepageFields);
        self::assertArrayNotHasKey('brand_logo', $homepageFields);
        self::assertSame('published', $database->fetchOne(sprintf(
            'SELECT workflow_state_key FROM %s WHERE id = ?',
            $tables->quoted('content_entries'),
        ), [$homepageId]));
        self::assertSame('main', $database->fetchOne(sprintf(
            'SELECT handle FROM %s WHERE handle = ?',
            $tables->quoted('navigation_menus'),
        ), ['main']));
        self::assertSame('4', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE menu_id = ?',
            $tables->quoted('navigation_items'),
        ), ['00000000-0000-7000-8000-000000001101']));
    }

    public function testMigrationLockSurvivesDdlAndRejectsASecondDatabaseSession(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $secondary = DriverManager::getConnection($database->getParams());
        $secondary->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? "SET time_zone = '+00:00'"
                : "SET TIME ZONE 'UTC'",
        );
        $prefix = 'l' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_';
        $primaryTables = new TableNames($database, $prefix);
        $secondaryTables = new TableNames($secondary, $prefix);
        $primaryLock = new DoctrineMigrationLock($database, $primaryTables);
        $secondaryLock = new DoctrineMigrationLock($secondary, $secondaryTables);
        $probeName = $primaryTables->raw('ddl_probe');

        try {
            $primaryLock->synchronized(function () use (
                $database,
                $secondaryLock,
                $probeName,
            ): void {
                $this->assertSecondMigrationSessionIsBlocked($secondaryLock);

                $probe = new Table($probeName);
                $probe->addColumn('id', Types::INTEGER);
                $probe->setPrimaryKey(['id']);
                $database->createSchemaManager()->createTable($probe);

                $this->assertSecondMigrationSessionIsBlocked($secondaryLock);
            });

            self::assertSame('acquired', $secondaryLock->synchronized(static fn (): string => 'acquired'));
        } finally {
            $schema = $database->createSchemaManager();
            foreach ([$probeName, $primaryTables->raw('migration_locks')] as $table) {
                if ($schema->tablesExist([$table])) {
                    $schema->dropTable($table);
                }
            }
            $secondary->close();
        }
    }

    public function testExpiredLegacyMigrationOwnerRecoveryRequiresTheExactToken(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $prefix = 'k' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_';
        $tables = new TableNames($database, $prefix);
        $lock = new DoctrineMigrationLock($database, $tables);
        $owner = str_repeat('a', 64);

        try {
            $lock->synchronized(static fn (): null => null);
            $database->insert($tables->raw('migration_locks'), [
                'lock_name' => 'core-migrations',
                'owner_token' => $owner,
                'acquired_at' => new DateTimeImmutable('-1 hour'),
                'expires_at' => new DateTimeImmutable('+1 hour'),
            ], [
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);

            try {
                $lock->synchronized(static fn (): null => null);
                self::fail('A legacy migration owner must block the advisory-lock bridge.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('legacy migration owner is present', $exception->getMessage());
            }
            try {
                $lock->recoverExpiredLegacyOwner($owner);
                self::fail('An active legacy migration owner must not be recoverable.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('has not expired', $exception->getMessage());
            }
            $database->update(
                $tables->raw('migration_locks'),
                ['expires_at' => new DateTimeImmutable('-1 hour')],
                ['lock_name' => 'core-migrations', 'owner_token' => $owner],
                ['expires_at' => Types::DATETIME_IMMUTABLE],
            );
            try {
                $lock->recoverExpiredLegacyOwner(str_repeat('b', 64));
                self::fail('Recovery must compare-and-delete the exact expected owner token.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('owner changed or no longer exists', $exception->getMessage());
            }

            $lock->recoverExpiredLegacyOwner($owner);
            self::assertSame('recovered', $lock->synchronized(static fn (): string => 'recovered'));
        } finally {
            $schema = $database->createSchemaManager();
            $table = $tables->raw('migration_locks');
            if ($schema->tablesExist([$table])) {
                $schema->dropTable($table);
            }
        }
    }

    private function assertSecondMigrationSessionIsBlocked(DoctrineMigrationLock $lock): void
    {
        try {
            $lock->synchronized(static fn (): null => null);
            self::fail('A concurrent migration database session must not acquire the lock.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already running database migrations', $exception->getMessage());
        }
    }

    public function testParentSchemaActiveReleaseTransitionsBeforeRuntimeMapSwitch(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $prefix = 'u' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 8) . '_';
        $tables = new TableNames($database, $prefix);
        $this->createParentLifecycleSchema($database, $tables);
        $legacyUserId = Uuid::uuid7()->toString();
        $legacyTokenId = Uuid::uuid7()->toString();
        $database->insert($tables->raw('users'), ['id' => $legacyUserId, 'status' => 'active']);
        $database->insert($tables->raw('api_tokens'), [
            'id' => $legacyTokenId,
            'subject_id' => $legacyUserId,
            'expires_at' => null,
            'revoked_at' => null,
        ]);
        $root = sys_get_temp_dir() . '/kumwe-upgrade-' . bin2hex(random_bytes(8));
        $runtime = 'legacy/demo/1.0.0';
        mkdir($root . '/' . $runtime . '/src', 0700, true);
        file_put_contents($root . '/' . $runtime . '/src/Provider.php', '<?php return true;');
        $safeRuntime = 'legacy/safe/1.0.0';
        mkdir($root . '/' . $safeRuntime . '/src', 0700, true);
        $safeProvider = '<?php return true;';
        $safeManifest = json_encode([
            'schema' => 1,
            'name' => 'legacy/safe',
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'Legacy\\SafeProvider',
            'autoload' => ['psr-4' => ['Legacy\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new \stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($root . '/' . $safeRuntime . '/kumwe.json', $safeManifest);
        file_put_contents($root . '/' . $safeRuntime . '/src/Provider.php', $safeProvider);
        $packageFile = $root . '/legacy-safe.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($packageFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('kumwe.json', $safeManifest));
        self::assertTrue($zip->addFromString('src/Provider.php', $safeProvider));
        self::assertTrue($zip->close());
        self::assertTrue(copy($packageFile, $root . '/' . $safeRuntime . '/.kumwe-package.zip'));
        $packageDigest = hash_file('sha256', $packageFile);
        self::assertIsString($packageDigest);
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $signature = sodium_crypto_sign_detached($packageDigest, sodium_crypto_sign_secretkey($keyPair));
        $extensionId = Uuid::uuid7()->toString();
        $safeExtensionId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('extensions'), [
            'id' => $extensionId,
            'identifier' => 'legacy/demo',
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Legacy\\Provider',
            'runtime_path' => $runtime,
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => [
                'schema' => 1,
                'name' => 'legacy/demo',
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => 'Legacy\\Provider',
                'autoload' => ['psr-4' => ['Legacy\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            ],
            'package_sha256' => str_repeat('a', 64),
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
        $database->insert($tables->raw('extensions'), [
            'id' => $safeExtensionId,
            'identifier' => 'legacy/safe',
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Legacy\\SafeProvider',
            'runtime_path' => $safeRuntime,
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $safeExtensionId,
            'version' => '1.0.0',
            'manifest' => json_decode($safeManifest, true, 32, JSON_THROW_ON_ERROR),
            'package_sha256' => $packageDigest,
            'signature_algorithm' => 'ed25519',
            'signing_key_id' => 'legacy.safe',
            'signature_base64' => base64_encode($signature),
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $database->insert($tables->raw('extension_trust_keys'), [
            'key_id' => 'legacy.safe',
            'algorithm' => 'ed25519',
            'public_key_base64' => base64_encode($publicKey),
            'enabled' => true,
            'added_at' => $now,
            'revoked_at' => null,
        ], ['enabled' => Types::BOOLEAN, 'added_at' => Types::DATETIME_IMMUTABLE]);
        $mapFile = $root . '/parent-runtime-map.json';
        mkdir($root . '/public', 0700);
        file_put_contents($mapFile, json_encode([
            'format' => 'kumwe-extension-map-v1',
            'generation' => 0,
            'extensions' => [['identifier' => 'legacy/demo']],
        ], JSON_THROW_ON_ERROR));

        (new TokenAndTrustLifecycleMigration($tables, $root))->up($database);
        (new IsolateThemeSurfacesMigration($tables))->up($database);
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('extension_trust_generation'),
        )));
        self::assertSame('ready', $database->fetchOne(sprintf(
            'SELECT lifecycle_state FROM %s WHERE singleton_key = 1',
            $tables->quoted('extension_trust_generation'),
        )));
        $legacyToken = $database->fetchAssociative(sprintf(
            'SELECT security_epoch, audience, purpose, site_identifier, expires_at FROM %s WHERE id = ?',
            $tables->quoted('api_tokens'),
        ), [$legacyTokenId]);
        self::assertIsArray($legacyToken);
        self::assertSame('1', (string) $legacyToken['security_epoch']);
        self::assertSame('kumwe-http', $legacyToken['audience']);
        self::assertSame('api', $legacyToken['purpose']);
        self::assertSame('default', $legacyToken['site_identifier']);
        self::assertLessThanOrEqual(
            new DateTimeImmutable('+31 days'),
            new DateTimeImmutable((string) $legacyToken['expires_at']),
        );
        self::assertSame('needs_reverification', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), ['legacy/demo']));
        $release = $database->fetchAssociative(sprintf(
            'SELECT trust_state, artifact_sha256, deployed_tree_sha256 FROM %s WHERE extension_id = ?',
            $tables->quoted('extension_releases'),
        ), [$extensionId]);
        self::assertIsArray($release);
        self::assertSame('needs_reverification', $release['trust_state']);
        self::assertNull($release['artifact_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $release['deployed_tree_sha256']);
        $safeRelease = $database->fetchAssociative(sprintf(
            'SELECT trust_state, artifact_sha256, deployed_tree_sha256 FROM %s WHERE extension_id = ?',
            $tables->quoted('extension_releases'),
        ), [$safeExtensionId]);
        self::assertIsArray($safeRelease);
        self::assertSame('verified', $safeRelease['trust_state']);
        self::assertSame($packageDigest, $safeRelease['artifact_sha256']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            (string) $safeRelease['deployed_tree_sha256'],
        );
        $compiler = $this->runtimeCompiler($database, $tables, $mapFile, $root);
        self::assertGreaterThan(0, $compiler->materialize());
        $map = json_decode((string) file_get_contents($mapFile), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertCount(1, $map['extensions']);
        self::assertSame('legacy/safe', $map['extensions'][0]['identifier']);
        self::assertSame($packageDigest, $map['extensions'][0]['artifact_sha256']);
        self::assertSame($safeRelease['deployed_tree_sha256'], $map['extensions'][0]['deployed_tree_sha256']);
        $map['extensions'][0]['provider'] = 'Local\\TamperedProvider';
        file_put_contents($mapFile, json_encode($map, JSON_THROW_ON_ERROR));
        self::assertSame((int) $map['generation'], $compiler->materialize());
        $repaired = json_decode((string) file_get_contents($mapFile), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($repaired);
        self::assertSame('Legacy\\SafeProvider', $repaired['extensions'][0]['provider']);
        if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
            $compiler->discardLocal();
            $raceDirectory = $root . '/materialization-race';
            mkdir($raceDirectory, 0700);
            $children = [];
            for ($index = 0; $index < 2; ++$index) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        while (!is_file($raceDirectory . '/start')) {
                            usleep(1_000);
                        }
                        $childContainer = (new ContainerFactory())->create(Environment::fromGlobals());
                        $childDatabase = $childContainer->get(Connection::class);
                        if (!$childDatabase instanceof Connection) {
                            throw new \RuntimeException('Child database connection is unavailable.');
                        }
                        $childCompiler = $this->runtimeCompiler(
                            $childDatabase,
                            new TableNames($childDatabase, $prefix),
                            $mapFile,
                            $root,
                        );
                        file_put_contents($raceDirectory . '/result-' . $index, (string) $childCompiler->materialize());
                    } catch (\Throwable $exception) {
                        file_put_contents(
                            $raceDirectory . '/result-' . $index,
                            'failed:' . $exception->getMessage(),
                        );
                    }
                    exit(0);
                }
                self::assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            touch($raceDirectory . '/start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
            }
            $raceResults = [
                (string) file_get_contents($raceDirectory . '/result-0'),
                (string) file_get_contents($raceDirectory . '/result-1'),
            ];
            self::assertSame($raceResults[0], $raceResults[1]);
            self::assertDoesNotMatchRegularExpression('/^failed:/D', $raceResults[0]);
            $raceMap = json_decode((string) file_get_contents($mapFile), true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($raceMap);
            self::assertSame((int) $raceResults[0], $raceMap['generation']);

            $writerStart = $raceDirectory . '/writer-start';
            $writer = pcntl_fork();
            if ($writer === 0) {
                try {
                    while (!is_file($writerStart)) {
                        usleep(1_000);
                    }
                    $child = (new ContainerFactory())->create(Environment::fromGlobals());
                    $childDatabase = $child->get(Connection::class);
                    if (!$childDatabase instanceof Connection) {
                        throw new \RuntimeException('Lifecycle writer database is unavailable.');
                    }
                    $childCompiler = $this->runtimeCompiler(
                        $childDatabase,
                        new TableNames($childDatabase, $prefix),
                        $mapFile,
                        $root,
                    );
                    for ($iteration = 0; $iteration < 8; ++$iteration) {
                        $childCompiler->advance('extension.runtime.writer-race');
                    }
                    file_put_contents($raceDirectory . '/writer-result', 'ok');
                } catch (\Throwable $exception) {
                    file_put_contents($raceDirectory . '/writer-result', 'failed:' . $exception->getMessage());
                }
                exit(0);
            }
            $materializer = pcntl_fork();
            if ($materializer === 0) {
                try {
                    while (!is_file($writerStart)) {
                        usleep(1_000);
                    }
                    $child = (new ContainerFactory())->create(Environment::fromGlobals());
                    $childDatabase = $child->get(Connection::class);
                    if (!$childDatabase instanceof Connection) {
                        throw new \RuntimeException('Lifecycle materializer database is unavailable.');
                    }
                    $childCompiler = $this->runtimeCompiler(
                        $childDatabase,
                        new TableNames($childDatabase, $prefix),
                        $mapFile,
                        $root,
                    );
                    for ($iteration = 0; $iteration < 8; ++$iteration) {
                        $childCompiler->materialize();
                    }
                    file_put_contents($raceDirectory . '/materializer-result', 'ok');
                } catch (\Throwable $exception) {
                    file_put_contents(
                        $raceDirectory . '/materializer-result',
                        'failed:' . $exception->getMessage(),
                    );
                }
                exit(0);
            }
            self::assertGreaterThan(0, $writer);
            self::assertGreaterThan(0, $materializer);
            touch($writerStart);
            foreach ([$writer, $materializer] as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
            }
            self::assertSame('ok', file_get_contents($raceDirectory . '/writer-result'));
            self::assertSame('ok', file_get_contents($raceDirectory . '/materializer-result'));
            $database->close();
            $finalGeneration = $compiler->materialize();
            $finalMap = json_decode((string) file_get_contents($mapFile), true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($finalMap);
            self::assertSame($finalGeneration, $finalMap['generation']);
        }
    }

    private function createParentLifecycleSchema(Connection $database, TableNames $tables): void
    {
        $schema = new Schema();
        $users = $schema->createTable($tables->raw('users'));
        $users->addColumn('id', Types::GUID);
        $users->addColumn('status', Types::STRING, ['length' => 32]);
        $users->setPrimaryKey(['id']);
        $roles = $schema->createTable($tables->raw('roles'));
        $roles->addColumn('id', Types::GUID);
        $roles->addColumn('code', Types::STRING, ['length' => 191]);
        $roles->setPrimaryKey(['id']);
        $capabilities = $schema->createTable($tables->raw('capabilities'));
        $capabilities->addColumn('code', Types::STRING, ['length' => 191]);
        $capabilities->addColumn('description', Types::STRING, ['length' => 255]);
        $capabilities->setPrimaryKey(['code']);
        $grants = $schema->createTable($tables->raw('role_capability_grants'));
        $grants->addColumn('id', Types::GUID);
        $grants->addColumn('role_id', Types::GUID);
        $grants->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $grants->addColumn('scope_type', Types::STRING, ['length' => 32]);
        $grants->addColumn('scope_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $grants->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $grants->addColumn('granted_by', Types::GUID, ['notnull' => false]);
        $grants->setPrimaryKey(['id']);
        $tokens = $schema->createTable($tables->raw('api_tokens'));
        $tokens->addColumn('id', Types::GUID);
        $tokens->addColumn('subject_id', Types::GUID);
        $tokens->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->setPrimaryKey(['id']);
        $extensions = $schema->createTable($tables->raw('extensions'));
        foreach (['id', 'identifier', 'extension_type', 'installed_version', 'status', 'service_provider'] as $name) {
            $extensions->addColumn($name, Types::STRING, ['length' => 255]);
        }
        $extensions->addColumn('runtime_path', Types::STRING, ['length' => 1024, 'notnull' => false]);
        $extensions->addColumn('registry_version', Types::BIGINT);
        $extensions->addColumn('installed_at', Types::DATETIME_IMMUTABLE);
        $extensions->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $extensions->setPrimaryKey(['id']);
        $releases = $schema->createTable($tables->raw('extension_releases'));
        $releases->addColumn('id', Types::GUID);
        $releases->addColumn('extension_id', Types::GUID);
        $releases->addColumn('version', Types::STRING, ['length' => 128]);
        $releases->addColumn('manifest', Types::JSON);
        $releases->addColumn('package_sha256', Types::STRING, ['length' => 64]);
        $releases->addColumn('signature_algorithm', Types::STRING, ['length' => 32, 'notnull' => false]);
        $releases->addColumn('signing_key_id', Types::STRING, ['length' => 127, 'notnull' => false]);
        $releases->addColumn('signature_base64', Types::STRING, ['length' => 256, 'notnull' => false]);
        $releases->addColumn('released_at', Types::DATETIME_IMMUTABLE);
        $releases->addColumn('installed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $releases->setPrimaryKey(['id']);
        $keys = $schema->createTable($tables->raw('extension_trust_keys'));
        $keys->addColumn('key_id', Types::STRING, ['length' => 127]);
        $keys->addColumn('algorithm', Types::STRING, ['length' => 32]);
        $keys->addColumn('public_key_base64', Types::STRING, ['length' => 128]);
        $keys->addColumn('enabled', Types::BOOLEAN);
        $keys->addColumn('added_at', Types::DATETIME_IMMUTABLE);
        $keys->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $keys->setPrimaryKey(['key_id']);
        $generation = $schema->createTable($tables->raw('extension_runtime_generation'));
        $generation->addColumn('singleton_key', Types::SMALLINT);
        $generation->addColumn('generation', Types::BIGINT);
        $generation->addColumn('rebuilt_at', Types::DATETIME_IMMUTABLE);
        $generation->setPrimaryKey(['singleton_key']);
        // Simulates interrupted MySQL/MariaDB DDL: table exists, lifecycle column and singleton row do not.
        $trustGeneration = $schema->createTable($tables->raw('extension_trust_generation'));
        $trustGeneration->addColumn('singleton_key', Types::SMALLINT);
        $trustGeneration->addColumn('generation', Types::BIGINT, ['default' => 0]);
        $trustGeneration->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $trustGeneration->setPrimaryKey(['singleton_key']);
        $idempotency = $schema->createTable($tables->raw('idempotency'));
        $idempotency->addColumn('id', Types::GUID);
        $idempotency->addColumn('idempotency_key', Types::STRING, ['length' => 128]);
        $idempotency->addColumn('subject', Types::STRING, ['length' => 255]);
        $idempotency->addColumn('operation', Types::STRING, ['length' => 160]);
        $idempotency->addColumn('request_digest', Types::STRING, ['length' => 64]);
        $idempotency->addColumn('state', Types::STRING, ['length' => 16]);
        $idempotency->addColumn('result_body', Types::TEXT, ['notnull' => false]);
        $idempotency->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $idempotency->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $idempotency->setPrimaryKey(['id']);
        $idempotency->addUniqueIndex(['subject', 'operation', 'idempotency_key']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
        $database->insert($tables->raw('extension_runtime_generation'), [
            'singleton_key' => 1,
            'generation' => 0,
            'rebuilt_at' => new DateTimeImmutable(),
        ], ['rebuilt_at' => Types::DATETIME_IMMUTABLE]);
    }

    private function runtimeCompiler(
        Connection $database,
        TableNames $tables,
        string $mapFile,
        string $root,
    ): ExtensionRuntimeMapCompiler {
        $process = (string) (getmypid() ?: 0);

        return new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $mapFile,
            $root,
            $root . '/public',
            new SystemClock(),
            new RuntimeIdentity(
                'migration-test-deployment',
                'migration-test-replica',
                'process-' . $process,
                'instance-' . $process,
            ),
            new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4)),
            new RuntimeArtifactDigester(),
        );
    }
}
