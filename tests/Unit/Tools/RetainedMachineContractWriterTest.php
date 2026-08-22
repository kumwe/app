<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Tools;

use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use Kumwe\App\Tools\RetainedMachineContractWriter;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves retained generation artifacts can be established but never overwritten with drifted bytes.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class RetainedMachineContractWriterTest extends TestCase
{
    /**
     * Allow a missing artifact and an identical repeat while rejecting a different retained generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedBytesCannotBeReplacedByWriteMode(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/RetainedMachineContractWriter.php';

        $path = sys_get_temp_dir() . '/kumwe-retained-contract-' . bin2hex(random_bytes(12)) . '.json';
        $original = "{\"generation\":\"v1\"}\n";

        try {
            self::assertTrue(RetainedMachineContractWriter::establish($path, $original));
            self::assertFalse(RetainedMachineContractWriter::establish($path, $original));

            try {
                RetainedMachineContractWriter::establish($path, "{\"generation\":\"changed\"}\n");
                self::fail('Different bytes must not replace a retained generation.');
            } catch (LogicException) {
                self::assertSame($original, file_get_contents($path));
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The CLI documentation mirror cannot turn changed v1 bytes into an accepted retained generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCliV1DocumentationMirrorRefusesChangedGenerationBytes(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/RetainedMachineContractWriter.php';

        $path = sys_get_temp_dir() . '/kumwe-retained-cli-' . bin2hex(random_bytes(12)) . '.json';
        $retained = CliV1MachineContract::json();
        $replacements = 0;
        $changed = str_replace('"generation": 1', '"generation": 2', $retained, $replacements);
        self::assertSame(1, $replacements);

        try {
            self::assertTrue(RetainedMachineContractWriter::establish($path, $retained));

            try {
                RetainedMachineContractWriter::establish($path, $changed);
                self::fail('Changed CLI v1 bytes must require a successor generation.');
            } catch (LogicException) {
                self::assertSame($retained, file_get_contents($path));
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
