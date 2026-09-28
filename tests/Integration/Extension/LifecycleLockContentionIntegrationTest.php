<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Kumwe\App\Delivery\Console\Command\WatchExtensionRuntimeCommand;
use Kumwe\App\Extension\Application\Install\ExtensionInstallReconciler;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineTrustStoreRepository;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the runtime watcher's steady-state pass never competes for the extension lifecycle lock.
 *
 * The watcher runs `extension:runtime:watch` every ten seconds on every replica. The lifecycle lock is taken
 * without waiting, so a watcher that took it on every pass would refuse, or be refused by, whatever lifecycle
 * work happened to coincide with its tick. Its install reconciliation takes the lock only while an install
 * operation is actually unresolved; this test holds the lock from a second database session and requires a
 * complete watcher pass over a registry with nothing pending to converge regardless.
 *
 * @since  2.0.0
 */
#[CoversClass(WatchExtensionRuntimeCommand::class)]
#[CoversClass(RedisLockedExtensionManager::class)]
final class LifecycleLockContentionIntegrationTest extends TestCase
{
    /**
     * Prove a watcher pass with no unresolved install converges while another session holds the lock.
     *
     * A lifecycle mutator is refused inside the same window, which proves the lock is genuinely held and
     * that mutators still serialize on it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheWatchersNoChangePassDoesNotContendForTheLifecycleLock(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $installs = $container->get(ExtensionInstallReconciler::class);
        $watcher = $container->get(WatchExtensionRuntimeCommand::class);
        $trust = $container->get(TrustStore::class);
        self::assertInstanceOf(RedisLockedExtensionManager::class, $installs);
        self::assertInstanceOf(WatchExtensionRuntimeCommand::class, $watcher);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertFalse($installs->hasPending(), 'The proof needs a registry with no unresolved install.');
        $output = new CapturingMachineConsoleOutput();

        [$mutatorRefused, $reconciled, $status] = self::holdingLifecycleLock(
            static function () use ($trust, $installs, $watcher, $output): array {
                $refused = false;
                try {
                    $trust->synchronizedLifecycle(static fn (): bool => true);
                } catch (RuntimeException) {
                    $refused = true;
                }

                return [$refused, $installs->reconcile(), $watcher->execute(['--once'], $output)];
            },
        );

        self::assertTrue($mutatorRefused, 'The second session must genuinely hold the lifecycle lock.');
        self::assertSame(0, $reconciled, 'Nothing was pending, so nothing may have been reconciled.');
        self::assertSame([], $output->errors, 'The watcher pass must not report a failure.');
        self::assertSame(0, $status, 'The watcher pass must converge while the lock is held elsewhere.');
    }

    /**
     * Run an operation while a second database session of this installation holds the lifecycle lock.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to run while the other session holds the lock.
     *
     * @return  T  Whatever the operation returned.
     *
     * @since   2.0.0
     */
    private static function holdingLifecycleLock(callable $operation): mixed
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals())->database;
        $holder = (new DoctrineConnectionFactory($configuration))->create();
        try {
            return (new DoctrineTrustStoreRepository(
                $holder,
                new TableNames($holder, $configuration->tablePrefix),
            ))->synchronizedLifecycle($operation);
        } finally {
            $holder->close();
        }
    }
}
