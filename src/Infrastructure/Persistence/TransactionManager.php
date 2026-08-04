<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
