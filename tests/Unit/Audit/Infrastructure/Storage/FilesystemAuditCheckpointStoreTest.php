<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Infrastructure\Storage;

use Kumwe\App\Audit\Application\AuditCheckpoint;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditCheckpointStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FilesystemAuditCheckpointStore::class)]
final class FilesystemAuditCheckpointStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-checkpoints-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * Checkpoints only ever advance, each published once, and the highest one is what is retained.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedCheckpointsOnlyAdvance(): void
    {
        $store = new FilesystemAuditCheckpointStore($this->root . '/store');
        $first = new AuditCheckpoint(1, str_repeat('a', 64), 10);
        $second = new AuditCheckpoint(2, str_repeat('b', 64), 12);

        self::assertNull($store->retained());
        self::assertTrue($store->retain($first));
        self::assertFalse($store->retain($first), 'A mark already reached is not published again.');
        self::assertTrue($store->retain($second));
        self::assertEquals($second, $store->retained());
        self::assertSame(0600, fileperms($this->root . '/store/' . sprintf('%020d-%020d.json', 2, 12)) & 0777);
        self::assertCount(2, glob($this->root . '/store/*.json') ?: []);
        self::assertFalse($store->retain(new AuditCheckpoint(1, str_repeat('a', 64), 11)));
        try {
            $store->retain(new AuditCheckpoint(3, str_repeat('c', 64), 11));
            self::fail('A checkpoint that lowers the retained head must be refused.');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('never lower', $refusal->getMessage());
        }
        self::assertEquals($second, $store->retained());
    }

    /**
     * Installations sharing an archive root keep separate checkpoint histories.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachInstallationHasItsOwnHistory(): void
    {
        $maria = FilesystemAuditCheckpointStore::forInstallation($this->root, 'mariadb', 'kumwe', 'kumwe_');
        $postgres = FilesystemAuditCheckpointStore::forInstallation($this->root, 'pgsql', 'kumwe', 'kumwe_');
        $maria->retain(new AuditCheckpoint(0, null, 5));

        self::assertNull($postgres->retained());
        self::assertEquals(new AuditCheckpoint(0, null, 5), $maria->retained());
        self::assertEquals(
            new AuditCheckpoint(0, null, 5),
            FilesystemAuditCheckpointStore::forInstallation($this->root, 'mariadb', 'kumwe', 'kumwe_')->retained(),
        );
    }

    /**
     * An unsafe or unreadable retained checkpoint is an error, never an absent one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsafeOrAlteredCheckpointsAreRefused(): void
    {
        mkdir($this->root . '/real', 0700, true);
        symlink($this->root . '/real', $this->root . '/link');
        $this->assertRefused(
            fn (): mixed => (new FilesystemAuditCheckpointStore($this->root . '/link'))->retained(),
            'unsafe',
        );
        file_put_contents(
            $this->root . '/real/' . sprintf('%020d-%020d.json', 1, 9),
            json_encode((new AuditCheckpoint(1, str_repeat('a', 64), 8))->toArray()),
        );
        $this->assertRefused(
            fn (): mixed => (new FilesystemAuditCheckpointStore($this->root . '/real'))->retained(),
            'does not match its name',
        );
        file_put_contents($this->root . '/real/' . sprintf('%020d-%020d.json', 2, 9), '{not json');
        $this->assertRefused(
            fn (): mixed => (new FilesystemAuditCheckpointStore($this->root . '/real'))->retained(),
            'not a valid checkpoint',
        );
        file_put_contents($this->root . '/real/ignored.json', '{}');
        file_put_contents($this->root . '/oversized.json', str_repeat(' ', 65_537));
        $this->assertRefused(
            fn (): mixed => FilesystemAuditCheckpointStore::read($this->root . '/oversized.json'),
            'cannot be read',
        );
        $this->assertRefused(
            static fn (): mixed => FilesystemAuditCheckpointStore::read('relative.json'),
            'absolute path',
        );
        $this->assertRefused(
            static fn (): mixed => new FilesystemAuditCheckpointStore('relative'),
            'absolute',
        );
    }

    /**
     * A directory that cannot be created or written fails the retention loudly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnwritableStoreFailsLoudly(): void
    {
        mkdir($this->root, 0700, true);
        file_put_contents($this->root . '/file', 'not a directory');
        $this->assertRefused(
            fn (): mixed => (new FilesystemAuditCheckpointStore($this->root . '/file/store'))
                ->retain(new AuditCheckpoint(0, null, 1)),
            'cannot be created',
        );
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return;
        }
        mkdir($this->root . '/locked', 0500);
        $this->assertRefused(
            fn (): mixed => (new FilesystemAuditCheckpointStore($this->root . '/locked'))
                ->retain(new AuditCheckpoint(0, null, 1)),
            'cannot be written',
        );
        chmod($this->root . '/locked', 0700);
    }

    /**
     * Assert an operation fails with a runtime refusal naming the expected reason.
     *
     * @param   callable(): mixed  $operation  Operation expected to fail.
     * @param   string             $reason     Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefused(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail('The operation must be refused: ' . $reason);
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString($reason, $refusal->getMessage());
        }
    }

    /**
     * Remove a scratch tree.
     *
     * @param   string  $path  Path to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
