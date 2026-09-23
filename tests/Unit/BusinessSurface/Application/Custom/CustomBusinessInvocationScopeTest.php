<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application\Custom;

use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessInvocationScope;
use Kumwe\App\Tests\Support\CoordinateExecutionContext;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CustomBusinessInvocationScope::class)]
/**
 * Proves the invocation scope resolves only the host context of the custom invocation currently running.
 *
 * @since  2.0.0
 */
final class CustomBusinessInvocationScopeTest extends TestCase
{
    /**
     * Prove that outside any invocation no context resolves, whatever coordinates it names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNothingResolvesOutsideAnInvocation(): void
    {
        $scope = new CustomBusinessInvocationScope();
        $host = self::context('request-1');

        self::assertNull($scope->hostFor(new CoordinateExecutionContext($host)));
    }

    /**
     * Prove the running invocation resolves for a context naming its coordinates and stops once left.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRunningInvocationResolvesForAContextNamingItsCoordinates(): void
    {
        $scope = new CustomBusinessInvocationScope();
        $host = self::context('request-1');

        $scope->enter($host);
        $resolved = $scope->hostFor(new CoordinateExecutionContext($host));
        $scope->leave();

        self::assertSame($host, $resolved);
        self::assertNull($scope->hostFor(new CoordinateExecutionContext($host)));
    }

    /**
     * Prove a context naming another request's coordinates resolves to nothing while an invocation runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContextNamingOtherCoordinatesResolvesToNothing(): void
    {
        $scope = new CustomBusinessInvocationScope();
        $running = self::context('request-1');
        $other = self::context('request-2');

        $scope->enter($running);
        $resolved = $scope->hostFor(new CoordinateExecutionContext($other));
        $scope->leave();

        self::assertNull($resolved);
    }

    /**
     * Prove nested invocations resolve the innermost context first and unwind to the outer one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNestedInvocationsResolveInnermostFirstAndUnwind(): void
    {
        $scope = new CustomBusinessInvocationScope();
        $outer = self::context('request-outer');
        $inner = self::context('request-inner');

        $scope->enter($outer);
        $scope->enter($inner);
        $insideInner = [
            $scope->hostFor(new CoordinateExecutionContext($inner)),
            $scope->hostFor(new CoordinateExecutionContext($outer)),
        ];
        $scope->leave();
        $afterInner = $scope->hostFor(new CoordinateExecutionContext($outer));
        $scope->leave();

        self::assertSame([$inner, null], $insideInner);
        self::assertSame($outer, $afterInner);
        self::assertNull($scope->hostFor(new CoordinateExecutionContext($outer)));
    }

    /**
     * Prove leaving a scope no invocation entered is a programming error, not a silent no-op.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLeavingWithoutAnInvocationIsRefused(): void
    {
        $scope = new CustomBusinessInvocationScope();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No custom business invocation is executing.');

        $scope->leave();
    }

    /**
     * Issue a system context on the default site for one request identifier.
     *
     * @param   string  $requestId  Request identifier distinguishing the context.
     *
     * @return  ExecutionContext  Host-issued context.
     *
     * @since   2.0.0
     */
    private static function context(string $requestId): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            $requestId,
        );
    }
}
