<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallReconciler;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Presentation\ThemeSurface;
use RuntimeException;
use Throwable;

final readonly class RedisLockedExtensionManager implements ExtensionManager, ExtensionInstallReconciler
{
    public function __construct(
        private DoctrineExtensionManager $extensions,
        private RedisRuntime $redis,
        private AuthorizationGateway $authorization,
        private ExtensionRegistryFenceAllocator $fences,
        private TrustStore $trust,
    ) {
    }

    public function installed(ExecutionContext $context): array
    {
        return $this->extensions->installed($context);
    }

    public function reconcile(): int
    {
        if (!$this->extensions->hasPendingInstallOperations()) {
            return 0;
        }

        return $this->trust->synchronizedLifecycle(fn (): int =>
            $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): int =>
                $this->extensions->reconcileInstallOperations($lease)));
    }

    public function hasPending(): bool
    {
        return $this->extensions->hasPendingInstallOperations();
    }

    public function install(
        string $archiveFile,
        ExecutionContext $context,
        ?string $signingKeyId = null,
        ?string $base64Signature = null,
    ): array {
        $this->authorize($context, AuthorizationResource::collection('extension'));
        return $this->trust->synchronizedLifecycle(fn (): array =>
            $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->install(
                $archiveFile,
                $context,
                $lease,
                $signingKeyId,
                $base64Signature,
            )));
    }

    public function activate(
        string $identifier,
        ExecutionContext $context,
        ?ThemeSurface $surface = null,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        return $this->trust->synchronizedLifecycle(fn (): array =>
            $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->activate(
                $identifier,
                $context,
                $lease,
                $surface,
                $stepUpCredential,
            )));
    }

    public function disable(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): array {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        return $this->trust->synchronizedLifecycle(fn (): array =>
            $this->locked(fn (DatabaseFencedExtensionRegistryLease $lease): array => $this->extensions->disable(
                $identifier,
                $context,
                $lease,
                $stepUpCredential,
            )));
    }

    public function uninstall(
        string $identifier,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential = null,
    ): void
    {
        $this->authorize($context, AuthorizationResource::item('extension', $identifier));
        $this->trust->synchronizedLifecycle(fn (): array => $this->locked(
            function (DatabaseFencedExtensionRegistryLease $lease) use (
                $identifier,
                $context,
                $stepUpCredential,
            ): array {
                $this->extensions->uninstall($identifier, $context, $lease, $stepUpCredential);

                return [];
            },
        ));
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
     * @template T
     * @param callable(DatabaseFencedExtensionRegistryLease): T $operation
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $mutex = $this->redis->acquireLease('extension-registry', 120);
        if ($mutex === null) {
            throw new RuntimeException('Another extension registry operation is already in progress.');
        }
        $lease = new DatabaseFencedExtensionRegistryLease($mutex, $this->fences->allocate());

        try {
            $result = $operation($lease);
        } catch (Throwable $exception) {
            $mutex->release();
            throw $exception;
        }
        // Release is ownership-checked. A lost lease cannot delete a newer holder and does not
        // reinterpret an already committed database mutation as a failed request.
        $mutex->release();

        return $result;
    }
}
