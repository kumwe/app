<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Infrastructure;

use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\ExtensionRuntimeWithdrawal;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(RedisLockedExtensionManager::class)]
/**
 * Pins post-lifecycle retirement policy independently of database and Redis mutation mechanics.
 *
 * @since  2.0.0
 */
final class RedisLockedExtensionManagerTest extends TestCase
{
    /**
     * Reconciliation retires a generation even when the synchronized operation fails after finding work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReconcileRunsThePostMutationWithdrawalBoundary(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchOne')->willReturn(1);
        $database->method('quoteSingleIdentifier')->willReturnArgument(0);
        $extensions = $this->withoutConstructor(DoctrineExtensionManager::class);
        $this->initialize($extensions, 'database', $database);
        $this->initialize($extensions, 'tables', new TableNames($database, 'kumwe_'));

        $repository = $this->createMock(TrustStoreRepository::class);
        $repository->expects(self::once())
            ->method('synchronizedLifecycle')
            ->willThrowException(new RuntimeException('Stop after the pending-operation check.'));
        $trust = $this->withoutConstructor(TrustStore::class);
        $this->initialize($trust, 'repository', $repository);
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())->method('isCurrent')->willReturn(false);
        $withdrawal = $this->createMock(ExtensionRuntimeWithdrawal::class);
        $withdrawal->expects(self::once())->method('withdrawAll');
        $manager = new RedisLockedExtensionManager(
            $extensions,
            $this->withoutConstructor(RedisRuntime::class),
            $this->createStub(AuthorizationGateway::class),
            $this->withoutConstructor(ExtensionRegistryFenceAllocator::class),
            $trust,
            $withdrawal,
            $execution,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stop after the pending-operation check.');
        $manager->reconcile();
    }

    /**
     * Preserve resident contributions after a failed or no-op operation that did not advance authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentGenerationIsNotWithdrawnAfterLifecycleAttempt(): void
    {
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())->method('isCurrent')->willReturn(true);
        $withdrawal = $this->createMock(ExtensionRuntimeWithdrawal::class);
        $withdrawal->expects(self::never())->method('withdrawAll');

        $this->invokeWithdrawalCheck($execution, $withdrawal);
    }

    /**
     * Withdraw every resident contribution after a committed lifecycle operation advances authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationIsWithdrawnAfterLifecycleAttempt(): void
    {
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())->method('isCurrent')->willReturn(false);
        $withdrawal = $this->createMock(ExtensionRuntimeWithdrawal::class);
        $withdrawal->expects(self::once())->method('withdrawAll');

        $this->invokeWithdrawalCheck($execution, $withdrawal);
    }

    /**
     * Invoke the manager's common finally-path policy over inert infrastructure collaborators.
     *
     * @param   ExtensionExecutionGate      $execution   Generation result under test.
     * @param   ExtensionRuntimeWithdrawal  $withdrawal  Observable retirement boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function invokeWithdrawalCheck(
        ExtensionExecutionGate $execution,
        ExtensionRuntimeWithdrawal $withdrawal,
    ): void {
        $manager = new RedisLockedExtensionManager(
            $this->withoutConstructor(DoctrineExtensionManager::class),
            $this->withoutConstructor(RedisRuntime::class),
            $this->createStub(AuthorizationGateway::class),
            $this->withoutConstructor(ExtensionRegistryFenceAllocator::class),
            $this->withoutConstructor(TrustStore::class),
            $withdrawal,
            $execution,
        );
        (new ReflectionMethod($manager, 'withdrawStaleRuntime'))->invoke($manager);
    }

    /**
     * Allocate a final infrastructure collaborator without running its unrelated constructor.
     *
     * @template T of object
     *
     * @param   class-string<T>  $class  Final collaborator type.
     *
     * @return  T  Inert instance used only to satisfy the manager constructor.
     *
     * @since   2.0.0
     */
    private function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * Initialize one collaborator required by a narrow call on a constructor-free readonly fixture.
     *
     * @param   object  $target    Fixture whose property has not yet been initialized.
     * @param   string  $property  Exact private property name.
     * @param   mixed   $value     Typed collaborator assigned once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function initialize(object $target, string $property, mixed $value): void
    {
        (new ReflectionProperty($target, $property))->setValue($target, $value);
    }
}
