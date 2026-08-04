<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

interface MigrationLock
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function synchronized(callable $operation): mixed;
}
