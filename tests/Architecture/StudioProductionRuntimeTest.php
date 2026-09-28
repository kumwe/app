<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Refuses any production Node.js, npm or Vite requirement for Studio (STUDIO-PROD-011, ADR 0020 section 5).
 *
 * Studio authoring, preview, save, publication and public rendering run on compiled, committed browser
 * assets and PHP alone. Node.js and npm build and test those assets; they must never appear in a runtime
 * image, a production service, a startup or install hook, the front controller, or a process the PHP code
 * starts. These checks fail the build the moment any of those surfaces gains such a dependency.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioProductionRuntimeTest extends TestCase
{
    /**
     * Tokens that name a JavaScript runtime, package manager or development server.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string JAVASCRIPT_RUNTIME = '/\b(?:node|nodejs|npm|npx|yarn|pnpm|vite|corepack)\b/i';

    /**
     * Production images, services, entry points and web server configuration never name a JavaScript runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionImagesServicesAndEntryPointsNeverRequireNode(): void
    {
        foreach (
            [
                'docker/php/Dockerfile',
                'docker/nginx/default.conf',
                'compose.production.yaml',
                'public/index.php',
                'bin/kumwe',
                'bin/kumwe-install',
            ] as $path
        ) {
            self::assertDoesNotMatchRegularExpression(
                self::JAVASCRIPT_RUNTIME,
                $this->contents($path),
                sprintf('%s must not require Node.js, npm or Vite in production.', $path),
            );
        }
    }

    /**
     * Composer lifecycle hooks that run on an operator's install never invoke a JavaScript toolchain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testComposerInstallHooksNeverInvokeNode(): void
    {
        $composer = json_decode($this->contents('composer.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $scripts = $composer['scripts'] ?? [];
        self::assertIsArray($scripts);
        foreach ($scripts as $name => $commands) {
            if (!is_string($name) || preg_match('/^(?:pre|post)-/', $name) !== 1) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                self::JAVASCRIPT_RUNTIME,
                json_encode($commands, JSON_THROW_ON_ERROR),
                sprintf('The Composer %s hook must not invoke a JavaScript toolchain.', $name),
            );
        }
    }

    /**
     * No production PHP source starts a JavaScript process.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionPhpNeverSpawnsAJavaScriptProcess(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $checked++;
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:exec|shell_exec|proc_open|passthru|system|popen)\s*\([^;]*\b(?:node|npm|npx|vite)\b/i',
                $source,
                sprintf('%s must not start a JavaScript process.', substr($file->getPathname(), strlen($root) + 1)),
            );
        }
        self::assertGreaterThan(500, $checked);
    }

    /**
     * The Content editor's Studio launch module is a committed compiled asset the PHP availability gate reads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStudioLaunchModuleShipsAsACommittedCompiledAsset(): void
    {
        $manifest = json_decode(
            $this->contents('public/assets/build/.vite/manifest.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $entry = $manifest['assets/administrator/components/studio-launch.ts'] ?? null;
        self::assertIsArray($entry, 'The compiled manifest must carry the Studio launch module.');
        $file = $entry['file'] ?? null;
        self::assertIsString($file);
        self::assertMatchesRegularExpression('#^js/studio-launch-[A-Za-z0-9_-]+\.js$#', $file);
        self::assertFileExists(dirname(__DIR__, 2) . '/public/assets/build/' . $file);
        self::assertStringContainsString(
            "'/administrator/studio/ports/'",
            $this->contents('src/Studio/Application/Authoring/HostedContentStudioAuthoringConfigurationProvider.php'),
        );
    }

    /**
     * Read one required repository file.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  File bytes.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
