<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the shipped installer entry point in an isolated project directory.
 *
 * @since  2.0.0-beta.1
 */
#[CoversNothing]
final class ProjectInstallerTest extends TestCase
{
    /**
     * Non-interactive distribution bootstrap creates no configuration or database side effect.
     *
     * @return  void
     *
     * @since   2.0.0-beta.1
     */
    public function testNonInteractiveBootstrapAndExistingConfigurationRemainSafe(): void
    {
        $directory = sys_get_temp_dir() . '/kumwe-installer-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory . '/bin', 0700, true));
        foreach (['kumwe-install', 'kumwe-install-functions.php'] as $name) {
            self::assertTrue(copy(dirname(__DIR__, 3) . '/bin/' . $name, $directory . '/bin/' . $name));
        }

        try {
            [$status, $output, $error] = self::execute($directory);
            self::assertSame(0, $status, $error);
            self::assertStringContainsString('installed without interactive configuration', $output);
            self::assertFileDoesNotExist($directory . '/.env');

            $existing = "APP_ENV=production\n# Operator-owned configuration\n";
            file_put_contents($directory . '/.env', $existing);
            [$status, $output, $error] = self::execute($directory);
            self::assertSame(0, $status, $error);
            self::assertStringContainsString('.env was not changed', $output);
            self::assertSame($existing, file_get_contents($directory . '/.env'));
        } finally {
            foreach (['.env', 'bin/kumwe-install', 'bin/kumwe-install-functions.php'] as $name) {
                if (is_file($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    /**
     * Run the actual PHP entry point with closed input, capturing both output streams.
     *
     * @param   string  $directory  Isolated installed project root.
     *
     * @return  array{int, string, string}  Exit status, output and diagnostics.
     *
     * @since   2.0.0-beta.1
     */
    private static function execute(string $directory): array
    {
        $process = proc_open(
            [PHP_BINARY, $directory . '/bin/kumwe-install', '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
