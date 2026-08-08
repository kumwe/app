<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

interface BusinessSchemaExecutionLock
{
    /**
     * @template T
     * @param callable(int): T $operation Receives a monotonic durable fence.
     * @return T
     */
    public function synchronized(string $definitionId, callable $operation): mixed;
}
