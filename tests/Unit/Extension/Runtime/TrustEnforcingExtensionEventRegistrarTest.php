<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Runtime\ExtensionEvent;
use Kumwe\App\Extension\Runtime\ExtensionEventRegistrar;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Runtime\LaminasExtensionEvent;
use Kumwe\App\Extension\Runtime\TrustEnforcingExtensionEventRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(TrustEnforcingExtensionEventRegistrar::class)]
/**
 * Verifies resident synchronous listeners cannot outlive their signed extension generation.
 *
 * @since  2.0.0
 */
final class TrustEnforcingExtensionEventRegistrarTest extends TestCase
{
    /**
     * A listener from a superseded package version becomes inert before trust or handler code runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationCannotEnterResidentListener(): void
    {
        $attached = null;
        $inner = $this->createMock(ExtensionEventRegistrar::class);
        $inner->expects(self::once())->method('listen')->willReturnCallback(
            static function (string $event, callable $listener) use (&$attached): void {
                self::assertSame('onKumweRecordSaved', $event);
                $attached = $listener;
            },
        );
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())->method('isCurrent')->willReturn(false);
        $listener = $this->createMock(ExtensionEventListenerProbe::class);
        $listener->expects(self::never())->method('receive');
        $trust = (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor();
        $registrar = new TrustEnforcingExtensionEventRegistrar(
            $inner,
            $trust,
            'acme/editor',
            $execution,
        );
        $registrar->listen('onKumweRecordSaved', $listener->receive(...));

        self::assertIsCallable($attached);
        $attached(new LaminasExtensionEvent('onKumweRecordSaved', []));
    }
}

/**
 * Mockable listener boundary proving whether extension code was entered.
 *
 * @since  2.0.0
 */
interface ExtensionEventListenerProbe
{
    /**
     * Receive one host event.
     *
     * @param   ExtensionEvent  $event  Event whose payload is irrelevant to the stale-generation proof.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function receive(ExtensionEvent $event): void;
}
