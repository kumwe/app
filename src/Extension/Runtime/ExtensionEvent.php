<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

/**
 * Event surface an extension listener receives when a Kumwe domain event is dispatched.
 *
 * Event names and their argument maps are versioned extension API, and this contract is the whole of
 * what a listener may rely on: the event's name, its named arguments, and the propagation flag. The
 * contract deliberately names no vendor type, so the dispatch engine behind it can change without the
 * extension surface moving again. Arguments are read-only through this surface — a listener reacts to a
 * domain event, it does not rewrite it for the listeners that follow.
 *
 * @since  2.0.0
 */
interface ExtensionEvent
{
    /**
     * Get the domain event name, such as `onKumweExtensionAfterActivate`.
     *
     * @return  string  The event name.
     *
     * @since   2.0.0
     */
    public function getName(): string;

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
    public function getArgument(string $name, mixed $default = null): mixed;

    /**
     * Tell whether a listener has stopped the event's propagation.
     *
     * @return  bool  True when propagation has been stopped.
     *
     * @since   2.0.0
     */
    public function isStopped(): bool;

    /**
     * Stop the event's propagation to the listeners that would follow.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function stopPropagation(): void;
}
