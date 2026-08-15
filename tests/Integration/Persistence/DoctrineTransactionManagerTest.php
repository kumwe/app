<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Kumwe\CMS\Application\Persistence\TransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    public function testNestedCallbacksWaitForTheOutermostCommit(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $events = [];

        $transactions->transactional(function () use ($transactions, &$events): void {
            $transactions->afterCommit(static function () use (&$events): void {
                $events[] = 'outer-commit';
            });
            $transactions->transactional(function () use ($transactions, &$events): void {
                $transactions->afterCommit(static function () use (&$events): void {
                    $events[] = 'inner-commit';
                });
            });
            self::assertSame([], $events);
        });

        self::assertSame(['outer-commit', 'inner-commit'], $events);
    }

    public function testNestedRollbackCallbacksRunAndCommitCallbacksAreDiscarded(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $events = [];

        try {
            $transactions->transactional(function () use ($transactions, &$events): void {
                $transactions->afterCommit(static function () use (&$events): void {
                    $events[] = 'unexpected-commit';
                });
                $transactions->afterRollback(static function () use (&$events): void {
                    $events[] = 'outer-rollback';
                });
                $transactions->transactional(function () use ($transactions, &$events): void {
                    $transactions->afterRollback(static function () use (&$events): void {
                        $events[] = 'inner-rollback';
                    });
                });
                throw new RuntimeException('force outer rollback');
            });
            self::fail('The outer transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('force outer rollback', $exception->getMessage());
        }

        self::assertSame(['outer-rollback', 'inner-rollback'], $events);
    }
}
