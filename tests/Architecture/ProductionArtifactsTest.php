<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessTransactionalRuntimeMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProductionArtifactsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRuntimeImageIsNonRootAndWebImageReceivesOnlyPublicFiles(): void
    {
        $dockerfile = $this->contents('docker/php/Dockerfile');

        self::assertStringContainsString('FROM php-base AS runtime', $dockerfile);
        self::assertStringContainsString('pdo_mysql pdo_pgsql', $dockerfile);
        self::assertStringContainsString('pecl install redis-6.3.0', $dockerfile);
        self::assertStringContainsString('apcu-5.1.28', $dockerfile);
        self::assertStringContainsString('mbstring pcntl pdo_mysql', $dockerfile);
        self::assertStringContainsString('USER www-data', $dockerfile);
        self::assertStringContainsString(
            'COPY --from=vendor --chown=nginx:nginx /var/www/kumwe/public /var/www/kumwe/public',
            $dockerfile,
        );
        self::assertStringNotContainsString('COPY --from=vendor /var/www/kumwe /var/www/kumwe/public', $dockerfile);
    }

    public function testOnlyTheDedicatedPublicDirectoryContainsWebEntrypoints(): void
    {
        foreach (['index.php', '.htaccess', 'robots.txt.dist', 'web.config.txt'] as $legacyRootFile) {
            self::assertFileDoesNotExist(
                $this->root . '/' . $legacyRootFile,
                sprintf('Legacy web-root artifact %s must not be shipped.', $legacyRootFile),
            );
        }

        self::assertFileExists($this->root . '/public/index.php');
        self::assertFileDoesNotExist($this->root . '/public/robots.txt');

        $nginx = $this->contents('docker/nginx/default.conf');
        self::assertStringNotContainsString('location = /robots.txt', $nginx);
        self::assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $nginx);

        $container = $this->contents('src/Kernel/ContainerFactory.php');
        self::assertStringContainsString("get('/robots.txt', RobotsHandler::class", $container);
    }

    public function testProductionTopologyKeepsDataServicesInternalAndSecretsFileBacked(): void
    {
        $compose = $this->contents('compose.production.yaml');

        foreach (['web:', 'app:', 'worker:', 'scheduler:', 'migrate:', 'database:', 'redis:'] as $service) {
            self::assertStringContainsString($service, $compose);
        }

        self::assertStringContainsString('internal: true', $compose);
        self::assertStringContainsString('ghcr.io/kumwe/cms/app:latest', $compose);
        self::assertStringContainsString('ghcr.io/kumwe/cms/web:latest', $compose);
        self::assertStringContainsString('KUMWE_DATABASE_IMAGE:-mariadb:lts', $compose);
        self::assertStringContainsString('KUMWE_REDIS_IMAGE:-redis:8-alpine', $compose);
        self::assertStringContainsString('APP_SECRET_FILE: /run/secrets/app_secret', $compose);
        self::assertStringContainsString(
            'EXTENSION_RUNTIME_SIGNING_KEY_FILE: /run/secrets/runtime_signing_key',
            $compose,
        );
        self::assertStringContainsString('DB_PASSWORD_FILE: /run/secrets/db_password', $compose);
        self::assertStringContainsString(
            'extension-assets-data:/var/www/kumwe/public/assets/extensions',
            $compose,
        );
        self::assertStringContainsString('private-data:/var/www/kumwe/storage/private', $compose);
        self::assertStringContainsString('profiles: [automation]', $compose);
    }

    public function testReleaseAndSecurityActionsAreCommitPinned(): void
    {
        foreach (['.github/workflows/security.yml', '.github/workflows/release.yml'] as $workflow) {
            $contents = $this->contents($workflow);
            self::assertDoesNotMatchRegularExpression(
                '/uses:\s+[^\s@]+@(?![a-f0-9]{40}(?:\s|$))[^\s]+/i',
                $contents,
                sprintf('Workflow actions must be pinned in %s.', $workflow),
            );
        }
    }

    public function testBackupToolsAreFailClosedAndRefuseNonV2Data(): void
    {
        $backup = $this->contents('tools/backup.sh');
        $restore = $this->contents('tools/restore.sh');
        $verify = $this->contents('tools/restore-verify.sh');

        self::assertStringContainsString('set -Eeuo pipefail', $backup);
        self::assertStringContainsString('KUMWE_BACKUP_CONSISTENCY', $backup);
        self::assertStringContainsString(BusinessTransactionalRuntimeMigration::ID, $backup);
        self::assertStringContainsString(BusinessTransactionalRuntimeMigration::ID, $restore);
        self::assertStringContainsString('mariadb|mysql|pgsql', $backup);
        self::assertStringContainsString('--no-tablespaces', $backup);
        self::assertStringContainsString('--set-gtid-purged=OFF', $backup);
        self::assertStringNotContainsString('--routines', $backup);
        self::assertStringNotContainsString('--events', $backup);
        self::assertStringContainsString('${#table_prefix} -le 28', $backup);
        self::assertStringContainsString('^[a-z][a-z0-9]*(_[a-z0-9]+)*_$', $backup);
        self::assertStringContainsString('${#table_prefix} -le 28', $restore);
        self::assertStringContainsString('database_table_prefix | length <= 28', $verify);
        self::assertStringContainsString('product_major: 2', $backup);
        self::assertStringContainsString('extension-assets.tar.gz', $backup);
        self::assertStringContainsString('KUMWE_PRIVATE_DIR', $backup);
        self::assertStringContainsString('private.tar.gz', $backup);
        self::assertStringContainsString('KUMWE_RESTORE_PRIVATE_DIR', $restore);
        self::assertStringContainsString('private.tar.gz', $restore);
        self::assertStringContainsString('private.tar.gz', $verify);
        self::assertStringContainsString('set -Eeuo pipefail', $verify);
        self::assertStringContainsString('Kumwe 1.x and unknown formats are refused', $verify);
    }

    public function testCiDeploysEverySupportedDatabaseAndComposerDistribution(): void
    {
        $ci = $this->contents('.github/workflows/ci.yml');
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');

        foreach (['mariadb:lts', 'mysql:8.4', 'postgres:17-alpine'] as $databaseImage) {
            self::assertStringContainsString($databaseImage, $ci);
            self::assertStringContainsString($databaseImage, $acceptance);
        }

        self::assertStringContainsString("php-version: '8.5'", $ci);
        self::assertStringContainsString('php bin/kumwe database:migrate', $acceptance);
        self::assertStringContainsString('php bin/kumwe user:create-admin', $acceptance);
        self::assertStringContainsString('Composer and ZIP installation', $acceptance);
        self::assertStringContainsString('bash tools/deployment-probe.sh', $acceptance);
        self::assertStringContainsString('Restore a production backup into a clean database', $acceptance);

        $probe = $this->contents('tools/deployment-probe.sh');
        self::assertStringContainsString('user without administrator.access', $probe);
        self::assertStringContainsString('Idempotency-Replayed: true', $probe);
        self::assertStringContainsString('kumwe_content_list', $probe);
        self::assertStringContainsString('kumwe_content_create', $probe);
    }

    /**
     * Require the production database matrix to execute the signed proof package and clean restore.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentAcceptanceExercisesTheCompleteSignedExtensionLifecycle(): void
    {
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');
        $driver = $this->contents('tools/asset-inspection-deployment-acceptance.sh');

        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh package', $acceptance);
        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh grant', $acceptance);
        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh exercise', $acceptance);
        self::assertStringContainsString('asset-inspection-deployment-acceptance.php', $acceptance);
        self::assertStringContainsString('extension:runtime:materialize', $acceptance);
        self::assertStringContainsString('KUMWE_ACCEPTANCE_ASSET_MANIFEST', $acceptance);
        self::assertStringContainsString('KUMWE_ACCEPTANCE_ASSET_STATE', $acceptance);
        self::assertStringContainsString('$source_private/report-exports/objects', $acceptance);
        self::assertStringContainsString('app web worker scheduler', $acceptance);

        foreach (['extension:build', 'extension:inspect', 'extension:conformance', 'extension:sign'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        foreach (['extension:install', 'extension:activate', 'extension:disable'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        foreach (['business-record create', 'business-record relate', 'integration:work --once'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        self::assertStringContainsString('Idempotency-Replayed', $driver);
        self::assertStringContainsString("del(.replayed)", $driver);
        self::assertStringContainsString('ordered_record_ids', $driver);
        self::assertStringContainsString('/order', $driver);
        $schemaLoopStart = strpos($driver, 'for definition in');
        if ($schemaLoopStart === false) {
            self::fail('The deployment schema-install loop is unavailable.');
        }
        $schemaLoopEnd = strpos($driver, 'install_schema "$definition"', $schemaLoopStart);
        if ($schemaLoopEnd === false) {
            self::fail('The deployment schema-install call is unavailable.');
        }
        $schemaLoop = substr($driver, $schemaLoopStart, $schemaLoopEnd - $schemaLoopStart);
        $schemaCursor = 0;
        foreach (
            [
            '019bc200-0000-7000-8000-000000000001',
            '019bc200-0000-7000-8000-000000000002',
            '019bc200-0000-7000-8000-000000000004',
            '019bc200-0000-7000-8000-000000000005',
            '019bc200-0000-7000-8000-000000000003',
            ] as $definitionId
        ) {
            $definitionPosition = strpos($schemaLoop, $definitionId, $schemaCursor);
            if ($definitionPosition === false) {
                self::fail('The deployment schema-install order is not dependency-safe.');
            }
            $schemaCursor = $definitionPosition + strlen($definitionId);
        }
        self::assertStringContainsString('access grant', $driver);
        self::assertStringContainsString('integration:manage projection-rebuild', $driver);
        self::assertStringContainsString('integration:manage projections', $driver);
        self::assertStringContainsString('kumwe.asset-inspection-example.integration', $driver);
        self::assertStringContainsString('business-report export', $driver);
        self::assertStringContainsString('kumwe_business_report_execute', $driver);
        self::assertStringContainsString('--force-recreate app web worker scheduler', $driver);
    }

    /**
     * Keep deployment bootstrap credentials unscoped until an exact live membership exists.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentAcceptanceBindsSensitiveTokensAfterMembershipBootstrap(): void
    {
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');
        $driver = $this->contents('tools/asset-inspection-deployment-acceptance.sh');
        $support = $this->contents('tests/Support/AssetInspectionDeploymentAcceptance.php');
        $bootstrapStart = strpos($acceptance, '--name=deployment-cli-management');
        if ($bootstrapStart === false) {
            self::fail('The deployment management-token bootstrap is missing.');
        }
        $bootstrapEnd = strpos($acceptance, '--password-file="$password_file"', $bootstrapStart);
        if ($bootstrapEnd === false) {
            self::fail('The deployment management-token bootstrap is incomplete.');
        }
        $bootstrap = substr($acceptance, $bootstrapStart, $bootstrapEnd - $bootstrapStart);
        if (preg_match('/--capabilities=([^\s\\\\]+)/D', $bootstrap, $workflowCapabilities) !== 1) {
            self::fail('The workflow bootstrap capability set is unavailable.');
        }
        if (preg_match("/bootstrap_capabilities='([^']+)'/D", $driver, $driverCapabilities) !== 1) {
            self::fail('The driver bootstrap capability set is unavailable.');
        }

        self::assertStringContainsString('business.schema.plan', $bootstrap);
        self::assertSame($driverCapabilities[1], $workflowCapabilities[1]);
        foreach (['business.record.', 'business.security.manage', 'business.step_up.manage'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $bootstrap);
        }
        self::assertStringNotContainsString('--organization=', $bootstrap);
        self::assertStringContainsString('--organization="$KUMWE_ACCEPTANCE_ORGANIZATION"', $driver);
        self::assertStringContainsString("delegated_capabilities=\"\${delegated_capabilities", $driver);
        self::assertStringContainsString("        refresh_bootstrap_token\n", $driver);
        self::assertStringContainsString("apply_policy_profile\n    refresh_management_token", $driver);
        self::assertStringContainsString('acceptance_php apply-seed-policy', $driver);
        self::assertStringContainsString('asset-inspection-seed-policy-operator@kumwe.test', $driver);
        self::assertStringContainsString('(.policy_ids | length == 10)', $driver);
        self::assertStringContainsString('(.policy_ids | length == 8)', $driver);
        self::assertStringContainsString('$security->createOrganization(', $support);
        self::assertStringContainsString('$security->createMembership(', $support);
        self::assertStringContainsString('private static function seedPolicyRequests(', $support);
        self::assertStringContainsString("'business.record.create'", $support);
        self::assertStringContainsString("'business.record.relate'", $support);
        self::assertStringContainsString('use DateTimeImmutable;', $support);
        self::assertStringContainsString('private static function requiredRowInteger(', $support);
        self::assertStringContainsString('asset_manifest_for_restore', $acceptance);
        self::assertStringContainsString('sudo chown 82:82 "$asset_manifest_for_restore"', $acceptance);
        self::assertStringContainsString("sudo bash -euo pipefail -c '\n              cd \"\$1\"", $acceptance);
    }

    public function testNativeInstallerPersistsIndependentRuntimeTrustAndStableIdentity(): void
    {
        $installer = $this->contents('bin/kumwe-install');

        self::assertStringContainsString(
            "'EXTENSION_RUNTIME_SIGNING_KEY' => base64_encode(random_bytes(48))",
            $installer,
        );
        self::assertStringContainsString("'KUMWE_DEPLOYMENT_ID' =>", $installer);
        self::assertStringContainsString('strlen($databasePrefix) > 28', $installer);
        self::assertStringContainsString("'KUMWE_REPLICA_ID' => 'primary-replica'", $installer);
        self::assertStringContainsString("'KUMWE_PROCESS_ID' => 'application-runtime'", $installer);
        self::assertStringContainsString("'KUMWE_INSTANCE_ID' => 'primary-instance'", $installer);
    }

    public function testObservabilityContractIsPrivateByDefault(): void
    {
        /**
         * @var    array{
         *             logging: array{destination: string},
         *             health: array{expose_details: bool},
         *             metrics: array{enabled: bool, public: bool}
         *         } $configuration
         */
        $configuration = require $this->root . '/config/observability.php';

        self::assertFalse($configuration['metrics']['enabled']);
        self::assertFalse($configuration['metrics']['public']);
        self::assertFalse($configuration['health']['expose_details']);
        self::assertSame('php://stderr', $configuration['logging']['destination']);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
