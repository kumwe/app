<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Executes host recovery filesystem guards without replacing database or signing commands with doubles.
 *
 * Native database replay, real Minisign and interrupted restore are exercised by recovery-native-drill.sh.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class RecoveryToolsTest extends TestCase
{
    /**
     * Pin retained formats, exact inventories, target ownership and refusals before database mutation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFilesystemAndTargetRefusalsExecuteTheRealShellTools(): void
    {
        $process = proc_open(
            ['bash', dirname(__DIR__, 2) . '/Support/recovery-tools-test.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $output . (string) $error);
        self::assertIsString($output);
        self::assertStringContainsString('11 filesystem/refusal checks without command doubles', $output);
    }
}
