<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Support;

use Kumwe\App\Tests\Support\ProcessOwnedShutdown;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves a forked child cannot inherit ownership of integration fixture cleanup.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ProcessOwnedShutdownTest extends TestCase
{
    /**
     * Only the registering PID may execute the guarded callback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInheritedChildCallbackIsANoOpWhileTheOwnerRuns(): void
    {
        $executions = 0;
        $shutdown = new ProcessOwnedShutdown(1201, static function () use (&$executions): void {
            $executions++;
        });

        $shutdown->runFor(1202);
        self::assertSame(0, $executions);

        $shutdown->runFor(1201);
        self::assertSame(1, $executions);
    }

    /**
     * Runtime capture records the process that performs registration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCapturedCallbackRunsInTheRegisteringProcess(): void
    {
        $executed = false;
        $shutdown = ProcessOwnedShutdown::capture(static function () use (&$executed): void {
            $executed = true;
        });

        $shutdown();

        self::assertTrue($executed);
    }
}
