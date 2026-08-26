<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\App\Extension\Runtime\ExtensionEvent;
use Kumwe\App\Extension\Runtime\LaminasExtensionEvent;
use Kumwe\App\Extension\Runtime\LaminasExtensionEventRegistrar;
use Laminas\EventManager\EventManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaminasExtensionEventRegistrar::class)]
/**
 * Verifies extensions can subscribe only to named Kumwe domain events on the host event manager.
 *
 * @since  2.0.0
 */
final class LaminasExtensionEventRegistrarTest extends TestCase
{
    /**
     * Event names the registrar must refuse before anything reaches the event manager.
     *
     * @return  iterable<string, array{string}>  Data sets of one refused event name each.
     *
     * @since   2.0.0
     */
    public static function namesOutsideTheDomainNamespace(): iterable
    {
        yield 'wildcard channel' => ['*'];
        yield 'framework hook' => ['dispatch.error'];
        yield 'missing prefix' => ['RecordSaved'];
        yield 'lower-case after prefix' => ['onKumweevent'];
        yield 'too short after prefix' => ['onKumweAb'];
        yield 'empty name' => [''];
    }

    /**
     * Subscription is refused when the event name falls outside the `onKumwe*` domain namespace.
     *
     * @param   string  $event  Event name outside the domain namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('namesOutsideTheDomainNamespace')]
    public function testRejectsEventNamesOutsideTheKumweDomainNamespace(string $event): void
    {
        $registrar = new LaminasExtensionEventRegistrar(new EventManager());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('named Kumwe domain events');

        $registrar->listen($event, static function (ExtensionEvent $event): void {
        });
    }

    /**
     * A listener attached through the registrar receives the domain event the manager dispatches.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAttachedListenerReceivesTheDispatchedDomainEvent(): void
    {
        $events = new EventManager();
        $registrar = new LaminasExtensionEventRegistrar($events);
        $received = null;
        $registrar->listen('onKumweRecordSaved', static function (ExtensionEvent $event) use (&$received): void {
            $received = $event;
        });

        $dispatched = new LaminasExtensionEvent('onKumweRecordSaved', ['record' => 'invoice-7']);
        $events->triggerEvent($dispatched);

        self::assertSame($dispatched, $received);
        self::assertSame('invoice-7', $received->getArgument('record'));
    }

    /**
     * A listener that stops propagation keeps the dispatch loop from reaching the listeners after it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoppedPropagationPreventsTheListenersThatFollow(): void
    {
        $events = new EventManager();
        $registrar = new LaminasExtensionEventRegistrar($events);
        $sequence = [];
        $registrar->listen('onKumweRecordSaved', static function (ExtensionEvent $event) use (&$sequence): void {
            $sequence[] = 'first';
            $event->stopPropagation();
        });
        $registrar->listen('onKumweRecordSaved', static function (ExtensionEvent $event) use (&$sequence): void {
            $sequence[] = 'second';
        });

        $events->triggerEvent(new LaminasExtensionEvent('onKumweRecordSaved', []));

        self::assertSame(['first'], $sequence);
    }
}
