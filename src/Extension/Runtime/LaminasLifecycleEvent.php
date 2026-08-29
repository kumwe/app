<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Laminas\EventManager\Event;

/**
 * Host lifecycle event carried by the Laminas event manager.
 *
 * The class extends the Laminas event so the event manager can dispatch it natively — the propagation
 * flag a host listener raises through `stopPropagation()` is the same flag the dispatch loop consults
 * between listeners. It is deliberately not exposed through the extension SDK: signed manifests and
 * executable binding IDs are the only extension declaration path.
 *
 * @extends  Event<null, array<string, mixed>>
 *
 * @since    2.0.0
 */
class LaminasLifecycleEvent extends Event
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
     * this override gives host listeners a fixed void return. Anything falsy clears the flag.
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
