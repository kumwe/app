<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

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
        self::assertStringContainsString('pdo_pgsql pgsql', $dockerfile);
        self::assertStringContainsString('pecl install redis-6.3.0', $dockerfile);
        self::assertStringContainsString('USER www-data', $dockerfile);
        self::assertStringContainsString(
            'COPY --from=vendor --chown=nginx:nginx /var/www/kumwe/public /var/www/kumwe/public',
            $dockerfile,
        );
        self::assertStringNotContainsString('COPY --from=vendor /var/www/kumwe /var/www/kumwe/public', $dockerfile);
    }

    public function testProductionTopologyKeepsDataServicesInternalAndSecretsFileBacked(): void
    {
        $compose = $this->contents('compose.production.yaml');

        foreach (['web:', 'app:', 'worker:', 'scheduler:', 'migrate:', 'postgres:', 'redis:'] as $service) {
            self::assertStringContainsString($service, $compose);
        }

        self::assertStringContainsString('internal: true', $compose);
        self::assertStringContainsString('APP_SECRET_FILE: /run/secrets/app_secret', $compose);
        self::assertStringContainsString('DB_PASSWORD_FILE: /run/secrets/db_password', $compose);
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
        $verify = $this->contents('tools/restore-verify.sh');

        self::assertStringContainsString('set -Eeuo pipefail', $backup);
        self::assertStringContainsString('KUMWE_BACKUP_CONSISTENCY', $backup);
        self::assertStringContainsString('20260804000800_create_application_runtime', $backup);
        self::assertStringContainsString('product_major: 2', $backup);
        self::assertStringContainsString('set -Eeuo pipefail', $verify);
        self::assertStringContainsString('Kumwe 1.x and unknown formats are refused', $verify);
    }

    public function testObservabilityContractIsPrivateByDefault(): void
    {
        /**
         * @var array{
         *     logging: array{destination: string},
         *     health: array{expose_details: bool},
         *     metrics: array{enabled: bool, public: bool}
         * } $configuration
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
