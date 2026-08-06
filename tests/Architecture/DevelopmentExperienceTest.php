<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DevelopmentExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDevelopmentComposePublishesTheConfiguredHostPort(): void
    {
        $compose = $this->contents('compose.yaml');

        self::assertStringContainsString(
            'APP_BASE_URL: "http://${KUMWE_HTTP_HOST:-localhost}:${KUMWE_HTTP_PORT:-8080}"',
            $compose,
        );
        self::assertStringContainsString(
            '"${KUMWE_HTTP_BIND:-127.0.0.1}:${KUMWE_HTTP_PORT:-8080}:8080"',
            $compose,
        );
    }

    public function testDevelopmentServerKeepsReadinessCurrentAndServesStaticAssets(): void
    {
        $compose = $this->contents('compose.yaml');
        $server = $this->contents('tools/development-server.sh');

        self::assertStringContainsString('command: sh tools/development-server.sh', $compose);
        self::assertStringContainsString(
            "file_get_contents('http://127.0.0.1:8080/health/ready')",
            $compose,
        );
        self::assertStringContainsString('extension:runtime:watch --once', $server);
        self::assertStringContainsString('extension:runtime:watch --interval=10', $server);
        self::assertStringContainsString('-t public tools/browser-router.php', $server);
        self::assertStringContainsString('Kumwe runtime watcher stopped', $server);
    }

    public function testFreshDevelopmentInstallIsExecutableOnANonDefaultPort(): void
    {
        $workflow = $this->contents('.github/workflows/development-compose.yml');

        foreach (
            [
                'KUMWE_HTTP_PORT=9900',
                'composer install --no-interaction --prefer-dist',
                'docker compose up -d --wait --wait-timeout 180',
                'http://127.0.0.1:9900/health/ready',
                '/assets/default-site.css',
                'sleep 35',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $workflow);
        }

        self::assertStringContainsString(
            '"php-http/discovery": true',
            $this->contents('composer.json'),
        );
    }

    public function testFirstRunPresentationIsACompleteResponsiveSurface(): void
    {
        $template = $this->contents('templates/site/home.twig');
        $stylesheet = $this->contents('public/assets/default-site.css');

        foreach (
            [
                'Content systems',
                'Editor-first administration',
                'Governed publishing',
                'One content core. Every delivery surface.',
                '/assets/default-site.css',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $template);
        }

        foreach (
            [
                '.welcome-hero-grid',
                '.welcome-workspace',
                '.welcome-card-grid',
                '.welcome-platform-grid',
                '@media (max-width: 46rem)',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $stylesheet);
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
