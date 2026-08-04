<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;

final class JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @param iterable<JobHandler> $handlers */
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

    public function find(string $type): ?JobHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = array_keys($this->handlers);
        sort($types, SORT_STRING);

        return $types;
    }
}
