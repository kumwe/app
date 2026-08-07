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
                'kumwe-wordmark.svg',
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

    public function testFreshPresentationIsSeededManagedContent(): void
    {
        $migration = $this->contents('src/Infrastructure/Persistence/Migration/DynamicSiteContentMigration.php');
        $template = $this->contents('templates/site/page.twig');
        $stylesheet = $this->contents('assets/site/styles.css');

        foreach (
            [
                'Content systems',
                'One content core. Every delivery surface.',
                'site.homepage_content_id',
                'kumwe-wordmark.svg',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $migration);
        }

        foreach (
            [
                'entry.data',
                'page.capabilities.body_html',
                'page.platform.body_html',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $template);
        }
        self::assertStringContainsString('.managed-hero-grid', $stylesheet);
        self::assertStringContainsString('@media (max-width: 46rem)', $stylesheet);
        self::assertFileExists(
            $this->root . '/resources/media/default/00000000-0000-7000-8000-000000000902.svg',
        );
        foreach ([
            'resources/media/default/00000000-0000-7000-8000-000000000901.svg',
            'resources/media/default/00000000-0000-7000-8000-000000000902.svg',
        ] as $asset) {
            $svg = $this->contents($asset);
            self::assertStringStartsWith('<svg ', $svg);
            self::assertDoesNotMatchRegularExpression(
                '/<(?:script|foreignObject)|\bon[a-z]+\s*=|\b(?:href|xlink:href)\s*=/i',
                $svg,
            );
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
