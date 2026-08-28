<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Infrastructure\Package;

use InvalidArgumentException;
use Kumwe\Extension\Package\ZipArchiveContentReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(ZipArchiveContentReader::class)]
/**
 * Pins what one expanded entry is allowed to cost, alongside the reader's ordinary contract.
 *
 * Admission retains two entries for the whole of a scan — the bill of materials and the provenance
 * statement — so the reader's memory cost is not a private implementation detail: it is multiplied by
 * however many entries the caller holds on to. Asking `ZipArchive::getFromIndex()` for the per-entry
 * ceiling used to charge that ceiling for every entry no matter how small it really was, and never
 * gave the slack back, which exhausted a deployed 256M limit part-way through an unremarkable package.
 * The memory test below is the regression guard for that; the rest cover the chunked read itself.
 *
 * @since  2.0.0
 */
final class ZipArchiveContentReaderTest extends TestCase
{
    /**
     * Private directory allocated for one test invocation.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $temporary;

    /**
     * Allocate a private test directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/kumwe-zip-reader-' . bin2hex(random_bytes(6));
        mkdir($base, 0o700, true);
        $this->temporary = $base;
    }

    /**
     * Remove the private test directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach (glob($this->temporary . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    /**
     * Small entries stay small in memory even when the caller retains every one of them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainingEveryEntryCostsTheEntryBytesRatherThanTheCeiling(): void
    {
        $archive = $this->archive([
            'kumwe.sbom.json' => str_repeat('s', 4_096),
            'kumwe.provenance.json' => str_repeat('p', 4_096),
            'a.php' => '<?php',
            'b.php' => '<?php',
            'c.php' => '<?php',
            'd.php' => '<?php',
            'e.php' => '<?php',
        ]);

        $before = memory_get_usage(true);
        $retained = [];
        foreach ((new ZipArchiveContentReader())->contents($archive) as $path => $entry) {
            $retained[$path] = $entry;
        }
        $growth = memory_get_usage(true) - $before;

        self::assertCount(7, $retained);
        self::assertLessThan(8 * 1024 * 1024, $growth);
    }

    /**
     * An entry longer than one read chunk is reassembled exactly, in central-directory order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEntriesSpanningManyChunksAreReassembledByteForByte(): void
    {
        $long = random_bytes(700_000);
        $archive = $this->archive([
            'first.txt' => 'first',
            'long.bin' => $long,
            'empty.txt' => '',
        ]);

        $read = [];
        foreach ((new ZipArchiveContentReader())->contents($archive) as $path => $entry) {
            $read[$path] = $entry;
        }

        self::assertSame(['first.txt', 'long.bin', 'empty.txt'], array_keys($read));
        self::assertSame($long, $read['long.bin']);
        self::assertSame('', $read['empty.txt']);
    }

    /**
     * Directory entries are skipped rather than yielded as empty files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDirectoryEntriesAreSkipped(): void
    {
        $archive = $this->temporary . '/dirs.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addEmptyDir('src');
        $zip->addFromString('src/Thing.php', '<?php');
        $zip->close();

        $read = iterator_to_array((new ZipArchiveContentReader())->contents($archive));

        self::assertSame(['src/Thing.php'], array_keys($read));
    }

    /**
     * A file that is not a ZIP archive is refused before anything is expanded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonArchiveIsRefused(): void
    {
        $path = $this->temporary . '/not-a-zip.bin';
        file_put_contents($path, 'plainly not a zip archive');

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new ZipArchiveContentReader())->contents($path));
    }

    /**
     * Build a ZIP archive holding the supplied entries.
     *
     * @param   array<string, string>  $entries  Entry bytes keyed by package path.
     *
     * @return  string  Absolute path of the written archive.
     *
     * @since   2.0.0
     */
    private function archive(array $entries): string
    {
        $path = $this->temporary . '/package.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return $path;
    }
}
