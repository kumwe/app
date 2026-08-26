<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Runtime\LaminasExtensionEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaminasExtensionEvent::class)]
/**
 * Verifies the Laminas-carried domain event honours the versioned extension event surface.
 *
 * @since  2.0.0
 */
final class LaminasExtensionEventTest extends TestCase
{
    /**
     * The event reports the domain name it was constructed with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExposesTheDomainEventNameItWasConstructedWith(): void
    {
        $event = new LaminasExtensionEvent('onKumweRecordSaved', []);

        self::assertSame('onKumweRecordSaved', $event->getName());
    }

    /**
     * A named argument is served from the payload and an absent one falls back to the default.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testServesNamedArgumentsAndFallsBackToTheDefault(): void
    {
        $event = new LaminasExtensionEvent('onKumweRecordSaved', ['record' => 'invoice-7']);

        self::assertSame('invoice-7', $event->getArgument('record'));
        self::assertSame('fallback', $event->getArgument('absent', 'fallback'));
        self::assertNull($event->getArgument('absent'));
    }

    /**
     * A freshly dispatched event has not had its propagation stopped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPropagationIsNotStoppedInitially(): void
    {
        $event = new LaminasExtensionEvent('onKumweRecordSaved', []);

        self::assertFalse($event->isStopped());
    }

    /**
     * Stopping propagation raises the flag the dispatch loop consults between listeners.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStopPropagationMarksTheEventStopped(): void
    {
        $event = new LaminasExtensionEvent('onKumweRecordSaved', []);
        $event->stopPropagation();

        self::assertTrue($event->isStopped());
    }
}
