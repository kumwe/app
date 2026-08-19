<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use InvalidArgumentException;

/**
 * Type-indexed lookup of every job handler wired into the container.
 *
 * The worker resolves a claimed job's handler here, and the automation management service reads the
 * registered types to decide what an operator may schedule. Indexing happens once at construction, so
 * two handlers claiming the same type are rejected while the container is being built rather than at
 * the moment such a job is first executed.
 *
 * @since  2.0.0
 */
final class JobHandlerRegistry
{
    /**
     * Registered handlers, keyed by the job type each one claims.
     *
     * @var    array<string, JobHandler>
     * @since  2.0.0
     */
    private array $handlers = [];

    /**
     * Index the wired handlers by the type each claims.
     *
     * @param   iterable<JobHandler>  $handlers  Handlers to register, in container wiring order.
     *
     * @throws  InvalidArgumentException  When two handlers claim the same job type.
     *
     * @since   2.0.0
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $type = $handler->type();

            if (isset($this->handlers[$type])) {
                throw new InvalidArgumentException(sprintf('Job handler %s is registered more than once.', $type));
            }

            $this->handlers[$type] = $handler;
        }
    }

    /**
     * Look up the handler registered for a job type.
     *
     * @param   string  $type  Job type read from the claimed queue row.
     *
     * @return  ?JobHandler  Null when nothing claims the type, which the worker fails permanently.
     *
     * @since   2.0.0
     */
    public function find(string $type): ?JobHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * List every registered job type in a stable order.
     *
     * @return  list<string>  Type names sorted as strings, so callers can present them predictably.
     *
     * @since   2.0.0
     */
    public function types(): array
    {
        $types = array_keys($this->handlers);
        sort($types, SORT_STRING);

        return $types;
    }
}
