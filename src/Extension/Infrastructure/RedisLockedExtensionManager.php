<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use RuntimeException;
use Throwable;

final readonly class RedisLockedExtensionManager implements ExtensionManager
{
    public function __construct(private DoctrineExtensionManager $extensions, private RedisRuntime $redis)
    {
    }

    public function installed(): array
    {
        return $this->extensions->installed();
    }

    public function install(
        string $archiveFile,
        string $actorId,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        return $this->locked(fn (): array => $this->extensions->install(
            $archiveFile,
            $actorId,
            $signingKeyId,
            $base64Signature,
        ));
    }

    public function activate(string $identifier, string $actorId): array
    {
        return $this->locked(fn (): array => $this->extensions->activate($identifier, $actorId));
    }

    public function disable(string $identifier, string $actorId): array
    {
        return $this->locked(fn (): array => $this->extensions->disable($identifier, $actorId));
    }

    public function uninstall(string $identifier, string $actorId): void
    {
        $this->locked(function () use ($identifier, $actorId): array {
            $this->extensions->uninstall($identifier, $actorId);

            return [];
        });
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function locked(callable $operation): array
    {
        $token = bin2hex(random_bytes(32));
        if (!$this->redis->acquireLock('extension-registry', $token, 120)) {
            throw new RuntimeException('Another extension registry operation is already in progress.');
        }

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->redis->releaseLock('extension-registry', $token);
            throw $exception;
        }
        if (!$this->redis->releaseLock('extension-registry', $token)) {
            throw new RuntimeException('The extension registry lock expired before it could be released.');
        }

        return $result;
    }
}
