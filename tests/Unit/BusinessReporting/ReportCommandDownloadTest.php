<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Delivery\Console\ReportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ReportCommand::class)]
final class ReportCommandDownloadTest extends TestCase
{
    public function testDownloadCreatesANewPrivateFileAndVerifiesItsChecksum(): void
    {
        $directory = sys_get_temp_dir() . '/kumwe-report-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/report.csv';
        $source = fopen('php://memory', 'w+b');
        self::assertIsResource($source);
        $bytes = "\xEF\xBB\xBF\"Name\"\r\n\"Alice\"\r\n";
        fwrite($source, $bytes);
        rewind($source);
        $reflection = new ReflectionClass(ReportCommand::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $write = $reflection->getMethod('writeDownload');

        $write->invoke($command, $path, $source, strlen($bytes), hash('sha256', $bytes));

        self::assertSame($bytes, file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        unlink($path);
        rmdir($directory);
    }

    public function testDownloadNeverOverwritesAnExistingFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-existing-report-');
        self::assertIsString($path);
        $source = fopen('php://memory', 'w+b');
        self::assertIsResource($source);
        fwrite($source, 'replacement');
        rewind($source);
        $reflection = new ReflectionClass(ReportCommand::class);
        $command = $reflection->newInstanceWithoutConstructor();

        try {
            $this->expectException(InvalidArgumentException::class);
            $reflection->getMethod('writeDownload')->invoke(
                $command,
                $path,
                $source,
                11,
                hash('sha256', 'replacement'),
            );
        } finally {
            unlink($path);
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
