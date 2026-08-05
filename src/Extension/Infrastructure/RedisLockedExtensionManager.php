<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use RuntimeException;
use Throwable;

final readonly class RedisLockedExtensionManager implements ExtensionManager
{
    public function __construct(
        private DoctrineExtensionManager $extensions,
        private RedisRuntime $redis,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function installed(ExecutionContext $context): array
    {
        return $this->extensions->installed($context);
    }

    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->authorize($context, AuthorizationResource::collection('extension'));
        return $this->locked(fn (): array => $this->extensions->install(
            $archiveFile,
            $context,
            $signingKeyId,
            $base64Signature,
        ));
    }

    public function activate(string $identifier, ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        return $this->locked(fn (): array => $this->extensions->activate($identifier, $context));
    }

    public function disable(string $identifier, ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        return $this->locked(fn (): array => $this->extensions->disable($identifier, $context));
    }

    public function uninstall(string $identifier, ExecutionContext $context): void
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $this->locked(function () use ($identifier, $context): array {
            $this->extensions->uninstall($identifier, $context);

            return [];
        });
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('extensions.manage'),
            $resource,
        );
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
