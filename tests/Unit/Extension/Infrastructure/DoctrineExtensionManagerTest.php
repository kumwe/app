<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Infrastructure;

use Kumwe\CMS\Extension\Infrastructure\DoctrineExtensionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(DoctrineExtensionManager::class)]
final class DoctrineExtensionManagerTest extends TestCase
{
    public function testCallerMutationCannotChangePrivateArchiveSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-snapshot-' . bin2hex(random_bytes(8));
        mkdir($root . '/operation', 0700, true);
        $source = $root . '/caller.zip';
        $original = str_repeat('signed-package-bytes', 4096);
        file_put_contents($source, $original);
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $snapshot = $reflection->getMethod('snapshotArchive')->invoke($manager, $source, $root . '/operation');
        file_put_contents($source, 'attacker replacement after the snapshot boundary');

        self::assertIsString($snapshot);
        self::assertSame(hash('sha256', $original), hash_file('sha256', $snapshot));
        self::assertNotSame(hash_file('sha256', $source), hash_file('sha256', $snapshot));
    }
}
