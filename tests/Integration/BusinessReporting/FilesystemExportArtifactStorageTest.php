<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessReporting;

use Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FilesystemExportArtifactStorage::class)]
final class FilesystemExportArtifactStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kumwe-export-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($this->directory);
    }

    public function testAtomicallyStoresDistinctAttemptFencedAndVerifiedPrivateBytes(): void
    {
        $storage = new FilesystemExportArtifactStorage($this->directory, 1024);
        $id = '018f22e2-7c8b-7ab0-8f3a-88e8026bb710';
        $stored = $storage->store($id, ["\xEF\xBB\xBF", "\"Name\"\r\n", "\"Alice\"\r\n"]);
        $second = $storage->store($id, ['different']);
        $stream = $storage->open($stored);

        self::assertIsResource($stream);
        self::assertSame("\xEF\xBB\xBF\"Name\"\r\n\"Alice\"\r\n", stream_get_contents($stream));
        fclose($stream);
        self::assertNotSame($stored->key, $second->key);
        self::assertMatchesRegularExpression(
            '/^' . preg_quote($id, '/') . '\.[0-9a-f]{32}\.csv$/D',
            $stored->key,
        );
        self::assertSame(0600, fileperms($this->directory . '/' . $stored->key) & 0777);
        self::assertSame(0600, fileperms($this->directory . '/' . $second->key) & 0777);
    }

    public function testRejectsUppercaseAttemptOwnershipAndDirectObjectKeys(): void
    {
        $storage = new FilesystemExportArtifactStorage($this->directory, 1024);
        $id = '018f22e2-7c8b-7ab0-8f3a-88e8026bb710';

        try {
            $storage->store(strtoupper($id), ['bytes']);
            self::fail('An uppercase artifact owner must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('An export artifact storage key is invalid.', $exception->getMessage());
        }

        try {
            $storage->delete(strtoupper($id) . '.' . str_repeat('a', 32) . '.csv');
            self::fail('An uppercase direct storage key must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('An export artifact storage key is invalid.', $exception->getMessage());
        }
    }
}
