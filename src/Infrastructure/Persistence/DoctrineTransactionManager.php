<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(
            static fn (Connection $connection): mixed => $operation(),
        );
    }
}
