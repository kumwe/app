<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\Extension\Spi\Runtime\ExtensionEvent;
use Laminas\EventManager\Event;

/**
 * Kumwe domain event carried by the Laminas event manager.
 *
 * The class extends the Laminas event so the event manager can dispatch it natively — the propagation
 * flag a listener raises through `stopPropagation()` is the same flag the dispatch loop consults between
 * listeners. Extension listeners receive it as `ExtensionEvent`, whose vocabulary (`getName`,
 * `getArgument`, `isStopped`, `stopPropagation`) is the versioned extension surface; the Laminas
 * parameter bag underneath is an implementation detail no listener should reach for.
 *
 * @extends  Event<null, array<string, mixed>>
 *
 * @since    2.0.0
 */
class LaminasExtensionEvent extends Event implements ExtensionEvent
{
    /**
     * Domain event name fixed at construction.
     *
     * The name is held here as well as in the Laminas parent so `getName()` can promise a string: the
     * parent allows a nameless event, while a Kumwe domain event never dispatches without one.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $eventName;

    /**
     * Create a named domain event carrying its argument map.
     *
     * @param  string                $name       Domain event name, such as `onKumweExtensionAfterInstall`.
     * @param  array<string, mixed>  $arguments  Named arguments published with the event.
     *
     * @since  2.0.0
     */
    public function __construct(string $name, array $arguments)
    {
        parent::__construct($name, null, $arguments);

        $this->eventName = $name;
    }

    /**
     * Get the domain event name.
     *
     * @return  string  The event name.
     *
     * @since   2.0.0
     */
    public function getName(): string
    {
        return $this->eventName;
    }

    /**
     * Get a named argument from the event's payload.
     *
     * @param   string  $name     Name of the argument to read.
     * @param   mixed   $default  Value returned when the payload carries no such argument.
     *
     * @return  mixed  The argument value or the default.
     *
     * @since   2.0.0
     */
    public function getArgument(string $name, mixed $default = null): mixed
    {
        return $this->getParam($name, $default);
    }

    /**
     * Tell whether a listener has stopped the event's propagation.
     *
     * @return  bool  True when propagation has been stopped.
     *
     * @since   2.0.0
     */
    public function isStopped(): bool
    {
        return $this->propagationIsStopped();
    }

    /**
     * Stop the event's propagation to the listeners that would follow.
     *
     * The optional flag is the Laminas signature, which the parent declares without a return type, so
     * this override is what lets one method satisfy the Laminas event and the argument-free Kumwe
     * contract at once. Kumwe listeners call it without arguments; anything falsy would instead clear
     * the flag, which no Kumwe caller does.
     *
     * @param   mixed  $flag  Laminas propagation flag; truthy stops the event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function stopPropagation($flag = true): void
    {
        parent::stopPropagation((bool) $flag);
    }
}
